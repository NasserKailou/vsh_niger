import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import '../database/local_store.dart';
import '../synchronization/sync_engine.dart';
import '../synchronization/sync_queue.dart';
import 'location_service.dart';
import 'visit.dart';

/// Tournée à domicile hors ligne : visites de l'équipe, actions de terrain horodatées et
/// géolocalisées, consultation `DOMICILE` ouverte au démarrage, constantes et notes.
class HomecareRepository {
  HomecareRepository(this.db, {SyncQueue? queue, LocalStore? store, DateTime Function()? clock})
      : queue = queue ?? SyncQueue(db),
        store = store ?? LocalStore(db),
        _clock = clock ?? DateTime.now;

  final AppDatabase db;
  final SyncQueue queue;
  final LocalStore store;
  final DateTime Function() _clock;

  static const visitEntity = 'homecare_request';
  static const consultationEntity = 'consultation';
  static const vitalEntity = 'vital_sign';
  static const noteEntity = 'consultation_note';

  static Map<String, dynamic> _decode(String raw) => (jsonDecode(raw) as Map).cast<String, dynamic>();

  Stream<List<Visit>> watchVisits() => (db.select(db.records)
        ..where((t) => t.entity.equals(visitEntity))
        ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)]))
      .watch()
      .map((rows) => rows.map((r) => Visit(_decode(r.data))).toList());

  Stream<Visit?> watchVisit(String id) => (db.select(db.records)..where((t) => t.entity.equals(visitEntity) & t.id.equals(id)))
      .watchSingleOrNull()
      .map((r) => r == null ? null : Visit(_decode(r.data)));

  /// Action de terrain. Enregistrée tout de suite sur le téléphone (affichage), envoyée ensuite ;
  /// les actions d'une même visite partent dans l'ordre, chacune seulement si la précédente a été
  /// acceptée. Renvoie l'identifiant de la consultation ouverte au démarrage.
  Future<String?> act(String visitId, String action, {GeoFix? fix, String? comment, String? reason}) async {
    final transition = VisitFlow.transitions[action];
    if (transition == null) throw ArgumentError.value(action, 'action');
    if (VisitFlow.needReason.contains(action) && (reason == null || reason.trim().isEmpty)) {
      throw ArgumentError.value(reason, 'reason', 'motif obligatoire');
    }
    final at = (await SyncEngine.serverNow(db, _clock)).toIso8601String();
    return db.transaction(() async {
      final before = await store.read(visitEntity, visitId);
      if (before == null) throw StateError('Visite absente de ce téléphone.');
      final visit = Visit(before);
      if (!VisitFlow.allowed(action, visit.status)) {
        throw StateError("Action impossible à l'étape « ${VisitFlow.labels[visit.status] ?? visit.status} ».");
      }
      final payload = <String, dynamic>{
        'at': at,
        if (fix != null && fix.usable) ...fix.toPayload(),
        if (comment != null && comment.trim().isNotEmpty) 'comment': comment.trim(),
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
      };
      String? consultationId;
      if (action == 'start') {
        consultationId = SyncQueue.newId();
        payload['consultation_id'] = consultationId;
        await store.upsert(consultationEntity, consultationId, {
          'id': consultationId,
          'patient_id': visit.patientId,
          'homecare_request_id': visitId,
          'type': 'DOMICILE',
          'status': 'OUVERTE',
          'version': 0,
        });
      }

      final after = {...before, 'status': transition.$2};
      final stamp = VisitFlow.stamps[action];
      if (stamp != null) after[stamp] = at;
      if (consultationId != null) after['consultation_id'] = consultationId;
      if (action == 'release') after['team'] = null;
      if (action == 'fail') after['failure_reason'] = payload['reason'];
      after['history'] = [
        ...visit.history,
        {
          'from': visit.status,
          'to': transition.$2,
          'at': at,
          'comment': payload['comment'] ?? payload['reason'],
          'latitude': payload['latitude'],
          'longitude': payload['longitude'],
          'pending': true,
        },
      ];
      await store.upsert(visitEntity, visitId, after);
      await queue.enqueue(
        entity: visitEntity,
        entityId: visitId,
        operation: 'ACTION',
        action: action,
        payload: payload,
        base: before,
        chain: true,
      );
      return consultationId;
    });
  }

  /// Points de trajet (100 au plus par envoi, idempotents côté serveur).
  Future<void> track(String visitId, List<GeoFix> points) async {
    final usable = points.where((p) => p.usable).toList();
    if (usable.isEmpty) return;
    await db.transaction(() async {
      for (var i = 0; i < usable.length; i += 100) {
        await queue.enqueue(
          entity: visitEntity,
          entityId: visitId,
          operation: 'ACTION',
          action: 'track',
          payload: {'points': usable.skip(i).take(100).map((p) => p.toTrackPoint()).toList()},
          chain: true,
        );
      }
    });
  }

  /// Opération `start` encore en file pour cette consultation (créée hors ligne).
  Future<String?> pendingStart(String consultationId) async {
    final row = await (db.select(db.syncOperations)
          ..where((t) =>
              t.entity.equals(visitEntity) &
              t.action.equals('start') &
              t.status.isIn(OpStatus.outgoing) &
              t.payload.like('%"consultation_id":"$consultationId"%'))
          ..limit(1))
        .getSingleOrNull();
    return row?.opId;
  }

  /// Constantes : bornes de plausibilité identiques à celles du serveur, sans interprétation clinique.
  Future<String> addVitals({required String consultationId, required String patientId, required Map<String, num?> measures, String? notes}) async {
    final values = {for (final e in measures.entries) if (e.value != null) e.key: e.value};
    if (values.isEmpty) throw ArgumentError('Saisissez au moins une mesure.');
    final recordedAt = (await SyncEngine.serverNow(db, _clock)).toIso8601String();
    final id = SyncQueue.newId();
    return db.transaction(() async {
      final payload = {
        ...values,
        'consultation_id': consultationId,
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        'recorded_at': recordedAt,
      };
      await store.upsert(vitalEntity, id, {...payload, 'id': id, 'patient_id': patientId, 'version': 0});
      await queue.enqueue(
        entity: vitalEntity,
        entityId: id,
        operation: 'CREATE',
        payload: payload,
        dependsOnOp: await pendingStart(consultationId),
        parentEntity: consultationEntity,
        parentId: consultationId,
      );
      return id;
    });
  }

  Future<String> addNote({required String consultationId, required String patientId, required String content}) async {
    final text = content.trim();
    if (text.isEmpty) throw ArgumentError('Note vide.');
    final id = SyncQueue.newId();
    final createdAt = (await SyncEngine.serverNow(db, _clock)).toIso8601String();
    return db.transaction(() async {
      final payload = {'consultation_id': consultationId, 'content': text};
      await store.upsert(noteEntity, id, {...payload, 'id': id, 'patient_id': patientId, 'created_at': createdAt, 'version': 0});
      await queue.enqueue(
        entity: noteEntity,
        entityId: id,
        operation: 'CREATE',
        payload: payload,
        dependsOnOp: await pendingStart(consultationId),
        parentEntity: consultationEntity,
        parentId: consultationId,
      );
      return id;
    });
  }

  Stream<List<Map<String, dynamic>>> _watchByConsultation(String entity, String consultationId, String orderField) =>
      db.customSelect(
        "SELECT data FROM records WHERE entity = ? AND json_extract(data, '\$.consultation_id') = ? "
        "ORDER BY json_extract(data, '\$.$orderField') DESC",
        variables: [Variable.withString(entity), Variable.withString(consultationId)],
        readsFrom: {db.records},
      ).watch().map((rows) => rows.map((r) => _decode(r.read<String>('data'))).toList());

  Stream<List<Map<String, dynamic>>> watchVitals(String consultationId) => _watchByConsultation(vitalEntity, consultationId, 'recorded_at');

  Stream<List<Map<String, dynamic>>> watchNotes(String consultationId) => _watchByConsultation(noteEntity, consultationId, 'created_at');

  /// Éléments de la visite pas encore envoyés.
  Stream<int> watchPending(String visitId) => (db.select(db.syncOperations)
        ..where((t) => t.entity.equals(visitEntity) & t.entityId.equals(visitId) & t.status.isIn(OpStatus.outgoing)))
      .watch()
      .map((rows) => rows.length);
}
