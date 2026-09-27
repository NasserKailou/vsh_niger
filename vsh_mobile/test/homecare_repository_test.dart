import 'package:drift/drift.dart' show OrderingTerm, driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/homecare/homecare_repository.dart';
import 'package:vsh_mobile/homecare/location_service.dart';
import 'package:vsh_mobile/synchronization/sync_engine.dart';
import 'package:vsh_mobile/synchronization/sync_queue.dart';

import 'fake_sync_api.dart';

/// Tournée à domicile entièrement hors ligne, puis synchronisée (docs/11 §4).
void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  late AppDatabase db;
  late FakeSyncServer server;
  late DateTime now;
  late SyncQueue queue;
  late SyncEngine engine;
  late HomecareRepository homecare;

  GeoFix fix(double lat, double lng) => GeoFix(lat, lng, 8, now);

  setUp(() async {
    db = AppDatabase(NativeDatabase.memory());
    server = FakeSyncServer();
    now = DateTime.utc(2026, 9, 27, 8);
    queue = SyncQueue(db, clock: () => now);
    engine = SyncEngine(db: db, api: server, queue: queue);
    homecare = HomecareRepository(db, queue: queue, clock: () => now);
    server.serverCreate('patient', 'p1', {'first_name': 'Aïcha', 'last_name': 'TEST', 'sex': 'F', 'status': 'ACTIVE'});
    server.serverCreate('homecare_request', 'v1', {
      'status': 'EN_ATTENTE',
      'patient_id': 'p1',
      'patient': {'id': 'p1', 'name': 'Aïcha TEST', 'file_number': 'VSH-2026-000009'},
      'reason': 'Pansement',
      'urgency': 'NORMALE',
      'location': {'latitude': 13.5116, 'longitude': 2.1254, 'accuracy_m': 12.0},
    });
    await engine.run();
  });

  tearDown(() async {
    await engine.dispose();
    await db.close();
  });

  Future<List<SyncOperation>> ops() => (db.select(db.syncOperations)..orderBy([(t) => OrderingTerm.asc(t.localId)])).get();
  Future<String> localStatus() async => (await homecare.watchVisit('v1').first)!.status;

  test('tournée complète hors ligne, puis envoi dans l’ordre', () async {
    await homecare.act('v1', 'accept');
    await homecare.act('v1', 'depart', fix: fix(13.50, 2.10));
    await homecare.track('v1', [fix(13.505, 2.11), fix(0, 0), fix(13.51, 2.12)]);
    await homecare.act('v1', 'arrive', fix: fix(13.5115, 2.1253), comment: 'Portail bleu');
    final consultationId = await homecare.act('v1', 'start');
    await homecare.addVitals(consultationId: consultationId!, patientId: 'p1', measures: {'temperature_c': 37.8, 'pulse_bpm': 88, 'spo2_percent': null});
    await homecare.addNote(consultationId: consultationId, patientId: 'p1', content: 'Plaie propre.');
    await homecare.act('v1', 'complete');

    // Affichage immédiat sur le téléphone.
    expect(await localStatus(), 'TERMINEE');
    final visit = (await homecare.watchVisit('v1').first)!;
    expect(visit.history.where((h) => h['pending'] == true), hasLength(5));
    expect(visit.consultationId, consultationId);
    expect(await homecare.watchVitals(consultationId).first, hasLength(1));

    // Chaîne : chaque action dépend de la précédente ; le trajet n'en fait pas partie.
    final queued = await ops();
    String opOf(String action) => queued.firstWhere((o) => o.action == action).opId;
    expect(queued.firstWhere((o) => o.action == 'depart').dependsOn, opOf('accept'));
    expect(queued.firstWhere((o) => o.action == 'track').dependsOn, opOf('depart'));
    expect(queued.firstWhere((o) => o.action == 'arrive').dependsOn, opOf('depart'));
    expect(queued.firstWhere((o) => o.entity == 'vital_sign').dependsOn, opOf('start'));
    expect(queued.firstWhere((o) => o.entity == 'consultation_note').dependsOn, opOf('start'));
    expect(queued.firstWhere((o) => o.action == 'arrive').payload, contains('"comment":"Portail bleu"'));
    expect(queued.firstWhere((o) => o.action == 'arrive').payload, contains('"latitude":13.5115'));

    final report = await engine.run();
    expect(report.rejected, 0);
    expect((await ops()).map((o) => o.status), everyElement(OpStatus.applied));
    expect(server.get('homecare_request', 'v1')!['status'], 'TERMINEE');
    expect(server.tracks['v1'], hasLength(2), reason: 'point (0,0) écarté');
    expect(server.get('vital_sign', (await ops()).firstWhere((o) => o.entity == 'vital_sign').entityId)!['temperature_c'], 37.8);
    expect(await localStatus(), 'TERMINEE');
    expect((await homecare.watchVisit('v1').first)!.history.where((h) => h['pending'] == true), isEmpty,
        reason: 'état serveur appliqué après envoi');
  });

  test('visite prise entre-temps par une autre équipe : refus, puis abandon en cascade', () async {
    server.serverEdit('homecare_request', 'v1', {'status': 'PRISE_EN_CHARGE', 'team': {'label': 'Équipe B'}});
    await homecare.act('v1', 'accept');
    await homecare.act('v1', 'depart', fix: fix(13.5, 2.1));

    await engine.run();
    var queued = await ops();
    expect(queued.first.status, OpStatus.rejected);
    expect(queued.first.lastError, contains('Action impossible'));
    expect(queued.last.status, OpStatus.deferred);

    now = now.add(const Duration(minutes: 1));
    await engine.run();
    queued = await ops();
    expect(queued.last.status, OpStatus.rejected, reason: 'bloquée par le refus de la précédente');

    await engine.dismiss(queued.first);
    queued = await ops();
    expect(queued.map((o) => o.status), everyElement(OpStatus.discarded));
    final visit = (await homecare.watchVisit('v1').first)!;
    expect(visit.status, 'PRISE_EN_CHARGE');
    expect(visit.teamLabel, 'Équipe B', reason: 'état du serveur mis de côté puis appliqué');
  });

  test('abandon sans état serveur connu : retour à l’état d’avant l’action', () async {
    await homecare.act('v1', 'accept');
    server.rejectWhen = (op) => op['action'] == 'accept';
    await engine.run();
    expect(await localStatus(), 'PRISE_EN_CHARGE', reason: 'saisie visible jusqu’à la décision');

    await engine.dismiss((await ops()).single);
    expect(await localStatus(), 'EN_ATTENTE');
  });

  test('horodatage à l’heure du serveur (téléphone en avance)', () async {
    await db.writeMeta(SyncEngine.clockOffsetKey, '${-const Duration(hours: 1).inMilliseconds}');
    await homecare.act('v1', 'accept');
    expect((await ops()).single.payload, contains('"at":"2026-09-27T07:00:00.000Z"'));
  });

  test('écart d’horloge mesuré à la récupération', () async {
    expect(await db.readMeta(SyncEngine.clockOffsetKey), isNull, reason: 'le faux serveur ne donne pas d’heure');
    await db.writeMeta(SyncEngine.clockOffsetKey, '120000');
    expect(await SyncEngine.serverNow(db, () => now), now.add(const Duration(minutes: 2)));
  });

  test('contrôles locaux : étape, motif, mesure', () async {
    expect(() => homecare.act('v1', 'depart'), throwsStateError);
    expect(() => homecare.act('v1', 'fail', reason: ' '), throwsArgumentError);
    expect(() => homecare.addVitals(consultationId: 'c', patientId: 'p1', measures: {'pulse_bpm': null}), throwsArgumentError);
    expect(await ops(), isEmpty);
  });
}
