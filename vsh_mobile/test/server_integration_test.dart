import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/authentication/auth_repository.dart';
import 'package:vsh_mobile/authentication/token_store.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/homecare/homecare_repository.dart';
import 'package:vsh_mobile/homecare/location_service.dart';
import 'package:vsh_mobile/network/api_client.dart';
import 'package:vsh_mobile/network/http_sync_api.dart';
import 'package:vsh_mobile/patients/patient_remote.dart';
import 'package:vsh_mobile/patients/patient_repository.dart';
import 'package:vsh_mobile/reference/reference_repository.dart';
import 'package:vsh_mobile/synchronization/sync_engine.dart';
import 'package:vsh_mobile/synchronization/sync_queue.dart';

/// Contrat réel avec un serveur de **développement** (jamais la production : des dossiers de test
/// sont créés). Lancement :
///
/// ```
/// VSH_IT_BASE_URL=http://localhost:8085/…/api/v1 VSH_IT_PHONE=+227… VSH_IT_PASSWORD=… flutter test test/server_integration_test.dart
/// ```
void main() {
  final env = Platform.environment;
  final baseUrl = env['VSH_IT_BASE_URL'];
  final skip = baseUrl == null ? 'VSH_IT_BASE_URL non défini : test d’intégration ignoré' : null;

  test('connexion, création hors ligne, modification, renvoi, renouvellement du jeton', () async {
    final tokens = _MemoryTokens();
    final lost = <bool>[];
    final client = ApiClient(baseUrl: baseUrl!, tokens: tokens, onSessionLost: ({required deviceRevoked}) async => lost.add(deviceRevoked));
    final user = await AuthRepository(client, tokens).login(env['VSH_IT_PHONE']!, env['VSH_IT_PASSWORD']!);
    expect(user.isStaff, isTrue);

    final db = AppDatabase(NativeDatabase.memory());
    final engine = SyncEngine(db: db, api: HttpSyncApi(client));
    final patients = PatientRepository(db, queue: engine.queue);
    final stamp = DateTime.now().millisecondsSinceEpoch % 100000;
    Map<String, dynamic> identity(String birth) => {'first_name': 'Mobile$stamp', 'last_name': 'TEST-Mobile', 'sex': 'F', 'birth_date': birth};

    final id = await patients.create(identity('1990-05-01'));
    var report = await engine.run();
    expect(engine.state.phase, SyncPhase.idle, reason: engine.state.message);
    expect(report.applied, 1);
    var row = await (db.select(db.patients)..where((t) => t.id.equals(id))).getSingle();
    expect(row.fileNumber, startsWith('VSH-'));
    expect(row.version, greaterThanOrEqualTo(1));

    await patients.update(id, identity('1990-05-02'));
    report = await engine.run();
    expect(report.applied, 1, reason: (await db.select(db.syncOperations).get()).last.lastError);
    row = await (db.select(db.patients)..where((t) => t.id.equals(id))).getSingle();
    expect(row.birthDate, '1990-05-02');

    // Renvoi d'une opération déjà appliquée : reconnue par son op_id, sans doublon.
    final op = (await db.select(db.syncOperations).get()).first;
    await db.update(db.syncOperations).replace(op.copyWith(status: OpStatus.pending));
    report = await engine.run();
    expect(report.applied, 1);

    // Jeton d'accès expiré : renouvelé automatiquement (le jeton de renouvellement change).
    final before = await tokens.readRefreshToken();
    tokens.accessToken = 'expired';
    await engine.run();
    expect(engine.state.phase, SyncPhase.idle, reason: engine.state.message);
    expect(await tokens.readRefreshToken(), isNot(before));
    expect(lost, isEmpty);

    expect(await db.readMeta(SyncEngine.cursorKey), isNotNull);
    await engine.dispose();
    await db.close();
  }, skip: skip, timeout: const Timeout(Duration(minutes: 2)));

  homecareScenario();
  referenceAndPinScenario();
}

