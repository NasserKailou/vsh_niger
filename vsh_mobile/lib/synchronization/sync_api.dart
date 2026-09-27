/// Contrat serveur de la synchronisation (docs/07_SYNCHRONISATION.md), abstrait pour être testé
/// sans réseau. L'implémentation HTTP est dans `network/http_sync_api.dart`.
abstract class SyncApi {
  /// Envoie un lot (50 opérations au plus). Renvoie un résultat par opération, dans le même ordre.
  Future<List<Map<String, dynamic>>> push(List<Map<String, dynamic>> operations);

  /// Changements du serveur depuis [cursor].
  Future<PullPage> pull(int cursor, {int limit = 200});
}

class PullPage {
  PullPage({required this.changes, required this.nextCursor, required this.hasMore, this.serverTime});

  factory PullPage.fromJson(Map<String, dynamic> json) => PullPage(
        changes: (json['changes'] as List? ?? const []).cast<Map<String, dynamic>>(),
        nextCursor: (json['next_cursor'] as num?)?.toInt() ?? 0,
        hasMore: json['has_more'] == true,
        serverTime: json['server_time'] as String?,
      );

  final List<Map<String, dynamic>> changes;
  final int nextCursor;
  final bool hasMore;
  final String? serverTime;
}

/// Pas de réseau, délai dépassé, serveur injoignable : on réessaiera, rien n'est perdu.
class SyncNetworkException implements Exception {
  SyncNetworkException([this.message = 'Connexion au serveur impossible.']);
  final String message;
  @override
  String toString() => message;
}

/// Session perdue (jeton impossible à renouveler) : l'utilisateur doit se reconnecter. La file est conservée.
class SyncAuthException implements Exception {
  SyncAuthException([this.message = 'Session expirée : reconnectez-vous.', this.deviceRevoked = false]);
  final String message;

  /// Appareil déclaré perdu ou volé : les données locales doivent être effacées.
  final bool deviceRevoked;
  @override
  String toString() => message;
}

/// Erreur serveur temporaire (5xx) : nouvel essai plus tard.
class SyncServerException implements Exception {
  SyncServerException([this.message = 'Le serveur a rencontré un problème.']);
  final String message;
  @override
  String toString() => message;
}
