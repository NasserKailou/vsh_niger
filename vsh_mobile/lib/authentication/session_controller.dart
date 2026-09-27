import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/env.dart';
import '../network/api_client.dart';
import '../synchronization/sync_providers.dart';
import 'auth_repository.dart';

enum SessionStatus { unconfigured, signedOut, mustChangePassword, signedIn }

class SessionState {
  const SessionState(this.status, {this.user, this.notice});

  final SessionStatus status;
  final AuthUser? user;

  /// Motif affiché sur l'écran de connexion (session expirée, appareil révoqué).
  final String? notice;
}

/// Session de l'utilisateur. Démarre hors ligne à partir du profil enregistré : l'application reste
/// utilisable sans réseau tant que la session n'a pas été refusée par le serveur.
class SessionController extends AsyncNotifier<SessionState> {
  static const _ownerKey = 'owner.user_id';

  @override
  Future<SessionState> build() async {
    if (!Env.isConfigured) return const SessionState(SessionStatus.unconfigured);
    final tokens = ref.read(tokenStoreProvider);
    final refresh = await tokens.readRefreshToken();
    final saved = await tokens.readUser();
    if (refresh == null || saved == null) return const SessionState(SessionStatus.signedOut);
    final user = AuthUser(saved);
    return SessionState(user.mustChangePassword ? SessionStatus.mustChangePassword : SessionStatus.signedIn, user: user);
  }

  Future<void> login(String phone, String password) async =>
      _signedIn(await ref.read(authRepositoryProvider).login(phone, password));

  /// Patient : connexion par n° de dossier, téléphone et code SMS.
  Future<void> loginWithCode(String fileNumber, String phone, String code) async =>
      _signedIn(await ref.read(authRepositoryProvider).loginWithCode(fileNumber, phone, code));

  /// Nouveau patient : inscription depuis l'application, puis session ouverte.
  Future<void> register(Map<String, dynamic> identity) async => _signedIn(await ref.read(authRepositoryProvider).register(identity));

  Future<void> _signedIn(AuthUser user) async {
    // Appareil partagé : les données du précédent utilisateur sont effacées (docs/05).
    final db = ref.read(databaseProvider);
    final owner = await db.readMeta(_ownerKey);
    if (owner != null && owner != user.id) {
      await db.wipe();
    }
    await db.writeMeta(_ownerKey, user.id);
    state = AsyncData(SessionState(user.mustChangePassword ? SessionStatus.mustChangePassword : SessionStatus.signedIn, user: user));
  }

  Future<void> changePassword(String current, String next) async {
    final auth = ref.read(authRepositoryProvider);
    await auth.changePassword(current, next);
    final user = await auth.me();
    state = AsyncData(SessionState(SessionStatus.signedIn, user: user));
  }

  /// Déconnexion volontaire. Les modifications non envoyées restent sur l'appareil pour le prochain
  /// utilisateur identique ; elles sont effacées si un autre compte se connecte.
  Future<void> logout() async {
    await ref.read(authRepositoryProvider).logout();
    state = const AsyncData(SessionState(SessionStatus.signedOut));
  }

  /// Renouvellement refusé par le serveur, ou appareil révoqué (données locales effacées).
  Future<void> sessionLost({required bool deviceRevoked}) async {
    await ref.read(tokenStoreProvider).clearSession();
    if (deviceRevoked) {
      await ref.read(databaseProvider).wipe();
    }
    state = AsyncData(SessionState(
      SessionStatus.signedOut,
      notice: deviceRevoked
          ? 'Cet appareil a été révoqué par la clinique : les données ont été effacées.'
          : 'Votre session a expiré. Reconnectez-vous : les modifications non envoyées sont conservées.',
    ));
  }

  /// Rafraîchit les droits en arrière-plan quand le réseau est disponible.
  Future<void> refreshProfile() async {
    final current = state.value;
    if (current?.status != SessionStatus.signedIn) return;
    try {
      final user = await ref.read(authRepositoryProvider).me();
      state = AsyncData(SessionState(SessionStatus.signedIn, user: user));
    } on ApiException {
      // Hors ligne ou session perdue (traitée par le client HTTP).
    }
  }
}
