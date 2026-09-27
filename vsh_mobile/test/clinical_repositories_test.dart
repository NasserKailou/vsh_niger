import 'package:drift/drift.dart' show driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/appointments/agenda_screen.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/database/record_repository.dart';
import 'package:vsh_mobile/homecare/homecare_repository.dart';
import 'package:vsh_mobile/notifications/notifications_screen.dart';
import 'package:vsh_mobile/reference/reference_repository.dart';
import 'package:vsh_mobile/synchronization/sync_engine.dart';
import 'package:vsh_mobile/synchronization/sync_queue.dart';
import 'package:vsh_mobile/treatments/treatments_screen.dart';

import 'fake_sync_api.dart';

/// Soins, rendez-vous, notifications et référentiels hors ligne (étape 12).
void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  late AppDatabase db;
  late FakeSyncServer server;
  late DateTime now;
  late SyncQueue queue;
  late SyncEngine engine;
  late RecordRepository records;

  setUp(() async {
    db = AppDatabase(NativeDatabase.memory());
    server = FakeSyncServer();
    now = DateTime.utc(2026, 9, 27, 8);
    queue = SyncQueue(db, clock: () => now);
    engine = SyncEngine(db: db, api: server, queue: queue);
    records = RecordRepository(db, queue: queue, clock: () => now);
    server.serverCreate('patient', 'p1', {'first_name': 'Aïcha', 'last_name': 'TEST', 'sex': 'F', 'status': 'ACTIVE'});
    server.serverCreate('treatment', 't1', {
      'patient_id': 'p1',
      'status': 'PLANIFIE',
      'scheduled_for': '2026-09-27T09:00:00Z',
      'treatment_type': {'id': 'tt1', 'code': 'PANS', 'label': 'Pansement'},
    });
    server.serverCreate('appointment', 'a1', {'patient_id': 'p1', 'status': 'CONFIRME', 'scheduled_start': '2026-09-27T10:00:00Z'});
    server.serverCreate('notification', 'n1', {'title': 'Visite affectée', 'read_at': null, 'created_at': '2026-09-27T07:00:00Z'});
    await engine.run();
  });

  tearDown(() async {
    await engine.dispose();
    await db.close();
  });

  Future<Map<String, dynamic>> local(String entity, String id) async => (await records.store.read(entity, id))!;

  test('soin programmé fait hors ligne : affiché tout de suite, appliqué à l’envoi', () async {
    await TreatmentActions(records).perform('t1', observations: 'Plaie propre', performerName: 'Aïcha Infirmière');
    expect((await local('treatment', 't1'))['status'], 'REALISE');
    expect((await local('treatment', 't1'))['performed_by'], {'name': 'Aïcha Infirmière'});

    await engine.run();
    expect(server.get('treatment', 't1')!['status'], 'REALISE');
    expect(server.get('treatment', 't1')!['observations'], 'Plaie propre');
    expect(server.get('treatment', 't1')!['performed_at'], '2026-09-27T08:00:00.000Z');
  });

  test('soin annulé entre-temps par la clinique : refus, puis état de la clinique', () async {
    server.serverEdit('treatment', 't1', {'status': 'ANNULE', 'cancel_reason': 'Patient hospitalisé'});
    await TreatmentActions(records).perform('t1');
    await engine.run();
    final op = (await db.select(db.syncOperations).get()).single;
    expect(op.status, OpStatus.rejected);

    await engine.dismiss(op);
    expect((await local('treatment', 't1'))['status'], 'ANNULE');
    expect((await local('treatment', 't1'))['cancel_reason'], 'Patient hospitalisé');
  });

  test('soin réalisé pendant une visite : dépend du démarrage fait hors ligne', () async {
    server.serverCreate('homecare_request', 'v1', {'status': 'SUR_PLACE', 'patient_id': 'p1', 'patient': {'name': 'Aïcha TEST'}});
    await engine.run();
    final homecare = HomecareRepository(db, queue: queue, clock: () => now);
    final consultationId = await homecare.act('v1', 'start');
    final id = await TreatmentActions(records).recordDone(
      patientId: 'p1',
      type: {'id': 'tt1', 'code': 'PANS', 'label': 'Pansement'},
      consultationId: consultationId,
      dependsOnOp: await homecare.pendingStart(consultationId!),
    );
    final op = (await db.select(db.syncOperations).get()).last;
    expect(op.dependsOn, isNotNull);
    expect(op.payload, contains('"status":"REALISE"'));
    expect((await local('treatment', id))['treatment_type'], containsPair('label', 'Pansement'));

    await engine.run();
    expect(server.get('treatment', id)!['consultation_id'], consultationId);
    expect((await db.select(db.syncOperations).get()).map((o) => o.status), everyElement(OpStatus.applied));
  });

  test('rendez-vous : arrivée et absence', () async {
    server.serverCreate('appointment', 'a2', {'patient_id': 'p1', 'status': 'DEPLACE', 'scheduled_start': '2026-09-27T11:00:00Z'});
    await engine.run();
    final actions = AppointmentActions(records);
    await actions.checkIn('a1');
    await actions.noShow('a2');
    expect((await local('appointment', 'a1'))['checked_in_at'], isNotNull);
    expect((await local('appointment', 'a2'))['status'], 'ABSENT');

    await engine.run();
    expect(server.get('appointment', 'a1')!['checked_in_at'], isNotNull);
    expect(server.get('appointment', 'a2')!['status'], 'ABSENT');
  });

  test('notification lue hors ligne : une seule modification, même si relue', () async {
    final actions = NotificationActions(records);
    await actions.markRead('n1');
    await actions.markRead('n1');
    final ops = await db.select(db.syncOperations).get();
    expect(ops, hasLength(1));
    expect(ops.single.operation, 'UPDATE');
    expect(ops.single.payload, contains('read_at'));

    await engine.run();
    expect(server.get('notification', 'n1')!['read_at'], isNotNull);
  });

  test('référentiels : lot complet, puis mise à jour incrémentale, paramètres', () async {
    final reference = ReferenceRepository(db);
    await reference.apply({
      'full': true,
      'generated_at': '2026-09-27T08:00:00Z',
      'settings': {'geo.arrival_radius_m': 250, 'app.clinic_name': 'Clinique'},
      'treatment_types': [
        {'id': 'a', 'code': 'INJ', 'label': 'Injection', 'active': true},
        {'id': 'b', 'code': 'PANS', 'label': 'Pansement', 'active': true},
      ],
    });
    await reference.apply({
      'full': false,
      'generated_at': '2026-09-27T09:00:00Z',
      'treatment_types': [
        {'id': 'b', 'code': 'PANS', 'label': 'Pansement simple', 'active': true},
        {'id': 'c', 'code': 'OLD', 'label': 'Ancien soin', 'active': false},
      ],
    });
    final active = await reference.active('treatment_types');
    expect(active.map((t) => t['label']), ['Injection', 'Pansement simple']);
    expect(await reference.number('geo.arrival_radius_m', 300), 250);
    expect(await reference.number('geo.low_accuracy_m', 100), 100, reason: 'valeur par défaut');
    expect(await db.readMeta('ref.generated_at'), '2026-09-27T09:00:00Z');

    await reference.apply({'full': true, 'treatment_types': <Map<String, dynamic>>[]});
    expect(await reference.list('treatment_types'), isEmpty, reason: 'un lot complet remplace');
  });
}
