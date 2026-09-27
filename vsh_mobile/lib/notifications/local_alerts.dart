import 'dart:io';

import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'notifications_screen.dart';

/// Alertes système du téléphone, affichées par l'application elle-même : elles fonctionnent sans
/// push, dès que la synchronisation apporte une nouvelle notification. Le texte vient du serveur et
/// ne contient jamais de donnée médicale.
///
/// Le canal Android est le même que celui des messages FCM envoyés par le serveur (D-011), pour que
/// l'utilisateur règle les deux au même endroit.
class LocalAlerts {
  static const channel = AndroidNotificationChannel(
    'vsh_notifications',
    'Notifications Vision Homecare',
    description: 'Visites, rendez-vous, résultats et factures (sans détail médical)',
    importance: Importance.high,
  );

  /// Au-delà, une seule alerte résume l'arrivée (retour de réseau après une longue coupure).
  static const maxSeparateAlerts = 3;

  final _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;

  /// Prépare les alertes et demande l'autorisation (Android 13 et plus, iOS). [onOpen] reçoit l'écran
  /// à ouvrir quand l'utilisateur touche une alerte, y compris celle qui a lancé l'application.
  Future<void> init(void Function(String route) onOpen) async {
    if (_ready || !(Platform.isAndroid || Platform.isIOS)) return;
    await _plugin.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(),
      ),
      onDidReceiveNotificationResponse: (response) {
        final route = response.payload;
        if (route != null && route.isNotEmpty) onOpen(route);
      },
    );
    final android = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(channel);
    await android?.requestNotificationsPermission();
    _ready = true;

    final launch = await _plugin.getNotificationAppLaunchDetails();
    final route = launch?.notificationResponse?.payload;
    if ((launch?.didNotificationLaunchApp ?? false) && route != null && route.isNotEmpty) onOpen(route);
  }

  Future<void> show(List<Map<String, dynamic>> items, {bool patient = false}) async {
    if (!_ready || items.isEmpty) return;
    final details = NotificationDetails(
      android: AndroidNotificationDetails(
        channel.id,
        channel.name,
        channelDescription: channel.description,
        importance: Importance.high,
        priority: Priority.high,
      ),
      iOS: const DarwinNotificationDetails(),
    );
    if (items.length > maxSeparateAlerts) {
      await _plugin.show(
        id: 0,
        title: '${items.length} nouvelles notifications',
        body: 'Ouvrez Vision Homecare pour les consulter.',
        notificationDetails: details,
        payload: '/notifications',
      );
      return;
    }
    for (final n in items) {
      await _plugin.show(
        id: '${n['id']}'.hashCode & 0x7fffffff,
        title: '${n['title']}',
        body: n['body'] as String?,
        notificationDetails: details,
        payload: notificationRoute(n, patient: patient) ?? '/notifications',
      );
    }
  }

  /// Déconnexion : plus aucune alerte de l'ancien utilisateur visible sur le téléphone.
  Future<void> clear() async {
    if (_ready) await _plugin.cancelAll();
  }
}

final localAlertsProvider = Provider<LocalAlerts>((ref) => LocalAlerts());
