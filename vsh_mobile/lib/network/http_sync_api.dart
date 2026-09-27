import '../synchronization/sync_api.dart';
import 'api_client.dart';

/// Implémentation HTTP du contrat de synchronisation (`/sync/push`, `/sync/pull`).
class HttpSyncApi implements SyncApi {
  HttpSyncApi(this.client);

  final ApiClient client;

  @override
  Future<List<Map<String, dynamic>>> push(List<Map<String, dynamic>> operations) => _guard(() async {
        final data = await client.post('/sync/push', {'operations': operations}) as Map;
        return (data['results'] as List).map((r) => (r as Map).cast<String, dynamic>()).toList();
      });

  @override
  Future<PullPage> pull(int cursor, {int limit = 200}) => _guard(() async {
        final data = await client.get('/sync/pull', query: {'cursor': cursor, 'limit': limit}) as Map;
        return PullPage.fromJson(data.cast<String, dynamic>());
      });

  /// Traduit les erreurs HTTP dans le vocabulaire du moteur (réessayer, se reconnecter, effacer).
  Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (e) {
      if (e.isNetwork) throw SyncNetworkException(e.message);
      if (e.deviceRevoked) throw SyncAuthException(e.message, true);
      if (e.status == 401) throw SyncAuthException();
      throw SyncServerException(e.message);
    }
  }
}
