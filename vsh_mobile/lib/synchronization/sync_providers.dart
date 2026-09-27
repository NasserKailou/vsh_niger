import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../authentication/auth_repository.dart';
import '../authentication/session_controller.dart';
import '../authentication/token_store.dart';
import '../config/env.dart';
import '../database/app_database.dart';
import '../database/record_repository.dart';
import '../homecare/homecare_repository.dart';
import '../network/api_client.dart';
import '../network/http_sync_api.dart';
import '../patient_space/patient_space_repository.dart';
import '../patients/patient_remote.dart';
import '../patients/patient_repository.dart';
import '../reference/reference_repository.dart';
import 'sync_engine.dart';
import 'sync_queue.dart';

/// Fournis au lancement (`main.dart`) une fois la base chiffrée ouverte.
final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());
final databaseProvider = Provider<AppDatabase>((ref) => throw UnimplementedError('Base ouverte dans main()'));

final sessionProvider = AsyncNotifierProvider<SessionController, SessionState>(SessionController.new);

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(
      baseUrl: Env.apiBaseUrl,
      tokens: ref.read(tokenStoreProvider),
      onSessionLost: ({required bool deviceRevoked}) =>
          ref.read(sessionProvider.notifier).sessionLost(deviceRevoked: deviceRevoked),
    ));

final authRepositoryProvider = Provider<AuthRepository>((ref) => AuthRepository(ref.read(apiClientProvider), ref.read(tokenStoreProvider)));

final syncQueueProvider = Provider<SyncQueue>((ref) => SyncQueue(ref.watch(databaseProvider)));

final syncEngineProvider = Provider<SyncEngine>((ref) {
  final engine = SyncEngine(
    db: ref.watch(databaseProvider),
    api: HttpSyncApi(ref.read(apiClientProvider)),
    queue: ref.watch(syncQueueProvider),
    // L'effacement est fait par la session (même traitement que via le client HTTP).
    onDeviceRevoked: () => ref.read(sessionProvider.notifier).sessionLost(deviceRevoked: true),
    // Patients : envoi seulement, leurs données passent par l'espace patient.
    pullEnabled: () => ref.read(sessionProvider).value?.user?.isPatient != true,
  );
  ref.onDispose(engine.dispose);
  return engine;
});

final recordRepositoryProvider = Provider<RecordRepository>((ref) => RecordRepository(ref.watch(databaseProvider), queue: ref.watch(syncQueueProvider)));

/// Sans adresse de serveur configurée, les référentiels déjà enregistrés restent lisibles.
final referenceRepositoryProvider = Provider<ReferenceRepository>(
    (ref) => ReferenceRepository(ref.watch(databaseProvider), Env.isConfigured ? ref.read(apiClientProvider) : null));

final patientSpaceProvider = Provider<PatientSpaceRepository>((ref) => PatientSpaceRepository(
      ref.watch(databaseProvider),
      Env.isConfigured ? ApiClientPatientApi(ref.read(apiClientProvider)) : null,
      queue: ref.watch(syncQueueProvider),
    ));

final patientRemoteProvider =Provider<PatientRemote>((ref) => PatientRemote(ref.read(apiClientProvider)));

final homecareRepositoryProvider = Provider<HomecareRepository>((ref) => HomecareRepository(ref.watch(databaseProvider), queue: ref.watch(syncQueueProvider)));

final patientRepositoryProvider = Provider<PatientRepository>((ref) => PatientRepository(ref.watch(databaseProvider), queue: ref.watch(syncQueueProvider)));

final syncStateProvider = StreamProvider<SyncState>((ref) async* {
  final engine = ref.watch(syncEngineProvider);
  yield engine.state;
  yield* engine.states;
});

final queueCountsProvider = StreamProvider<QueueCounts>((ref) => ref.watch(syncQueueProvider).watchCounts());

/// Réseau disponible (au sens du téléphone ; le serveur peut rester injoignable).
final connectivityProvider = StreamProvider<bool>((ref) async* {
  final connectivity = Connectivity();
  bool online(List<ConnectivityResult> results) => results.any((r) => r != ConnectivityResult.none);
  yield online(await connectivity.checkConnectivity());
  yield* connectivity.onConnectivityChanged.map(online).distinct();
});

/// Les trois états montrés à l'utilisateur (cahier des charges).
enum SyncIndicator { synced, syncing, offline }

class SyncOverview {
  const SyncOverview(this.indicator, this.label, {this.outgoing = 0, this.attention = 0, this.lastSuccessAt, this.detail});

  final SyncIndicator indicator;
  final String label;
  final int outgoing;
  final int attention;
  final DateTime? lastSuccessAt;
  final String? detail;
}

final syncOverviewProvider = Provider<SyncOverview>((ref) {
  final online = ref.watch(connectivityProvider).value ?? true;
  final sync = ref.watch(syncStateProvider).value ?? const SyncState();
  final counts = ref.watch(queueCountsProvider).value ?? const QueueCounts(0, 0);
  final common = (outgoing: counts.outgoing, attention: counts.attention, last: sync.lastSuccessAt);

  if (!online || sync.phase == SyncPhase.offline) {
    return SyncOverview(SyncIndicator.offline, 'Hors connexion',
        outgoing: common.outgoing, attention: common.attention, lastSuccessAt: common.last,
        detail: counts.outgoing > 0 ? '${counts.outgoing} modification(s) en attente, envoyées au retour du réseau.' : null);
  }
  if (sync.phase == SyncPhase.sessionExpired) {
    return SyncOverview(SyncIndicator.offline, 'Session expirée',
        outgoing: common.outgoing, attention: common.attention, lastSuccessAt: common.last, detail: sync.message);
  }
  if (sync.phase == SyncPhase.running || counts.outgoing > 0 || sync.phase == SyncPhase.failed) {
    return SyncOverview(SyncIndicator.syncing, 'Synchronisation en cours',
        outgoing: common.outgoing,
        attention: common.attention,
        lastSuccessAt: common.last,
        detail: sync.phase == SyncPhase.failed ? sync.message : null);
  }
  return SyncOverview(SyncIndicator.synced, 'Synchronisé', attention: common.attention, lastSuccessAt: common.last);
});
