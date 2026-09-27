import 'dart:io';

import '../config/env.dart';
import '../network/api_client.dart';
import 'token_store.dart';

class AuthUser {
  AuthUser(this.raw);

  final Map<String, dynamic> raw;

  String get id => raw['id'] as String;
  String get displayName => '${raw['first_name'] ?? ''} ${raw['last_name'] ?? ''}'.trim();
  String? get firstName => raw['first_name'] as String?;
  bool get isStaff => raw['account_type'] == 'STAFF';

  /// Compte patient : espace patient (rendez-vous, visites, résultats, documents) au lieu de la
  /// tournée du personnel.
  bool get isPatient => raw['account_type'] == 'PATIENT';
  bool get mustChangePassword => raw['must_change_password'] == true;
  List<String> get permissions => ((raw['permissions'] as List?) ?? const []).map((p) => '$p').toList();
  bool can(String permission) => permissions.contains(permission);
}

class AuthRepository {
  AuthRepository(this.api, this.tokens);

  final ApiClient api;
  final TokenStore tokens;

  /// Connexion par téléphone et mot de passe (personnel, ou patient inscrit avec un mot de passe),
  /// avec enregistrement de l'appareil (le jeton de renouvellement lui est lié).
  Future<AuthUser> login(String phone, String password) async {
    final data = await api.postPublic('/auth/login', {
      'phone': phone.trim(),
      'password': password,
      'device': await _device(),
    }) as Map;
    return _open(data);
  }

  /// Patient, étape 1 : code à usage unique envoyé par SMS (D-001). Réponse identique que le dossier
  /// existe ou non : rien n'est révélé.
  Future<void> requestPatientCode(String fileNumber, String phone) =>
      api.postPublic('/auth/patient-portal/code', {'file_number': _fileNumber(fileNumber), 'phone': phone.trim()});

  /// Patient, étape 2 : session liée à l'appareil, valable 30 jours sans nouveau SMS.
  Future<AuthUser> loginWithCode(String fileNumber, String phone, String code) async {
    final data = await api.postPublic('/auth/patient-portal/login', {
      'file_number': _fileNumber(fileNumber),
      'phone': phone.trim(),
      'code': code.replaceAll(RegExp(r'\s'), ''),
      'device': await _device(),
    }) as Map;
    return _open(data);
  }

  /// Inscription, étape 1 : code de vérification du téléphone. Faux si la clinique ne l'exige pas.
  Future<bool> requestRegistrationCode(String phone) async {
    final data = await api.postPublic('/auth/register/code', {'phone': phone.trim()});
    return data is Map && data['code_required'] == true;
  }

  /// Inscription, étape 2 : compte patient et dossier « en attente de validation » par l'accueil,
  /// session ouverte aussitôt sur ce téléphone.
  Future<AuthUser> register(Map<String, dynamic> identity) async {
    final data = await api.postPublic('/auth/register', {...identity, 'device': await _device()}) as Map;
    return _open(data);
  }

  Future<AuthUser> _open(Map data) async {
    final user = AuthUser((data['user'] as Map).cast<String, dynamic>());
    if (!user.isStaff && !user.isPatient) {
      throw ApiException('Ce type de compte ne peut pas utiliser l’application.');
    }
    await tokens.saveTokens((data['tokens'] as Map).cast<String, dynamic>());
    await tokens.saveUser(_minimal(user.raw));
    return user;
  }

  Future<Map<String, dynamic>> _device() async => {
        'uuid': await tokens.deviceUuid(),
        'platform': Platform.isIOS ? 'IOS' : 'ANDROID',
        'name': _deviceName(),
        'app_version': Env.appVersion,
      };

  static String _fileNumber(String value) => value.trim().toUpperCase();

  /// Profil à jour (droits modifiés entre-temps). Hors ligne, le profil enregistré reste valable.
  Future<AuthUser> me() async {
    final data = (await api.get('/me') as Map).cast<String, dynamic>();
    await tokens.saveUser(_minimal(data));
    return AuthUser(data);
  }

  Future<void> changePassword(String current, String next) =>
      api.put('/auth/password', {'current_password': current, 'new_password': next});

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } on ApiException {
      // Hors ligne : la session sera close côté serveur à son expiration ; elle est effacée ici.
    }
    await tokens.clearSession();
  }

  static String _deviceName() {
    final host = Platform.localHostname;
    return host.length > 100 ? host.substring(0, 100) : host;
  }

  /// Seules les informations utiles hors ligne sont gardées sur le téléphone.
  static Map<String, dynamic> _minimal(Map<String, dynamic> user) => {
        for (final key in ['id', 'account_type', 'first_name', 'last_name', 'must_change_password', 'roles', 'permissions'])
          key: user[key],
      };
}
