import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import '../database/local_store.dart';
import '../synchronization/sync_queue.dart';

/// Champs d'identité modifiables hors ligne (mêmes règles que `PatientService::IDENTITY_RULES`,
/// vérifiées de nouveau par le serveur).
const patientIdentityFields = ['first_name', 'last_name', 'sex', 'birth_date', 'birth_date_is_estimated', 'phone'];

class PatientRepository {
  PatientRepository(this.db, {SyncQueue? queue, LocalStore? store})
      : queue = queue ?? SyncQueue(db),
        store = store ?? LocalStore(db);

  final AppDatabase db;
  final SyncQueue queue;
  final LocalStore store;

  /// Liste locale, filtrée par nom, prénom, n° de dossier ou téléphone.
  Stream<List<Patient>> watchSearch(String term, {int limit = 100}) {
    final query = db.select(db.patients)
      ..orderBy([(t) => OrderingTerm.asc(t.lastName), (t) => OrderingTerm.asc(t.firstName)])
      ..limit(limit);
    final words = term.trim().toLowerCase().split(RegExp(r'\s+')).where((w) => w.isNotEmpty);
    for (final word in words) {
      final like = '%${word.replaceAll('%', '').replaceAll('_', '')}%';
      query.where((t) =>
          t.lastName.lower().like(like) |
          t.firstName.lower().like(like) |
          t.fileNumber.lower().like(like) |
          t.phone.like(like));
    }
    return query.watch();
  }

  Stream<Patient?> watch(String id) => (db.select(db.patients)..where((t) => t.id.equals(id))).watchSingleOrNull();

  /// Ids des patients ayant une modification pas encore synchronisée.
  Stream<Set<String>> watchUnsynced() => (db.selectOnly(db.syncOperations, distinct: true)
        ..addColumns([db.syncOperations.entityId])
        ..where(db.syncOperations.entity.equals(LocalStore.patientEntity) & db.syncOperations.status.isIn(OpStatus.outgoing)))
      .watch()
      .map((rows) => rows.map((r) => r.read(db.syncOperations.entityId)!).toSet());

  /// Création hors ligne : l'identifiant est généré ici et sera celui du serveur ; le n° de dossier
  /// est attribué par le serveur à la réception.
  Future<String> create(Map<String, dynamic> identity) {
    final values = _identity(identity);
    final id = SyncQueue.newId();
    return db.transaction(() async {
      await store.upsert(LocalStore.patientEntity, id, {...values, 'id': id, 'version': 0, 'status': 'PENDING'});
      await queue.enqueue(entity: LocalStore.patientEntity, entityId: id, operation: 'CREATE', payload: values);
      return id;
    });
  }

  /// Modification hors ligne : seuls les champs changés partent, avec leur valeur d'avant (`base`)
  /// et la version connue, pour la fusion champ par champ sur le serveur.
  Future<bool> update(String id, Map<String, dynamic> identity) {
    final wanted = _identity(identity);
    return db.transaction(() async {
      final row = await (db.select(db.patients)..where((t) => t.id.equals(id))).getSingle();
      final current = (jsonDecode(row.data) as Map).cast<String, dynamic>();
      final changes = <String, dynamic>{};
      final base = <String, dynamic>{};
      wanted.forEach((key, value) {
        if (current[key] != value) {
          changes[key] = value;
          base[key] = current[key];
        }
      });
      if (changes.isEmpty) return false;
      await store.merge(LocalStore.patientEntity, id, changes);
      await queue.enqueue(
        entity: LocalStore.patientEntity,
        entityId: id,
        operation: 'UPDATE',
        payload: changes,
        base: base,
        baseVersion: row.version,
      );
      return true;
    });
  }

  static Map<String, dynamic> _identity(Map<String, dynamic> input) {
    final values = <String, dynamic>{};
    for (final key in patientIdentityFields) {
      if (!input.containsKey(key)) continue;
      final value = input[key];
      values[key] = value is String ? (value.trim().isEmpty ? null : value.trim()) : value;
    }
    for (final key in ['first_name', 'last_name', 'sex']) {
      if (values[key] == null) {
        throw ArgumentError.value(values[key], key, 'obligatoire');
      }
    }
    if (!const ['M', 'F'].contains(values['sex'])) {
      throw ArgumentError.value(values['sex'], 'sex', 'M ou F');
    }
    return values;
  }
}
