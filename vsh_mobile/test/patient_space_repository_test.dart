import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/network/api_client.dart';
import 'package:vsh_mobile/patient_space/patient_space_repository.dart';

/// Serveur simulé : réponses des routes `/me/patients/…` du portail patient.
class FakePatientApi implements PatientApi {
  final responses = <String, Object>{};
  final posts = <(String, Object?)>[];
  final gets = <String>[];
  bool offline = false;

  @override
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async {
    gets.add(path);
    if (offline) throw ApiException('Pas de connexion.');
    return responses[path] ?? const [];
  }

  @override
  Future<dynamic> post(String path, [Object? body]) async {
    if (offline) throw ApiException('Pas de connexion.');
    posts.add((path, body));
    return const {};
  }

  @override
  Future<List<int>> bytes(String path) async => const [37, 80, 68, 70];
}

void main() {
  late AppDatabase db;
  late FakePatientApi api;
  late PatientSpaceRepository repo;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    api = FakePatientApi();
    repo = PatientSpaceRepository(db, api);
    api.responses['/me/patients'] = [
      {'id': 'p-mere', 'first_name': 'Aïcha', 'last_name': 'Moussa', 'status': 'ACTIVE'},
      {'id': 'p-enfant', 'first_name': 'Ali', 'last_name': 'Moussa', 'status': 'PENDING'},
    ];
    api.responses['/me/patients/p-mere/appointments'] = [
      {'id': 'r-1', 'status': 'CONFIRME', 'scheduled_start': '2026-10-02T08:00:00Z', 'service': {'label': 'Médecine générale'}},
    ];
    api.responses['/me/patients/p-mere/examinations'] = [
      {'id': 'e-1', 'validated_at': '2026-09-20T10:00:00Z', 'examination_type': {'label': 'Glycémie'}, 'results': []},
    ];
    api.responses['/me/patients/p-enfant/homecare'] = [
      {'id': 'v-1', 'status': 'EN_ROUTE', 'reason': 'Pansement', 'created_at': '2026-09-27T07:00:00Z'},
    ];
    api.responses['/notifications'] = [
      {'id': 'n-1', 'type': 'APPOINTMENT_STATUS', 'title': 'Rendez-vous confirmé', 'created_at': '2026-09-27T08:00:00Z', 'read_at': null},
    ];
  });

  tearDown(() => db.close());

  test('la mise à jour garde chaque dossier de la famille et ses éléments, pour une lecture hors ligne', () async {
    await repo.refresh();

    expect((await repo.watch(PatientSpaceRepository.patients).first).map((p) => p['id']), containsAll(['p-mere', 'p-enfant']));
    expect((await repo.watch(PatientSpaceRepository.appointments, patientId: 'p-mere').first).single['id'], 'r-1');
    expect(await repo.watch(PatientSpaceRepository.appointments, patientId: 'p-enfant').first, isEmpty);
    expect((await repo.watch(PatientSpaceRepository.visits, patientId: 'p-enfant').first).single['status'], 'EN_ROUTE');
    expect((await repo.watch(PatientSpaceRepository.examinations, patientId: 'p-mere').first).single['id'], 'e-1');
    expect((await repo.watch(PatientSpaceRepository.notification).first).single['title'], 'Rendez-vous confirmé');
    expect(await repo.lastRefresh(), isNotNull);

    // Hors ligne : la mise à jour échoue, les données déjà enregistrées restent.
    api.offline = true;
    await expectLater(repo.refresh(), throwsA(isA<ApiException>()));
    expect(await repo.watch(PatientSpaceRepository.appointments, patientId: 'p-mere').first, hasLength(1));
  });

  test('un élément disparu côté serveur disparaît aussi du téléphone', () async {
    await repo.refresh();
    api.responses['/me/patients'] = [
      {'id': 'p-mere', 'first_name': 'Aïcha', 'last_name': 'Moussa', 'status': 'ACTIVE'},
    ];
    api.responses['/me/patients/p-mere/appointments'] = const [];
    await repo.refresh();

    expect((await repo.watch(PatientSpaceRepository.patients).first).map((p) => p['id']), ['p-mere']);
    expect(await repo.watch(PatientSpaceRepository.appointments).first, isEmpty);
    expect(await repo.watch(PatientSpaceRepository.visits).first, isEmpty, reason: 'dossier de l’enfant détaché du compte');
  });

  test('seules les notifications les plus récentes sont conservées', () async {
    api.responses['/notifications'] = [
      for (var i = 0; i < PatientSpaceRepository.keptNotifications + 10; i++)
        {'id': 'n-$i', 'title': 'N $i', 'created_at': '2026-09-${(i % 28 + 1).toString().padLeft(2, '0')}T${(i % 24).toString().padLeft(2, '0')}:00:00Z'},
    ];
    await repo.refresh();
    expect(await repo.watch(PatientSpaceRepository.notification).first, hasLength(PatientSpaceRepository.keptNotifications));
  });

  test('les demandes partent au serveur puis la liste est relue', () async {
    await repo.requestAppointment(patientId: 'p-mere', serviceId: 's-1', start: '2026-10-05T09:00:00Z', reason: '  ');
    await repo.cancelVisit('v-1', reason: 'Déjà soigné');
    await repo.requestVisit({'patient_id': 'p-mere', 'reason': 'Injection', 'urgency': 'NORMALE'});

    expect(api.posts.map((p) => p.$1), ['/appointments', '/homecare/v-1/cancel', '/homecare']);
    expect((api.posts.first.$2 as Map)['reason'], isNull, reason: 'motif vide non envoyé');
    expect((api.posts[1].$2 as Map)['reason'], 'Déjà soigné');
    expect(api.gets.where((g) => g == '/me/patients'), hasLength(3), reason: 'relecture après chaque demande');
  });
}
