import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../database/app_database.dart';
import '../synchronization/sync_providers.dart';
import 'local_alerts.dart';
import 'notifications_screen.dart';
import 'push_service.dart';

/// Repère, parmi les notifications gardées sur le téléphone, celles qui viennent d'arriver et
/// méritent une alerte : non lues et plus récentes que la dernière déjà signalée. La première
/// synchronisation (installation, changement d'utilisateur) ne déclenche aucune alerte, pour ne pas
/// sonner pour tout l'historique.
class NotificationAlertTracker {
  NotificationAlertTracker(this.db);

  final AppDatabase db;
  static const markerKey = 'notifications.alerted_until';

  /// Dates ISO 8601 UTC du serveur (`2026-09-27T18:12:49Z`) : l'ordre des chaînes est l'ordre du temps.
  Future<List<Map<String, dynamic>>> fresh(List<Map<String, dynamic>> items) async {
    String? newest;
    for (final n in items) {
      final at = '${n['created_at'] ?? ''}';
      if (at.isNotEmpty && (newest == null || at.compareTo(newest) > 0)) newest = at;
    }
    if (newest == null) return const [];
    final marker = await db.readMeta(markerKey);
    if (marker != null && newest.compareTo(marker) <= 0) return const [];
    await db.writeMeta(markerKey, newest);
    if (marker == null) return const [];
    return items.where((n) => n['read_at'] == null && '${n['created_at']}'.compareTo(marker) > 0).toList()
      ..sort((a, b) => '${a['created_at']}'.compareTo('${b['created_at']}'));
  }
}

/// Suit les notifications de la session ouverte et affiche les alertes locales.
///
/// Si le push est actif, le système affiche déjà les messages quand l'application est en
/// arrière-plan : l'alerte locale n'est alors montrée qu'application ouverte, où Android n'affiche
/// pas les messages FCM.
class NotificationAlerter with WidgetsBindingObserver {
  NotificationAlerter(this.ref);

  final Ref ref;
  StreamSubscription<List<Map<String, dynamic>>>? _subscription;
  Future<void> _pending = Future.value();
  bool _foreground = true;

  void start() {
    if (_subscription != null) return;
    WidgetsBinding.instance.addObserver(this);
    final tracker = NotificationAlertTracker(ref.read(databaseProvider));
    _subscription = ref.read(recordRepositoryProvider).watchEntity(NotificationActions.entity).listen((items) {
      // Une arrivée à la fois : deux lots rapprochés ne doivent pas sonner deux fois.
      _pending = _pending.then((_) async {
        final fresh = await tracker.fresh(items);
        if (fresh.isEmpty) return;
        if (!_foreground && ref.read(pushServiceProvider).active) return;
        await ref.read(localAlertsProvider).show(fresh, patient: ref.read(sessionProvider).value?.user?.isPatient == true);
      }).catchError((Object _) {});
    });
  }

  Future<void> stop() async {
    WidgetsBinding.instance.removeObserver(this);
    await _subscription?.cancel();
    _subscription = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) => _foreground = state == AppLifecycleState.resumed;
}

final notificationAlerterProvider = Provider<NotificationAlerter>((ref) {
  final alerter = NotificationAlerter(ref);
  ref.onDispose(alerter.stop);
  return alerter;
});