/// Tournée réelle : demande créée par la régulation, puis faite hors ligne par un membre de l'équipe
/// mobile (`VSH_IT_NURSE_PHONE`) pour le patient `VSH_IT_PATIENT_ID`.
void homecareScenario() {
  final env = Platform.environment;
  final baseUrl = env['VSH_IT_BASE_URL'];
  final skip = baseUrl == null || env['VSH_IT_NURSE_PHONE'] == null || env['VSH_IT_PATIENT_ID'] == null
      ? 'VSH_IT_NURSE_PHONE / VSH_IT_PATIENT_ID non définis : tournée réelle ignorée'
      : null;

  test('tournée hors ligne sur le serveur réel : prise en charge → constantes → fin', () async {
    final adminTokens = _MemoryTokens();
    final admin = ApiClient(baseUrl: baseUrl!, tokens: adminTokens);
    await AuthRepository(admin, adminTokens).login(env['VSH_IT_PHONE']!, env['VSH_IT_PASSWORD']!);
    var created = await admin.post('/homecare', {
      'patient_id': env['VSH_IT_PATIENT_ID'],
      'reason': 'Test automatique application mobile',
      'latitude': 13.5116,
      'longitude': 2.1254,
      'gps_accuracy_m': 10,
      'landmark': 'Test',
      'contact_phone': '+22790000099',
    }) as Map;
    if (created['status'] == 'NOUVELLE') {
      created = await admin.post('/homecare/${created['id']}/approve') as Map;
    }
    final visitId = created['id'] as String;
    expect(created['status'], 'EN_ATTENTE');

    final tokens = _MemoryTokens();
    final client = ApiClient(baseUrl: baseUrl, tokens: tokens);
    await AuthRepository(client, tokens).login(env['VSH_IT_NURSE_PHONE']!, env['VSH_IT_PASSWORD']!);
    final db = AppDatabase(NativeDatabase.memory());
    final engine = SyncEngine(db: db, api: HttpSyncApi(client));
    final homecare = HomecareRepository(db, queue: engine.queue);
    await engine.run();
    expect((await homecare.watchVisit(visitId).first)?.status, 'EN_ATTENTE', reason: 'visible dans la file de l’infirmière');

    // Hors ligne : toute la tournée est saisie avant le moindre envoi.
    final at = GeoFix(13.5115, 2.1253, 9, DateTime.now().toUtc());
    await homecare.act(visitId, 'accept');
    await homecare.act(visitId, 'depart', fix: GeoFix(13.50, 2.10, 15, DateTime.now().toUtc()));
    await homecare.track(visitId, [GeoFix(13.505, 2.11, 12, DateTime.now().toUtc())]);
    await homecare.act(visitId, 'arrive', fix: at);
    final consultationId = await homecare.act(visitId, 'start', fix: at);
    await homecare.addVitals(consultationId: consultationId!, patientId: env['VSH_IT_PATIENT_ID']!, measures: {'temperature_c': 37.2, 'pulse_bpm': 80});
    await homecare.act(visitId, 'complete', fix: at);

    final report = await engine.run();
    final failures = (await db.select(db.syncOperations).get()).where((o) => o.status != OpStatus.applied).map((o) => '${o.entity}/${o.action}: ${o.lastError}');
    expect(failures, isEmpty);
    expect(report.applied, 7, reason: 'accept, depart, trajet, arrive, start, constantes, complete');

    final server = await admin.get('/homecare/$visitId') as Map;
    expect(server['status'], 'TERMINEE');
    expect(server['consultation_id'], consultationId);
    expect((server['geo'] as Map)['arrival'], isNotNull);
    final visit = (await homecare.watchVisit(visitId).first)!;
    expect(visit.status, 'TERMINEE');
    expect(visit.history.where((h) => h['pending'] == true), isEmpty);
    await engine.dispose();
    await db.close();
  }, skip: skip, timeout: const Timeout(Duration(minutes: 3)));
}

/// Référentiels hors ligne, recherche en ligne et épinglage d'un dossier hors du périmètre.
void referenceAndPinScenario() {
  final env = Platform.environment;
  final baseUrl = env['VSH_IT_BASE_URL'];
  final skip = baseUrl == null ? 'VSH_IT_BASE_URL non défini' : null;

  test('référentiels, recherche serveur et épinglage', () async {
    final tokens = _MemoryTokens();
    final client = ApiClient(baseUrl: baseUrl!, tokens: tokens);
    await AuthRepository(client, tokens).login(env['VSH_IT_PHONE']!, env['VSH_IT_PASSWORD']!);
    final db = AppDatabase(NativeDatabase.memory());

    final reference = ReferenceRepository(db, client);
    await reference.refresh();
    expect(await db.readMeta('ref.generated_at'), isNotNull);
    expect(await reference.number('geo.arrival_radius_m', -1), greaterThan(0));
    await reference.refresh(); // incrémental (since)

    final remote = PatientRemote(client);
    final found = await remote.search('TEST');
    expect(found, isNotEmpty);
    final target = found.first['id'] as String;
    await remote.pin(target);
    expect(await remote.pinned(), contains(target));

    final engine = SyncEngine(db: db, api: HttpSyncApi(client));
    await engine.run();
    expect(await (db.select(db.patients)..where((t) => t.id.equals(target))).getSingleOrNull(), isNotNull,
        reason: 'dossier épinglé reçu à la synchronisation');
    await remote.unpin(target);
    await engine.dispose();
    await db.close();
  }, skip: skip, timeout: const Timeout(Duration(minutes: 2)));
}

/// Stockage en mémoire (le stockage sécurisé du téléphone n'existe pas dans les tests).
class _MemoryTokens extends TokenStore {
  String? _refresh;
  Map<String, dynamic>? _user;
  final _device = SyncQueue.newId();

  @override
  Future<String?> readRefreshToken() async => _refresh;

  @override
  Future<void> saveTokens(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String?;
    _refresh = tokens['refresh_token'] as String?;
  }

  @override
  Future<void> saveUser(Map<String, dynamic> user) async => _user = user;

  @override
  Future<Map<String, dynamic>?> readUser() async => _user;

  @override
  Future<void> clearSession() async {
    accessToken = null;
    _refresh = null;
  }

  @override
  Future<String> deviceUuid() async => _device;
}
