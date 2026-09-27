import 'dart:io';

import 'package:drift/drift.dart' show driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/synchronization/sync_api.dart';
import 'package:vsh_mobile/synchronization/sync_engine.dart';
import 'package:vsh_mobile/synchronization/sync_queue.dart';
import 'package:vsh_mobile/patients/patient_repository.dart';

import 'fake_sync_api.dart';

/// Scénarios exigés par le cahier des charges (Internet coupé, coupure pendant l'envoi, double
/// synchronisation, erreur serveur, jeton expiré, conflit, fermeture et redémarrage).
void main() {
  // Le scénario du redémarrage ouvre volontairement une seconde base.
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  late AppDatabase db;
  late FakeSyncServer server;
  late DateTime now;
  late SyncQueue queue;
  late SyncEngine engine;
  late PatientRepository patients;

  SyncEngine newEngine({Future<void> Function()? onRevoked, int pullLimit = 200}) =>
      SyncEngine(db: db, api: server, queue: queue, onDeviceRevoked: onRevoked, pullLimit: pullLimit);

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    server = FakeSyncServer();
    now = DateTime.utc(2026, 9, 27, 8);
    queue = SyncQueue(db, clock: () => now);
    engine = newEngine();
    patients = PatientRepository(db, queue: queue);
  });

  tearDown(() async {
    await engine.dispose();
    await db.close();
  });

  Future<List<SyncOperation>> ops() => db.select(db.syncOperations).get();
  Future<Patient> local(String id) => (db.select(db.patients)..where((t) => t.id.equals(id))).getSingle();
  Map<String, dynamic> aicha() => {'first_name': 'Aïcha', 'last_name': 'Moussa', 'sex': 'F', 'phone': '+22790000000'};

  test('Internet disponible : la création part, le n° de dossier revient', () async {
    final id = await patients.create(aicha());
    expect((await ops()).single.status, OpStatus.pending);

    final report = await engine.run();

    expect(report.applied, 1);
    expect(server.get('patient', id)!['first_name'], 'Aïcha');
    final row = await local(id);
    expect(row.fileNumber, 'VSH-2026-000001');
    expect(row.version, 1);
    expect((await ops()).single.status, OpStatus.applied);
    expect(engine.state.phase, SyncPhase.idle);
    expect(engine.state.lastSuccessAt, isNotNull);
  });

  test('Internet coupé puis revenu : rien n’est perdu, envoi au retour', () async {
    final id = await patients.create(aicha());
    server.failNextPush = SyncNetworkException();

    await engine.run();

    expect(engine.state.phase, SyncPhase.offline);
    expect((await ops()).single.status, OpStatus.pending);
    expect((await local(id)).firstName, 'Aïcha');
    expect(server.get('patient', id), isNull);

    await engine.run();
    expect(server.get('patient', id), isNotNull);
    expect((await ops()).single.status, OpStatus.applied);
  });

  test('Coupure pendant l’opération (réponse perdue) : renvoi sans doublon', () async {
    final id = await patients.create(aicha());
    server.loseNextPushResponse = true;

    await engine.run();
    expect(engine.state.phase, SyncPhase.offline);
    expect(server.entities['patient']!.length, 1, reason: 'le serveur a appliqué avant la coupure');
    expect((await ops()).single.status, OpStatus.pending);

    await engine.run();
    expect(server.entities['patient']!.length, 1);
    expect(server.receivedOpIds.length, 2, reason: 'même op_id reçu deux fois');
    expect((await local(id)).fileNumber, isNotNull);
    expect((await ops()).single.status, OpStatus.applied);
  });

  test('Double synchronisation simultanée : une seule exécution', () async {
    await patients.create(aicha());
    final results = await Future.wait([engine.run(), engine.run()]);

    expect(identical(results[0], results[1]), isTrue);
    expect(server.pushCalls, 1);
    expect(server.entities['patient']!.length, 1);
  });

  test('Erreur serveur : attente progressive, puis renvoi', () async {
    final id = await patients.create(aicha());
    server.errorWhen = (_) => true;

    await engine.run();
    var op = (await ops()).single;
    expect(op.status, OpStatus.error);
    expect(op.retryCount, 1);
    expect(op.nextAttemptAt!.toUtc(), now.add(SyncQueue.backoff(0)));

    server.errorWhen = null;
    await engine.run();
    expect(server.pushCalls, 1, reason: 'pas de renvoi avant la fin de l’attente');

    now = now.add(const Duration(minutes: 1));
    await engine.run();
    op = (await ops()).single;
    expect(op.status, OpStatus.applied);
    expect(server.get('patient', id), isNotNull);
    expect(SyncQueue.backoff(20), const Duration(hours: 1), reason: 'attente plafonnée');
  });

  test('Serveur indisponible (5xx) : la file est intacte', () async {
    await patients.create(aicha());
    server.failNextPush = SyncServerException();

    await engine.run();
    expect(engine.state.phase, SyncPhase.failed);
    expect((await ops()).single.status, OpStatus.pending);
  });

  test('Jeton expiré et non renouvelable : session à rouvrir, file conservée', () async {
    await patients.create(aicha());
    server.failNextPush = SyncAuthException();

    await engine.run();
    expect(engine.state.phase, SyncPhase.sessionExpired);
    expect((await ops()).single.status, OpStatus.pending);
    expect(await db.select(db.patients).get(), hasLength(1));
  });

  test('Appareil révoqué : données locales effacées', () async {
    await patients.create(aicha());
    server.failNextPush = SyncAuthException('Cet appareil a été révoqué.', true);
    var wiped = false;
    await engine.dispose();
    engine = newEngine(onRevoked: () async {
      wiped = true;
      await db.wipe();
    });

    await engine.run();
    expect(wiped, isTrue);
    expect(await db.select(db.patients).get(), isEmpty);
    expect(await ops(), isEmpty);
  });

  group('Conflits', () {
    late String id;

    setUp(() async {
      id = await patients.create(aicha());
      await engine.run();
    });

    test('champs différents : fusion sans conflit', () async {
      server.serverEdit('patient', id, {'first_name': 'Aïchatou'});
      await patients.update(id, {...aicha(), 'phone': '+22796000000'});

      await engine.run();
      final op = (await ops()).last;
      expect(op.status, OpStatus.applied);
      expect(op.baseVersion, 1);
      final row = await local(id);
      expect(row.firstName, 'Aïchatou');
      expect(row.phone, '+22796000000');
      expect(row.version, 3);
    });

    test('même champ : la valeur du serveur est conservée, conflit à traiter', () async {
      server.serverEdit('patient', id, {'phone': '+22791111111'});
      await patients.update(id, {...aicha(), 'phone': '+22796000000'});

      final report = await engine.run();
      expect(report.conflicts, 1);
      final op = (await ops()).last;
      expect(op.status, OpStatus.conflict);
      expect(op.lastError, contains('valeur du serveur'));
      expect((await local(id)).phone, '+22791111111');
      expect((await queue.counts()).attention, 1);

      await engine.dismiss(op);
      expect((await queue.counts()).attention, 0);
    });

    test('pull pendant une modification locale en attente : la modification locale reste affichée', () async {
      server.serverEdit('patient', id, {'first_name': 'Autre'});
      await patients.update(id, {...aicha(), 'last_name': 'Moussa-Issa'});
      server.failNextPush = SyncNetworkException();
      await engine.run();
      expect((await local(id)).lastName, 'Moussa-Issa');

      await engine.run();
      final row = await local(id);
      expect(row.firstName, 'Autre');
      expect(row.lastName, 'Moussa-Issa');
    });
  });

  test('Modification refusée après un pull mis de côté : l’abandon affiche l’état du serveur', () async {
    final id = await patients.create(aicha());
    await engine.run();
    server.serverEdit('patient', id, {'first_name': 'Autre'});
    await patients.update(id, {...aicha(), 'last_name': 'Moussa-Issa'});

    // Envoi en échec temporaire, mais récupération faite : l'état serveur est mis de côté.
    server.errorWhen = (_) => true;
    await engine.run();
    expect((await local(id)).lastName, 'Moussa-Issa');
    expect((await local(id)).firstName, 'Aïcha');
    expect(await db.select(db.serverShadows).get(), hasLength(1));

    server.errorWhen = null;
    server.rejectWhen = (op) => op['operation'] == 'UPDATE';
    now = now.add(const Duration(minutes: 1));
    await engine.run();
    final op = (await ops()).last;
    expect(op.status, OpStatus.rejected);

    await engine.dismiss(op);
    final row = await local(id);
    expect(row.firstName, 'Autre');
    expect(row.lastName, 'Moussa');
    expect(row.version, 2);
    expect(await db.select(db.serverShadows).get(), isEmpty);
  });

  test('Refus du serveur : visible, puis abandon qui annule la création locale', () async {
    server.rejectWhen = (op) => op['entity'] == 'patient';
    final id = await patients.create(aicha());

    final report = await engine.run();
    expect(report.rejected, 1);
    final op = (await ops()).single;
    expect(op.status, OpStatus.rejected);
    expect(op.lastError, 'Données invalides.');

    await engine.dismiss(op);
    expect(await db.select(db.patients).get(), isEmpty);
    expect((await ops()).single.status, OpStatus.discarded);
    expect(id, isNotEmpty);
  });

  test('Dépendances : l’élément enfant part après son parent créé hors ligne', () async {
    final id = await patients.create(aicha());
    final contactId = SyncQueue.newId();
    await db.transaction(() => queue.enqueue(
          entity: 'patient_contact',
          entityId: contactId,
          operation: 'CREATE',
          payload: {'patient_id': id, 'name': 'Contact', 'phone': '+22790000001'},
          parentEntity: 'patient',
          parentId: id,
        ));
    final child = (await ops()).last;
    expect(child.dependsOn, (await ops()).first.opId);

    await engine.run();
    expect((await ops()).map((o) => o.status), everyElement(OpStatus.applied));
    expect(server.get('patient_contact', contactId), isNotNull);
  });

  test('Dépendances : parent refusé, enfant bloqué au lieu d’être différé sans fin', () async {
    server.rejectWhen = (op) => op['entity'] == 'patient';
    final id = await patients.create(aicha());
    await db.transaction(() => queue.enqueue(
        entity: 'patient_contact', entityId: SyncQueue.newId(), operation: 'CREATE', payload: {'patient_id': id}, parentEntity: 'patient', parentId: id));

    await engine.run();
    expect((await ops()).last.status, OpStatus.deferred);
    now = now.add(const Duration(minutes: 5));
    await engine.run();
    expect((await ops()).last.status, OpStatus.rejected);
  });

  test('Récupération par pages : curseur enregistré après chaque lot appliqué, reprise exacte', () async {
    for (var i = 0; i < 5; i++) {
      server.serverCreate('patient', 'p$i', {'first_name': 'P$i', 'last_name': 'Test', 'sex': 'M'});
    }
    // Coupure au 2e lot : le 1er lot et son curseur sont gardés.
    final flaky = _FlakyPull(server, failOnCall: 2);
    await engine.dispose();
    engine = SyncEngine(db: db, api: flaky, queue: queue, pullLimit: 2);

    await engine.run();
    expect(engine.state.phase, SyncPhase.offline);
    expect(await db.select(db.patients).get(), hasLength(2));
    expect(await db.readMeta(SyncEngine.cursorKey), '2');

    await engine.run();
    expect(await db.select(db.patients).get(), hasLength(5));
    expect(await db.readMeta(SyncEngine.cursorKey), '5');

    server.serverDelete('patient', 'p0');
    await engine.run();
    expect(await db.select(db.patients).get(), hasLength(4));
  });

  test('Application fermée pendant la synchronisation : reprise au lancement suivant', () async {
    final id = await patients.create(aicha());
    // Envoi commencé puis application tuée : l'opération est restée SENDING.
    await queue.markSending((await ops()).map((o) => o.localId));

    final restarted = newEngine();
    await restarted.run();
    expect((await ops()).single.status, OpStatus.applied);
    expect(server.get('patient', id), isNotNull);
    await restarted.dispose();
  });

  test('Téléphone redémarré avant la synchronisation : la file survit (base sur disque)', () async {
    final dir = await Directory.systemTemp.createTemp('vsh_sync_');
    final file = File('${dir.path}/vsh.db');
    try {
      var diskDb = AppDatabase(NativeDatabase(file));
      final id = await PatientRepository(diskDb).create(aicha());
      await diskDb.close(); // arrêt du téléphone

      diskDb = AppDatabase(NativeDatabase(file));
      final diskEngine = SyncEngine(db: diskDb, api: server);
      await diskEngine.run();
      expect(server.get('patient', id), isNotNull);
      expect((await diskDb.select(diskDb.syncOperations).getSingle()).status, OpStatus.applied);
      await diskEngine.dispose();
      await diskDb.close();
    } finally {
      await dir.delete(recursive: true);
    }
  });

  test('Purge des opérations terminées après la durée de conservation', () async {
    await patients.create(aicha());
    await engine.run();
    now = now.add(const Duration(days: 31));
    expect(await queue.purgeFinished(const Duration(days: 30)), 1);
    expect(await ops(), isEmpty);
  });
}

class _FlakyPull implements SyncApi {
  _FlakyPull(this.inner, {required this.failOnCall});

  final FakeSyncServer inner;
  final int failOnCall;
  int _calls = 0;

  @override
  Future<List<Map<String, dynamic>>> push(List<Map<String, dynamic>> operations) => inner.push(operations);

  @override
  Future<PullPage> pull(int cursor, {int limit = 200}) {
    if (++_calls == failOnCall) throw SyncNetworkException();
    return inner.pull(cursor, limit: limit);
  }
}
