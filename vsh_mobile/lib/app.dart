import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'authentication/session_controller.dart';
import 'notifications/local_alerts.dart';
import 'notifications/notification_alerts.dart';
import 'notifications/push_service.dart';
import 'patient_space/patient_space_repository.dart';
import 'routing/app_router.dart';
import 'synchronization/sync_providers.dart';
import 'synchronization/sync_scheduler.dart';
import 'theme/app_theme.dart';

class VshApp extends ConsumerStatefulWidget {
  const VshApp({super.key});

  @override
  ConsumerState<VshApp> createState() => _VshAppState();
}

class _VshAppState extends ConsumerState<VshApp> {
  @override
  void initState() {
    super.initState();
    // Synchronisation automatique dès que la session est ouverte (au lancement ou après connexion).
    ref.listenManual(sessionProvider, (previous, next) {
      final wasSignedIn = previous?.value?.status == SessionStatus.signedIn;
      if (next.value?.status != SessionStatus.signedIn) {
        if (wasSignedIn) _stopNotifications();
        return;
      }
      final scheduler = ref.read(syncSchedulerProvider)..start();
      if (!wasSignedIn) {
        scheduler.now();
        ref.read(sessionProvider.notifier).refreshProfile();
        _startNotifications();
      }
    }, fireImmediately: true);
  }

  /// Alertes locales à l'arrivée des notifications, et push FCM s'il est configuré (D-011).
  Future<void> _startNotifications() async {
    ref.read(notificationAlerterProvider).start();
    try {
      await ref.read(localAlertsProvider).init(_open);
    } catch (_) {
      // Alertes refusées ou indisponibles : les notifications restent dans l'application.
    }
    await ref.read(pushServiceProvider).start(_open);
  }

  Future<void> _stopNotifications() async {
    await ref.read(notificationAlerterProvider).stop();
    await ref.read(pushServiceProvider).stop();
    await ref.read(localAlertsProvider).clear();
    // Espace patient : aucun PDF (ordonnance, facture) ne reste sur le téléphone.
    await PatientSpaceRepository.clearDocuments();
  }

  void _open(String route) => openLocation(ref.read(routerProvider), route);

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Vision Homecare',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      locale: const Locale('fr'),
      supportedLocales: const [Locale('fr')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      routerConfig: ref.watch(routerProvider),
    );
  }
}
