import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

/// Secrets de l'appareil, dans le Keystore Android / Keychain iOS. Le mot de passe n'est jamais
/// enregistré ; le jeton d'accès reste en mémoire.
class TokenStore {
  TokenStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _refreshKey = 'vsh.refresh_token';
  static const _deviceKey = 'vsh.device_uuid';
  static const _dbKeyKey = 'vsh.db_key';
  static const _userKey = 'vsh.user';

  String? accessToken;

  Future<String?> readRefreshToken() => _storage.read(key: _refreshKey);

  Future<void> saveTokens(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String?;
    await _storage.write(key: _refreshKey, value: tokens['refresh_token'] as String?);
  }

  /// Profil minimal de l'utilisateur connecté (nom, rôles, permissions), sans donnée médicale.
  Future<Map<String, dynamic>?> readUser() async {
    final raw = await _storage.read(key: _userKey);
    return raw == null ? null : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  Future<void> saveUser(Map<String, dynamic> user) => _storage.write(key: _userKey, value: jsonEncode(user));

  Future<void> clearSession() async {
    accessToken = null;
    await _storage.delete(key: _refreshKey);
    await _storage.delete(key: _userKey);
  }

  /// Identifiant stable de l'installation, déclaré au serveur (révocation possible par la clinique).
  Future<String> deviceUuid() async {
    final existing = await _storage.read(key: _deviceKey);
    if (existing != null) return existing;
    final created = const Uuid().v4();
    await _storage.write(key: _deviceKey, value: created);
    return created;
  }

  /// Clé de chiffrement de la base locale : 256 bits aléatoires, créés au premier lancement.
  Future<String> databaseKey() async {
    final existing = await _storage.read(key: _dbKeyKey);
    if (existing != null) return existing;
    final random = Random.secure();
    final key = List.generate(32, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    await _storage.write(key: _dbKeyKey, value: key);
    return key;
  }
}
