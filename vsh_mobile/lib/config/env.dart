import 'dart:io';

import 'package:flutter/foundation.dart';

/// Configuration fournie à la compilation, jamais écrite dans le code pour la production :
///
/// ```
/// flutter build appbundle --dart-define=VSH_API_BASE_URL=https://api.exemple.ne/api/v1
/// ```
///
/// Aucun secret n'est nécessaire côté application : l'authentification se fait avec le compte de
/// l'utilisateur, et les jetons sont gardés dans le stockage sécurisé du téléphone.
abstract final class Env {
  static const _definedBaseUrl = String.fromEnvironment('VSH_API_BASE_URL');

  /// Poste de développement vu depuis le téléphone (débogage seulement) : `10.0.2.2` est l'ordinateur
  /// hôte pour l'émulateur Android ; pour un vrai téléphone sur le même Wi-Fi, passer l'adresse IP
  /// du poste : `--dart-define=VSH_DEV_HOST=192.168.1.20`.
  static const _devHost = String.fromEnvironment('VSH_DEV_HOST');
  static const _devPath = ':8085/vsh_niger/vsh_serveur/public/api/v1';

  /// Adresse de l'API. En débogage sans paramètre : le serveur XAMPP local (les deux projets du
  /// dépôt fonctionnent ensemble sans réglage). Une version publiée exige l'adresse HTTPS.
  static String get apiBaseUrl {
    if (_definedBaseUrl.isNotEmpty) return _definedBaseUrl;
    if (!kDebugMode) return '';
    final host = _devHost.isNotEmpty ? _devHost : (Platform.isAndroid ? '10.0.2.2' : 'localhost');
    return 'http://$host$_devPath';
  }

  /// Version affichée et envoyée au serveur à l'enregistrement de l'appareil.
  static const appVersion = String.fromEnvironment('VSH_APP_VERSION', defaultValue: '1.0.0');

  /// HTTP en clair toléré seulement pour un serveur de développement local.
  static bool get isConfigured {
    final uri = Uri.tryParse(apiBaseUrl);
    if (uri == null || !uri.hasScheme || uri.host.isEmpty) return false;
    return uri.scheme == 'https' || (uri.scheme == 'http' && _isLocal(uri.host));
  }

  static bool _isLocal(String host) =>
      host == 'localhost' || host == '10.0.2.2' || host.startsWith('192.168.') || host.startsWith('10.') || host == '127.0.0.1';

  /// Fréquence de la synchronisation automatique quand l'application est ouverte.
  static const syncInterval = Duration(minutes: 5);

  /// Conservation des opérations terminées (traçabilité locale).
  static const outboxRetention = Duration(days: 30);
}
