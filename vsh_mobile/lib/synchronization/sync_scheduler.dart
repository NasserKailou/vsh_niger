import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../authentication/session_controller.dart';
import '../config/env.dart';
import 'sync_engine.dart';
import 'sync_providers.dart';

/// Déclenche la synchronisation : au retour du réseau, au retour dans l'application, après une
/// modification locale (regroupée sur quelques secondes) et à intervalle régulier.
class SyncScheduler {
  SyncScheduler(this.ref);

  final Ref ref;
  Timer? _periodic;
  Timer? _debounce;
  AppLifecycleListener? _lifecycle;
  bool _active = false;

  void start() {
    if (_active) return;
    _active = true;
    ref.listen<AsyncValue<bool>>(connectivityProvider, (previous, next) {
      if (next.value == true && previous?.value != true) now();
    });
    _lifecycle = AppLifecycleListener(onResume: now);
    _periodic = Timer.periodic(Env.syncInterval, (_) => now());
    unawaited(ref.read(syncQueueProvider).purgeFinished(Env.outboxRetention));
    now();
  }

  /// Après une écriture locale : envoi groupé quelques secondes plus tard.
  void poke() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(seconds: 3), now);
  }

  void now() {
    final session = ref.read(sessionProvider).value;
    if (!_active || session?.status != SessionStatus.signedIn) return;
    if (ref.read(connectivityProvider).value == false) return;
    unawaited(_runAndRefresh());
  }

  /// Après une synchronisation réussie : mise à jour des référentiels (incrémentale) et, pour un
  /// patient, de son espace (rendez-vous, visites, résultats, documents, notifications).
  Future<void> _runAndRefresh() async {
    final engine = ref.read(syncEngineProvider);
    await engine.run();
    if (engine.state.phase != SyncPhase.idle) return;
    try {
      await ref.read(referenceRepositoryProvider).refresh();
    } catch (_) {
      // Référentiels : nouvel essai à la synchronisation suivante, sans gêner l'utilisateur.
    }
    if (ref.read(sessionProvider).value?.user?.isPatient == true) {
      try {
        await ref.read(patientSpaceProvider).refresh();
      } catch (_) {
        // Hors ligne ou serveur indisponible : les données déjà enregistrées restent affichées.
      }
    }
  }

  void stop() {
    _active = false;
    _periodic?.cancel();
    _debounce?.cancel();
    _lifecycle?.dispose();
  }
}

final syncSchedulerProvider = Provider<SyncScheduler>((ref) {
  final scheduler = SyncScheduler(ref);
  ref.onDispose(scheduler.stop);
  return scheduler;
});
