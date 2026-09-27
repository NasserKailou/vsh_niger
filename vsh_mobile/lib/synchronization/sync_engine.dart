import 'dart:async';
import 'dart:convert';

import '../database/app_database.dart';
import '../database/local_store.dart';
import 'sync_api.dart';
import 'sync_queue.dart';

enum SyncPhase { idle, running, offline, failed, sessionExpired }

class SyncState {
  const SyncState({this.phase = SyncPhase.idle, this.lastSuccessAt, this.message});

  final SyncPhase phase;
  final DateTime? lastSuccessAt;

  /// Message destiné à l'utilisateur, sans détail technique ni donnée médicale.
  final String? message;

  SyncState copyWith({SyncPhase? phase, DateTime? lastSuccessAt, String? message}) =>
      SyncState(phase: phase ?? this.phase, lastSuccessAt: lastSuccessAt ?? this.lastSuccessAt, message: message);
}

class SyncReport {
  int sent = 0;
  int applied = 0;
  int conflicts = 0;
  int rejected = 0;
  int retried = 0;
  int received = 0;
}

/// Moteur de synchronisation : envoi de la file par lots, puis récupération incrémentale.
///
/// - Une seule exécution à la fois : un appel pendant une exécution reçoit la même.
/// - Chaque étape se reprend : une opération envoyée reste `SENDING` jusqu'à son résultat (renvoyée
///   après une coupure ou un redémarrage, sans doublon grâce à l'`op_id`), et le curseur n'avance
///   que dans la transaction qui applique le lot reçu.
class SyncEngine {
  SyncEngine({
    required this.db,
    required this.api,
    SyncQueue? queue,
    LocalStore? store,
    this.onDeviceRevoked,
    this.pullLimit = 200,
    this.pullEnabled,
  })
      : queue = queue ?? SyncQueue(db),
        store = store ?? LocalStore(db);

  final AppDatabase db;
  final SyncApi api;
  final SyncQueue queue;
  final LocalStore store;

  /// Appareil révoqué par la clinique : effacer les données locales et fermer la session.
  final Future<void> Function()? onDeviceRevoked;

  static const cursorKey = 'sync.cursor';
  static const lastSyncKey = 'sync.last_success_at';
  /// Changements par page de récupération (500 au plus côté serveur).
  final int pullLimit;

  /// Faux pour un compte patient : ses données sont lues par l'espace patient (`/me/patients/…`),
  /// sans parcourir le journal de synchronisation, trop coûteux pour des centaines de milliers de
  /// téléphones (docs/22). Seul l'envoi des opérations locales (notification lue) est fait.
  final bool Function()? pullEnabled;

  /// Lots au plus par exécution, pour rendre la main (la suite part à l'exécution suivante).
  static const maxBatchesPerRun = 20;

  final _states = StreamController<SyncState>.broadcast();
  SyncState _state = const SyncState();
  Future<SyncReport>? _running;
  bool _started = false;

  SyncState get state => _state;
  Stream<SyncState> get states => _states.stream;

  void _emit(SyncState state) {
    _state = state;
    if (!_states.isClosed) _states.add(state);
  }

  /// Reprise au lancement : opérations interrompues remises en file, date de dernière synchronisation.
  Future<void> start() async {
    if (_started) return;
    _started = true;
    await queue.recoverInterrupted();
    final last = await db.readMeta(lastSyncKey);
    _emit(_state.copyWith(lastSuccessAt: last == null ? null : DateTime.tryParse(last)));
  }

  Future<SyncReport> run() => _running ??= _run().whenComplete(() => _running = null);

