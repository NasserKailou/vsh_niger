import 'dart:io';

import 'package:drift/drift.dart' show driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/database/database_opener.dart';
import 'package:vsh_mobile/patients/patient_repository.dart';
import 'package:vsh_mobile/synchronization/sync_queue.dart';

void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  late AppDatabase db;
  late PatientRepository repository;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    repository = PatientRepository(db);
  });

  tearDown(() => db.close());

  Map<String, dynamic> identity() => {'first_name': ' Aïcha ', 'last_name': 'Moussa', 'sex': 'F', 'birth_date': '', 'phone': '+22790000000'};

  test('création : donnée et opération écrites ensemble, UUID généré sur l’appareil', () async {
    final id = await repository.create(identity());

    final patient = await (db.select(db.patients)..where((t) => t.id.equals(id))).getSingle();
    expect(patient.firstName, 'Aïcha', reason: 'espaces retirés');
    expect(patient.birthDate, isNull, reason: 'valeur vide envoyée comme null');
    expect(patient.version, 0);
    final op = await db.select(db.syncOperations).getSingle();
    expect(op.operation, 'CREATE');
    expect(op.entityId, id);
    expect(op.payload, contains('"first_name":"Aïcha"'));
    expect(RegExp(r'^[0-9a-f-]{36}$').hasMatch(id), isTrue);
  });

  test('échec de validation : ni donnée ni opération (transaction)', () async {
    expect(() => repository.create({'first_name': 'A', 'last_name': '', 'sex': 'F'}), throwsArgumentError);
    expect(() => repository.create({'first_name': 'A', 'last_name': 'B', 'sex': 'X'}), throwsArgumentError);
    expect(await db.select(db.patients).get(), isEmpty);
    expect(await db.select(db.syncOperations).get(), isEmpty);
  });

  test('modification : seuls les champs changés, avec base et version connue', () async {
    final id = await repository.create(identity());
    expect(await repository.update(id, identity()), isFalse, reason: 'rien de changé : aucune opération');

    await repository.update(id, {...identity(), 'phone': '+22796000000'});
    final ops = await db.select(db.syncOperations).get();
    expect(ops, hasLength(2));
    final update = ops.last;
    expect(update.payload, '{"phone":"+22796000000"}');
    expect(update.base, '{"phone":"+22790000000"}');
    expect(update.baseVersion, 0);
    expect(update.dependsOn, ops.first.opId, reason: 'dépend de la création pas encore envoyée');
  });

  test('recherche locale par nom, dossier et téléphone', () async {
    await repository.create(identity());
    await repository.create({'first_name': 'Issa', 'last_name': 'Hamani', 'sex': 'M', 'phone': '+22791234567'});

    expect((await repository.watchSearch('ham').first).single.firstName, 'Issa');
    expect((await repository.watchSearch('aïcha mou').first).single.lastName, 'Moussa');
    expect(await repository.watchSearch('9123').first, hasLength(1));
    expect(await repository.watchSearch('').first, hasLength(2));
    expect(await repository.watchUnsynced().first, hasLength(2));
  });

  test('base chiffrée : illisible sans la clé', () async {
    final dir = await Directory.systemTemp.createTemp('vsh_crypt_');
    final file = File('${dir.path}/vsh.sqlite');
    final key = List.filled(32, 'ab').join();
    try {
      final encrypted = AppDatabase(encryptedExecutor(file, key));
      await PatientRepository(encrypted).create(identity());
      await encrypted.close();

      final bytes = await file.readAsBytes();
      expect(String.fromCharCodes(bytes.take(15)), isNot('SQLite format 3'), reason: 'en-tête chiffré');
      expect(String.fromCharCodes(bytes).contains('Moussa'), isFalse);

      final reopened = AppDatabase(encryptedExecutor(file, key));
      expect(await reopened.select(reopened.patients).get(), hasLength(1));
      await reopened.close();

      final wrong = AppDatabase(encryptedExecutor(file, List.filled(32, 'cd').join()));
      await expectLater(wrong.select(wrong.patients).get(), throwsA(anything));
      await wrong.close();
    } finally {
      await dir.delete(recursive: true);
    }
  });

  test('attente progressive plafonnée', () {
    expect(SyncQueue.backoff(0), const Duration(seconds: 15));
    expect(SyncQueue.backoff(3), const Duration(seconds: 120));
    expect(SyncQueue.backoff(50), const Duration(hours: 1));
  });
}
