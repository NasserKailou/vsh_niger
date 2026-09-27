import 'package:drift/drift.dart';

part 'app_database.g.dart';

/// Dossiers patients disponibles hors ligne (colonnes typées pour la recherche et le tri ;
/// `data` garde l'état serveur complet, en JSON).
class Patients extends Table {
  TextColumn get id => text()();
  TextColumn get fileNumber => text().nullable()();
  TextColumn get firstName => text()();
  TextColumn get lastName => text()();
  TextColumn get sex => text()();
  TextColumn get birthDate => text().nullable()();
  TextColumn get phone => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('PENDING'))();

  /// Version serveur connue (0 = créé sur l'appareil, pas encore synchronisé).
  IntColumn get version => integer().withDefault(const Constant(0))();
  TextColumn get data => text().withDefault(const Constant('{}'))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

/// Autres entités synchronisées (contacts, adresses, consultations, visites…), stockées telles que
/// reçues du serveur. Les modules y ajoutent des tables typées quand un écran en a besoin.
class Records extends Table {
  TextColumn get entity => text()();
  TextColumn get id => text()();
  TextColumn get patientId => text().nullable()();
  IntColumn get version => integer().withDefault(const Constant(0))();
  TextColumn get data => text()();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {entity, id};
}

/// File des opérations locales à envoyer (contrat : docs/07_SYNCHRONISATION.md). Les opérations
/// appliquées sont conservées un temps pour la traçabilité, puis purgées.
@DataClassName('SyncOperation')
class SyncOperations extends Table {
  IntColumn get localId => integer().autoIncrement()();

  /// Clé d'idempotence côté serveur.
  TextColumn get opId => text().unique()();
  TextColumn get entity => text()();

  /// Identifiant de l'élément (UUID généré par l'appareil à la création = identifiant serveur).
  TextColumn get entityId => text()();

  /// CREATE, UPDATE, DELETE ou ACTION.
  TextColumn get operation => text()();
  TextColumn get action => text().nullable()();
  TextColumn get payload => text()();

  /// Valeurs avant modification (UPDATE), pour la fusion champ par champ côté serveur.
  TextColumn get base => text().nullable()();
  IntColumn get baseVersion => integer().nullable()();

  /// Opération dont celle-ci dépend (élément créé hors ligne et pas encore envoyé).
  TextColumn get dependsOn => text().nullable()();

  /// PENDING, SENDING, DEFERRED, ERROR (à renvoyer) ; APPLIED ; REJECTED, CONFLICT (à traiter).
  TextColumn get status => text()();
  IntColumn get retryCount => integer().withDefault(const Constant(0))();
  DateTimeColumn get nextAttemptAt => dateTime().nullable()();
  TextColumn get lastError => text().nullable()();
  TextColumn get serverResult => text().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
}

/// État serveur mis de côté pendant qu'une modification locale est en attente sur l'élément (rebase).
/// Appliqué si la modification locale est abandonnée ; remplacé par le résultat si elle est acceptée.
class ServerShadows extends Table {
  TextColumn get entity => text()();
  TextColumn get id => text()();

  /// null : supprimé sur le serveur.
  TextColumn get data => text().nullable()();
  DateTimeColumn get receivedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {entity, id};
}

/// Petites valeurs persistantes : curseur de synchronisation, dernière synchronisation…
class SyncMeta extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column<Object>> get primaryKey => {key};
}

@DriftDatabase(tables: [Patients, Records, SyncOperations, SyncMeta, ServerShadows])
class AppDatabase extends _$AppDatabase {
  AppDatabase(super.executor);

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (m) async {
          await m.createAll();
          await customStatement('CREATE INDEX ix_patients_name ON patients (last_name, first_name)');
          await customStatement('CREATE INDEX ix_records_patient ON records (patient_id, entity)');
          await customStatement('CREATE INDEX ix_sync_operations_status ON sync_operations (status, local_id)');
          await customStatement('CREATE INDEX ix_sync_operations_entity ON sync_operations (entity, entity_id)');
        },
        onUpgrade: (m, from, to) async {
          if (from < 2) {
            await m.createTable(serverShadows);
          }
        },
      );

  Future<String?> readMeta(String key) async {
    final row = await (select(syncMeta)..where((t) => t.key.equals(key))).getSingleOrNull();
    return row?.value;
  }

  Future<void> writeMeta(String key, String value) =>
      into(syncMeta).insertOnConflictUpdate(SyncMetaCompanion.insert(key: key, value: value));

  /// Effacement complet (changement d'utilisateur sur un appareil partagé, appareil révoqué).
  Future<void> wipe() => transaction(() async {
        for (final table in allTables) {
          await delete(table).go();
        }
      });
}