  Future<SyncReport> _run() async {
    await start();
    final report = SyncReport();
    _emit(_state.copyWith(phase: SyncPhase.running));
    try {
      await _push(report);
      if (pullEnabled?.call() ?? true) await _pull(report);
      final now = DateTime.now().toUtc();
      await db.writeMeta(lastSyncKey, now.toIso8601String());
      _emit(SyncState(phase: SyncPhase.idle, lastSuccessAt: now));
    } on SyncNetworkException catch (e) {
      await queue.recoverInterrupted();
      _emit(_state.copyWith(phase: SyncPhase.offline, message: e.message));
    } on SyncAuthException catch (e) {
      if (e.deviceRevoked && onDeviceRevoked != null) {
        await onDeviceRevoked!();
      } else {
        await queue.recoverInterrupted();
      }
      _emit(_state.copyWith(phase: SyncPhase.sessionExpired, message: e.message));
    } catch (e) {
      // Erreur serveur ou imprévue : la file est intacte, nouvel essai plus tard.
      await queue.recoverInterrupted();
      _emit(_state.copyWith(
          phase: SyncPhase.failed,
          message: e is SyncServerException ? e.message : 'La synchronisation a échoué. Nouvel essai automatique.'));
    }
    return report;
  }

  Future<void> _push(SyncReport report) async {
    for (final op in await queue.blockedByRejected()) {
      await queue.markDone(op, OpStatus.rejected, error: "Dépend d'une modification refusée ou abandonnée.");
      report.rejected++;
    }
    for (var i = 0; i < maxBatchesPerRun; i++) {
      final batch = await queue.nextBatch();
      if (batch.isEmpty) return;
      await queue.markSending(batch.map((op) => op.localId));
      final results = await api.push(batch.map(_toWire).toList());
      report.sent += batch.length;
      await db.transaction(() async {
        for (var j = 0; j < batch.length; j++) {
          await _applyResult(batch[j], j < results.length ? results[j] : null, report);
        }
      });
    }
  }

  Map<String, dynamic> _toWire(SyncOperation op) => {
        'op_id': op.opId,
        'entity': op.entity,
        'entity_id': op.entityId,
        'operation': op.operation,
        if (op.action != null) 'action': op.action,
        'payload': jsonDecode(op.payload),
        if (op.base != null && op.operation == 'UPDATE') 'base': jsonDecode(op.base!),
        if (op.baseVersion != null) 'base_version': op.baseVersion,
        if (op.dependsOn != null) 'depends_on': op.dependsOn,
        'client_created_at': op.createdAt.toUtc().toIso8601String(),
      };

  Future<void> _applyResult(SyncOperation op, Map<String, dynamic>? result, SyncReport report) async {
    if (result == null || result['op_id'] != op.opId) {
      report.retried++;
      return queue.markRetry(op, OpStatus.error, 'Réponse du serveur incomplète.');
    }
    final status = result['status'] as String?;
    final message = (result['error'] as Map?)?['message'] as String?;
    final data = result['data'];
    switch (status) {
      case OpStatus.applied:
      case OpStatus.conflict:
        final state = op.operation == 'DELETE' ? null : (data is Map && data['id'] == op.entityId ? data.cast<String, dynamic>() : null);
        if (op.operation == 'DELETE' || state != null) {
          if (await queue.hasOutgoing(op.entity, op.entityId, exceptLocalId: op.localId)) {
            // D'autres modifications locales suivent : l'affichage les garde, l'état serveur attend.
            await store.saveShadow(op.entity, op.entityId, state);
          } else {
            state == null ? await store.remove(op.entity, op.entityId) : await store.upsert(op.entity, op.entityId, state);
            await store.dropShadow(op.entity, op.entityId);
          }
        }
        if (status == OpStatus.conflict) {
          report.conflicts++;
          return queue.markDone(op, OpStatus.conflict,
              result: {'conflict': result['conflict']},
              error: 'Modifié entre-temps sur le serveur : la valeur du serveur a été conservée.');
        }
        report.applied++;
        return queue.markDone(op, OpStatus.applied);
      case OpStatus.rejected:
        report.rejected++;
        final error = (result['error'] as Map?)?.cast<String, dynamic>();
        return queue.markDone(op, OpStatus.rejected,
            result: {'code': error?['code'], 'errors': error?['errors']}, error: message ?? 'Refusé par le serveur.');
      case OpStatus.deferred:
        report.retried++;
        return queue.markRetry(op, OpStatus.deferred, message ?? 'En attente d’une autre modification.');
      default:
        report.retried++;
        return queue.markRetry(op, OpStatus.error, message ?? 'Erreur temporaire du serveur.');
    }
  }

