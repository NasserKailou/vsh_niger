import 'dart:convert';
import 'dart:math';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../database/app_database.dart';

/// États d'une opération de la file (colonne `status`).
abstract final class OpStatus {
  static const pending = 'PENDING';
  static const sending = 'SENDING';
  static const deferred = 'DEFERRED';
  static const error = 'ERROR';
  static const applied = 'APPLIED';
  static const conflict = 'CONFLICT';
  static const rejected = 'REJECTED';

  /// Traitée par l'utilisateur depuis le centre de synchronisation.
  static const discarded = 'DISCARDED';

  /// À envoyer (maintenant ou après l'attente progressive).
  static const outgoing = [pending, sending, deferred, error];

  /// À montrer à l'utilisateur jusqu'à ce qu'il les traite.
  static const attention = [rejected, conflict];
}

/// File des opérations hors ligne. Toute écriture locale passe par [enqueue], appelée **dans la même
/// transaction** que la modification de la donnée (règle 1 du contrat de synchronisation).
class SyncQueue {
  SyncQueue(this.db, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final AppDatabase db;
  final DateTime Function() _clock;
  static const _uuid = Uuid();

  static const batchSize = 50;

  /// Attente progressive : 15 s, 30 s, 1 min… plafonnée à 1 h.
  static Duration backoff(int retryCount) => Duration(seconds: min(15 * pow(2, min(retryCount, 12)).toInt(), 3600));

  static String newId() => _uuid.v4();

  /// Ajoute une opération. `depends_on` est calculé ici : si l'élément visé (ou son parent,
  /// [parentEntity]/[parentId]) a une création encore dans la file, l'opération en dépend.
  Future<SyncOperation> enqueue({
    required String entity,
    required String entityId,
    required String operation,
    required Map<String, dynamic> payload,
    String? action,
    Map<String, dynamic>? base,
    int? baseVersion,
    String? parentEntity,
    String? parentId,
    String? dependsOnOp,
    bool chain = false,
  }) async {
    final dependsOn = dependsOnOp ??
        (chain ? await lastOutgoing(entity, entityId) : null) ??
        await _pendingCreate(entity, entityId) ??
        (parentEntity != null && parentId != null ? await _pendingCreate(parentEntity, parentId) : null);
    final now = _clock().toUtc();
    final localId = await db.into(db.syncOperations).insert(SyncOperationsCompanion.insert(
          opId: newId(),
          entity: entity,
          entityId: entityId,
          operation: operation,
          action: Value(action),
          payload: jsonEncode(payload),
          base: Value(base == null ? null : jsonEncode(base)),
          baseVersion: Value(baseVersion),
          dependsOn: Value(dependsOn),
          status: OpStatus.pending,
          createdAt: now,
          updatedAt: now,
        ));
    return (db.select(db.syncOperations)..where((t) => t.localId.equals(localId))).getSingle();
  }

  /// Dernière opération encore à envoyer sur l'élément : les actions successives d'une visite
  /// s'enchaînent (une action n'est appliquée que si la précédente l'a été). Les points de trajet
  /// (`track`) ne font pas partie de la chaîne : un point refusé ne bloque aucune action.
  Future<String?> lastOutgoing(String entity, String entityId) async {
    final row = await (db.select(db.syncOperations)
          ..where((t) =>
              t.entity.equals(entity) &
              t.entityId.equals(entityId) &
              t.status.isIn(OpStatus.outgoing) &
              (t.action.isNull() | t.action.equals('track').not()))
          ..orderBy([(t) => OrderingTerm.desc(t.localId)])
          ..limit(1))
        .getSingleOrNull();
    return row?.opId;
  }

  /// Modification refusée pas encore traitée par l'utilisateur sur cet élément.
  Future<bool> hasRejected(String entity, String entityId) async {
    final row = await (db.select(db.syncOperations)
          ..where((t) => t.entity.equals(entity) & t.entityId.equals(entityId) & t.status.equals(OpStatus.rejected))
          ..limit(1))
        .getSingleOrNull();
    return row != null;
  }

  /// Opérations à traiter (refusées, en conflit) sur un élément.
  Future<List<SyncOperation>> attentionFor(String entity, String entityId) => (db.select(db.syncOperations)
        ..where((t) => t.entity.equals(entity) & t.entityId.equals(entityId) & t.status.isIn(OpStatus.attention))
        ..orderBy([(t) => OrderingTerm.asc(t.localId)]))
      .get();

  Future<String?> _pendingCreate(String entity, String entityId) async {
    final row = await (db.select(db.syncOperations)
          ..where((t) =>
              t.entity.equals(entity) & t.entityId.equals(entityId) & t.operation.equals('CREATE') & t.status.isIn(OpStatus.outgoing))
          ..limit(1))
        .getSingleOrNull();
    return row?.opId;
  }

  /// Opérations prêtes à partir, dans l'ordre de création (un parent part avant ses dépendants).
  Future<List<SyncOperation>> nextBatch() {
    final now = _clock().toUtc();
    return (db.select(db.syncOperations)
          ..where((t) => t.status.isIn(OpStatus.outgoing) & (t.nextAttemptAt.isNull() | t.nextAttemptAt.isSmallerOrEqualValue(now)))
          ..orderBy([(t) => OrderingTerm.asc(t.localId)])
          ..limit(batchSize))
        .get();
  }

  /// Une opération locale attend-elle encore l'envoi pour cet élément ? (rebase du pull)
  Future<bool> hasOutgoing(String entity, String entityId, {int? exceptLocalId}) async {
    final row = await (db.select(db.syncOperations)
          ..where((t) =>
              t.entity.equals(entity) &
              t.entityId.equals(entityId) &
              t.status.isIn(OpStatus.outgoing) &
              (exceptLocalId == null ? const Constant(true) : t.localId.equals(exceptLocalId).not()))
          ..limit(1))
        .getSingleOrNull();
    return row != null;
  }

  Future<void> markSending(Iterable<int> localIds) => (db.update(db.syncOperations)..where((t) => t.localId.isIn(localIds)))
      .write(SyncOperationsCompanion(status: const Value(OpStatus.sending), updatedAt: Value(_clock().toUtc())));

  Future<void> markDone(SyncOperation op, String status, {Map<String, dynamic>? result, String? error}) =>
      (db.update(db.syncOperations)..where((t) => t.localId.equals(op.localId))).write(SyncOperationsCompanion(
        status: Value(status),
        lastError: Value(error),
        serverResult: result == null ? const Value.absent() : Value(jsonEncode(result)),
        nextAttemptAt: const Value(null),
        updatedAt: Value(_clock().toUtc()),
      ));

  /// Renvoi plus tard, avec une attente qui s'allonge à chaque échec.
  Future<void> markRetry(SyncOperation op, String status, String error) {
    final retries = op.retryCount + 1;
    final now = _clock().toUtc();
    return (db.update(db.syncOperations)..where((t) => t.localId.equals(op.localId))).write(SyncOperationsCompanion(
      status: Value(status),
      retryCount: Value(retries),
      lastError: Value(error),
      nextAttemptAt: Value(now.add(backoff(retries - 1))),
      updatedAt: Value(now),
    ));
  }

  /// Une opération restée « en cours d'envoi » (coupure, application fermée, téléphone éteint) est
  /// renvoyée. Sans risque : le serveur reconnaît un `op_id` déjà reçu et renvoie le résultat enregistré.
  Future<int> recoverInterrupted() => (db.update(db.syncOperations)..where((t) => t.status.equals(OpStatus.sending)))
      .write(SyncOperationsCompanion(status: const Value(OpStatus.pending), updatedAt: Value(_clock().toUtc())));

  /// Opérations dont le parent a été refusé ou abandonné : le serveur les différerait sans fin.
  Future<List<SyncOperation>> blockedByRejected() async {
    final parent = db.alias(db.syncOperations, 'parent');
    final query = db.select(db.syncOperations).join([
      innerJoin(parent, parent.opId.equalsExp(db.syncOperations.dependsOn)),
    ])
      ..where(db.syncOperations.status.isIn(OpStatus.outgoing) & parent.status.isIn([OpStatus.rejected, OpStatus.discarded]));
    return (await query.get()).map((row) => row.readTable(db.syncOperations)).toList();
  }

  /// Purge des opérations terminées plus anciennes que la durée de conservation.
  Future<int> purgeFinished(Duration retention) {
    final limit = _clock().toUtc().subtract(retention);
    return (db.delete(db.syncOperations)
          ..where((t) => t.status.isIn([OpStatus.applied, OpStatus.discarded]) & t.updatedAt.isSmallerThanValue(limit)))
        .go();
  }

  Future<QueueCounts> counts() => _countsQuery().get().then(_toCounts);

  /// Nombre d'opérations à envoyer et à traiter, pour l'indicateur d'état.
  Stream<QueueCounts> watchCounts() => _countsQuery().watch().map(_toCounts);

  final _count = CustomExpression<int>('COUNT(*)');

  JoinedSelectStatement<$SyncOperationsTable, SyncOperation> _countsQuery() => db.selectOnly(db.syncOperations)
    ..addColumns([db.syncOperations.status, _count])
    ..groupBy([db.syncOperations.status]);

  QueueCounts _toCounts(List<TypedResult> rows) {
    var outgoing = 0;
    var attention = 0;
    for (final row in rows) {
      final status = row.read(db.syncOperations.status);
      final n = row.read(_count) ?? 0;
      if (OpStatus.outgoing.contains(status)) outgoing += n;
      if (OpStatus.attention.contains(status)) attention += n;
    }
    return QueueCounts(outgoing, attention);
  }

  /// Centre de synchronisation : éléments à traiter et en attente.
  Stream<List<SyncOperation>> watchVisible() => (db.select(db.syncOperations)
        ..where((t) => t.status.isIn([...OpStatus.attention, ...OpStatus.outgoing]))
        ..orderBy([(t) => OrderingTerm.asc(t.localId)]))
      .watch();

  /// L'utilisateur a pris connaissance d'un conflit (valeur du serveur conservée) ou abandonne une
  /// modification refusée. L'opération reste tracée jusqu'à la purge.
  Future<void> acknowledge(SyncOperation op) => markDone(op, OpStatus.discarded, error: op.lastError);

  /// Bouton « Réessayer » : renvoi immédiat.
  Future<void> retryNow(SyncOperation op) => (db.update(db.syncOperations)..where((t) => t.localId.equals(op.localId))).write(
      SyncOperationsCompanion(status: const Value(OpStatus.pending), nextAttemptAt: const Value(null), updatedAt: Value(_clock().toUtc())));
}

class QueueCounts {
  const QueueCounts(this.outgoing, this.attention);
  final int outgoing;
  final int attention;

  @override
  bool operator ==(Object other) => other is QueueCounts && other.outgoing == outgoing && other.attention == attention;

  @override
  int get hashCode => Object.hash(outgoing, attention);
}
