import 'dart:convert';

import 'package:drift/drift.dart';

import '../synchronization/sync_engine.dart';
import '../synchronization/sync_queue.dart';
import 'app_database.dart';
import 'local_store.dart';

/// Écritures hors ligne communes aux entités stockées dans `records` (soins, rendez-vous,
/// notifications…) : affichage immédiat, opération dans la même transaction, état d'avant gardé
/// pour pouvoir annuler l'affichage si le serveur refuse.
class RecordRepository {
  RecordRepository(this.db, {SyncQueue? queue, LocalStore? store, DateTime Function()? clock})
      : queue = queue ?? SyncQueue(db),
        store = store ?? LocalStore(db),
        clock = clock ?? DateTime.now;

  final AppDatabase db;
  final SyncQueue queue;
  final LocalStore store;
  final DateTime Function() clock;

  static Map<String, dynamic> decode(String raw) => (jsonDecode(raw) as Map).cast<String, dynamic>();

  /// Heure du serveur estimée, pour horodater les actions faites hors ligne.
  Future<String> serverNowIso() async => (await SyncEngine.serverNow(db, clock)).toIso8601String();

  Stream<List<Map<String, dynamic>>> watchEntity(String entity, {String? patientId}) {
    final query = db.select(db.records)..where((t) => t.entity.equals(entity));
    if (patientId != null) query.where((t) => t.patientId.equals(patientId));
    return query.watch().map((rows) => rows.map((r) => decode(r.data)).toList());
  }

  /// Ids des éléments ayant une opération pas encore envoyée.
  Stream<Set<String>> watchUnsynced(String entity) => (db.selectOnly(db.syncOperations, distinct: true)
        ..addColumns([db.syncOperations.entityId])
        ..where(db.syncOperations.entity.equals(entity) & db.syncOperations.status.isIn(OpStatus.outgoing)))
      .watch()
      .map((rows) => rows.map((r) => r.read(db.syncOperations.entityId)!).toSet());

  /// Action de la machine à états du serveur, affichée tout de suite avec [changes].
  Future<void> act(String entity, String id, String action, Map<String, dynamic> payload, Map<String, dynamic> changes) =>
      db.transaction(() async {
        final before = await store.read(entity, id);
        if (before == null) throw StateError('Élément absent de ce téléphone.');
        await store.upsert(entity, id, {...before, ...changes});
        await queue.enqueue(entity: entity, entityId: id, operation: 'ACTION', action: action, payload: payload, base: before, chain: true);
      });

  /// Création : identifiant généré sur l'appareil (identique sur le serveur).
  Future<String> create(String entity, Map<String, dynamic> payload, {Map<String, dynamic> display = const {}, String? dependsOnOp, String? parentEntity, String? parentId}) {
    final id = SyncQueue.newId();
    return db.transaction(() async {
      await store.upsert(entity, id, {...payload, ...display, 'id': id, 'version': 0});
      await queue.enqueue(
        entity: entity,
        entityId: id,
        operation: 'CREATE',
        payload: payload,
        dependsOnOp: dependsOnOp,
        parentEntity: parentEntity,
        parentId: parentId,
      );
      return id;
    });
  }

  /// Modification des seuls champs changés, avec leur valeur d'avant et la version connue.
  Future<bool> update(String entity, String id, Map<String, dynamic> fields) => db.transaction(() async {
        final current = await store.read(entity, id);
        if (current == null) throw StateError('Élément absent de ce téléphone.');
        final changes = <String, dynamic>{};
        final base = <String, dynamic>{};
        fields.forEach((key, value) {
          if (current[key] != value) {
            changes[key] = value;
            base[key] = current[key];
          }
        });
        if (changes.isEmpty) return false;
        await store.merge(entity, id, changes);
        await queue.enqueue(
          entity: entity,
          entityId: id,
          operation: 'UPDATE',
          payload: changes,
          base: base,
          baseVersion: (current['version'] as num?)?.toInt(),
        );
        return true;
      });
}