  Future<void> _pull(SyncReport report) async {
    var cursor = int.tryParse(await db.readMeta(cursorKey) ?? '') ?? 0;
    while (true) {
      final sentAt = DateTime.now().toUtc();
      final page = await api.pull(cursor, limit: pullLimit);
      final serverTime = DateTime.tryParse(page.serverTime ?? '');
      if (serverTime != null) {
        // Milieu de l'aller-retour : précision de l'ordre de la seconde, suffisante ici.
        final receivedAt = DateTime.now().toUtc();
        final local = sentAt.add(receivedAt.difference(sentAt) ~/ 2);
        await db.writeMeta(clockOffsetKey, '${serverTime.difference(local).inMilliseconds}');
      }
      await db.transaction(() async {
        for (final change in page.changes) {
          final entity = change['entity'] as String;
          final id = change['id'] as String;
          // Rebase : une modification locale en attente l'emporte ; le résultat de son envoi
          // apportera l'état du serveur à jour.
          final data = change['data'];
          if (await queue.hasOutgoing(entity, id) || await queue.hasRejected(entity, id)) {
            // Mis de côté : appliqué si la modification locale (en attente, ou refusée et pas encore
            // traitée par l'utilisateur) est abandonnée.
            await store.saveShadow(entity, id, change['operation'] == 'DELETE' || data is! Map ? null : data.cast<String, dynamic>());
            continue;
          }
          if (change['operation'] == 'DELETE' || data is! Map) {
            await store.remove(entity, id);
          } else {
            await store.upsert(entity, id, data.cast<String, dynamic>());
          }
          report.received++;
        }
        // Même transaction : après une coupure, reprise exacte au dernier lot appliqué.
        await db.writeMeta(cursorKey, '${page.nextCursor}');
      });
      if (!page.hasMore || page.nextCursor <= cursor) return;
      cursor = page.nextCursor;
    }
  }

  /// Abandon d'une modification refusée, ou prise de connaissance d'un conflit.
  ///
  /// Une action refusée (visite reprise par une autre équipe, annulée…) entraîne l'abandon des
  /// actions suivantes du même élément : elles en découlaient.
  Future<void> dismiss(SyncOperation op) => db.transaction(() async {
        final group = op.operation == 'ACTION' ? await queue.attentionFor(op.entity, op.entityId) : [op];
        if (group.isEmpty) group.add(op);
        for (final item in group) {
          await queue.acknowledge(item);
        }
        if (op.status != OpStatus.rejected || await queue.hasOutgoing(op.entity, op.entityId)) return;
        // Dernier état connu du serveur s'il a été mis de côté, sinon l'état d'avant la saisie.
        if (!await store.applyShadow(op.entity, op.entityId)) {
          await store.revert(group.first);
        }
      });

  static const clockOffsetKey = 'sync.clock_offset_ms';

  /// Heure du serveur estimée (heure du téléphone corrigée de l'écart mesuré à la synchronisation) :
  /// une horloge de téléphone en avance ferait refuser les actions horodatées hors ligne.
  static Future<DateTime> serverNow(AppDatabase db, [DateTime Function() clock = DateTime.now]) async {
    final offset = int.tryParse(await db.readMeta(clockOffsetKey) ?? '') ?? 0;
    return clock().toUtc().add(Duration(milliseconds: offset));
  }

  Future<void> dispose() => _states.close();
}
