import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import 'notifications_screen.dart';

/// Projet Firebase, fourni à la compilation (aucun fichier google-services.json dans le dépôt) :
///
/// ```
/// flutter build appbundle --dart-define=FIREBASE_API_KEY=… --dart-define=FIREBASE_APP_ID=… \
///   --dart-define=FIREBASE_SENDER_ID=… --dart-define=FIREBASE_PROJECT_ID=…
/// ```
///
/// Sans ces valeurs, le push est simplement absent : les notifications arrivent à la
/// synchronisation et sont signalées par des alertes locales.
abstract final class FirebaseEnv {
  static const apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');

  static bool get configured => apiKey.isNotEmpty && appId.isNotEmpty && senderId.isNotEmpty && projectId.isNotEmpty;
}

/// Push FCM facultatif (D-011). Le message ne sert qu'à prévenir : son contenu est déjà dans la
/// notification in-app, qui reste la source de vérité et arrive par la synchronisation.
///
/// - Application ouverte : le message déclenche une synchronisation, puis l'alerte locale.
/// - Application en arrière-plan ou fermée : le système affiche le message ; le toucher ouvre
///   l'écran concerné.
class PushService {
  PushService(this.ref);

  final Ref ref;

  /// Vrai quand le serveur a confirmé l'envoi de push vers cet appareil.
  bool active = false;

  bool _initialized = false;
  final _subscriptions = <StreamSubscription<Object?>>[];

  Future<void> start(void Function(String route) onOpen) async {
    if (!FirebaseEnv.configured || !(Platform.isAndroid || Platform.isIOS)) return;
    try {
      if (!_initialized) {
        await Firebase.initializeApp(
          options: const FirebaseOptions(
            apiKey: FirebaseEnv.apiKey,
            appId: FirebaseEnv.appId,
            messagingSenderId: FirebaseEnv.senderId,
            projectId: FirebaseEnv.projectId,
          ),
        );
        _initialized = true;
        _subscriptions
          ..add(FirebaseMessaging.onMessage.listen((_) => ref.read(syncSchedulerProvider).now()))
          ..add(FirebaseMessaging.onMessageOpenedApp.listen((message) => onOpen(_route(message))))
          ..add(FirebaseMessaging.instance.onTokenRefresh.listen(_register));
        final initial = await FirebaseMessaging.instance.getInitialMessage();
        if (initial != null) onOpen(_route(initial));
      }
      final permission = await FirebaseMessaging.instance.requestPermission();
      if (permission.authorizationStatus == AuthorizationStatus.denied) return;
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) await _register(token);
    } catch (_) {
      // Push facultatif : Google Play Services absents, projet mal configuré… l'application
      // continue avec la synchronisation et les alertes locales.
      active = false;
    }
  }

  Future<void> _register(String token) async {
    try {
      final data = await ref.read(apiClientProvider).post('/push-tokens', {'token': token});
      active = data is Map && data['push_enabled'] == true;
    } on ApiException {
      // Hors ligne : nouvel essai au prochain démarrage de la session.
    }
  }

  /// Déconnexion : le jeton est détruit auprès de Firebase, et le serveur a déjà oublié l'appareil.
  Future<void> stop() async {
    active = false;
    if (!_initialized) return;
    try {
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {
      // Hors ligne : le serveur supprimera le jeton au premier envoi refusé par FCM.
    }
  }

  String _route(RemoteMessage message) =>
      notificationRoute(message.data, patient: ref.read(sessionProvider).value?.user?.isPatient == true) ?? '/notifications';
}

final pushServiceProvider = Provider<PushService>((ref) => PushService(ref));
