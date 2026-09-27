import 'dart:convert';

import 'package:drift/drift.dart';

import 'app_database.dart';

/// Écrit dans les tables locales l'état reçu du serveur, et l'état modifié sur l'appareil.
/// `patient` a sa table typée ; les autres entités vont dans `records`.
class LocalStore {
  LocalStore(this.db);

  final AppDatabase db;

  static const patientEntity = 'patient';

  /// Insère ou remplace l'élément avec l'état complet [data] (qui porte `version`).
  Future<void> upsert(String entity, String id, Map<String, dynamic> data) async {
    final now = DateTime.now().toUtc();
    final version = (data['version'] as num?)?.toInt() ?? 0;
    if (entity == patientEntity) {
      await db.into(db.patients).insertOnConflictUpdate(PatientsCompanion.insert(
            id: id,
            fileNumber: Value(data['file_number'] as String?),
            firstName: (data['first_name'] ?? '') as String,
            lastName: (data['last_name'] ?? '') as String,
            sex: (data['sex'] ?? '') as String,
            birthDate: Value(data['birth_date'] as String?),
            phone: Value(data['phone'] as String?),
            status: Value((data['status'] ?? 'PENDING') as String),
            version: Value(version),
            data: Value(jsonEncode(data)),
            updatedAt: now,
          ));
      return;
    }
    await db.into(db.records).insertOnConflictUpdate(RecordsCompanion.insert(
          entity: entity,
          id: id,
          patientId: Value(data['patient_id'] as String?),
          version: Value(version),
          data: jsonEncode(data),
          updatedAt: now,
        ));
  }

  Future<void> remove(String entity, String id) async {
    if (entity == patientEntity) {
      await (db.delete(db.patients)..where((t) => t.id.equals(id))).go();
    } else {
      await (db.delete(db.records)..where((t) => t.entity.equals(entity) & t.id.equals(id))).go();
    }
  }

  Future<Map<String, dynamic>?> read(String entity, String id) async {
    final String? raw;
    if (entity == patientEntity) {
      raw = (await (db.select(db.patients)..where((t) => t.id.equals(id))).getSingleOrNull())?.data;
    } else {
      raw = (await (db.select(db.records)..where((t) => t.entity.equals(entity) & t.id.equals(id))).getSingleOrNull())?.data;
    }
    return raw == null ? null : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  /// Applique des champs modifiés sur l'appareil, sans changer la version connue du serveur.
  Future<void> merge(String entity, String id, Map<String, dynamic> fields) async {
    final current = await read(entity, id);
    if (current == null) return;
    await upsert(entity, id, {...current, ...fields});
  }

  /// Annule localement une modification abandonnée (refusée par le serveur) : une création jamais
  /// acceptée disparaît, une modification retrouve les valeurs d'avant.
  Future<void> revert(SyncOperation op) async {
    if (op.operation == 'CREATE') {
      final current = await read(op.entity, op.entityId);
      if (current != null && ((current['version'] as num?)?.toInt() ?? 0) == 0) {
        await remove(op.entity, op.entityId);
      }
    } else if (op.operation == 'UPDATE' && op.base != null) {
      await merge(op.entity, op.entityId, (jsonDecode(op.base!) as Map).cast<String, dynamic>());
    } else if (op.operation == 'ACTION' && op.base != null) {
      // Action : `base` garde l'état complet d'avant l'action (affichage optimiste annulé).
      await upsert(op.entity, op.entityId, (jsonDecode(op.base!) as Map).cast<String, dynamic>());
    }
  }

  /// Met de côté l'état serveur d'un élément modifié localement (voir [ServerShadows]).
  Future<void> saveShadow(String entity, String id, Map<String, dynamic>? data) => db.into(db.serverShadows).insertOnConflictUpdate(
      ServerShadowsCompanion.insert(entity: entity, id: id, data: Value(data == null ? null : jsonEncode(data)), receivedAt: DateTime.now().toUtc()));

  Future<void> dropShadow(String entity, String id) =>
      (db.delete(db.serverShadows)..where((t) => t.entity.equals(entity) & t.id.equals(id))).go();

  /// Applique l'état serveur mis de côté, s'il existe. Renvoie `true` si un état a été appliqué.
  Future<bool> applyShadow(String entity, String id) async {
    final shadow = await (db.select(db.serverShadows)..where((t) => t.entity.equals(entity) & t.id.equals(id))).getSingleOrNull();
    if (shadow == null) return false;
    if (shadow.data == null) {
      await remove(entity, id);
    } else {
      await upsert(entity, id, (jsonDecode(shadow.data!) as Map).cast<String, dynamic>());
    }
    await dropShadow(entity, id);
    return true;
  }
}
