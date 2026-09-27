import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';
import 'package:vsh_mobile/homecare/tile_prefetch.dart';

/// Cache en mémoire, à la place du cache disque de flutter_map.
class _MemoryCache implements MapCachingProvider {
  final tiles = <String, Uint8List>{};

  @override
  bool get isSupported => true;

  @override
  Future<CachedMapTile?> getTile(String url) async {
    final bytes = tiles[url];
    return bytes == null
        ? null
        : (bytes: bytes, metadata: CachedMapTileMetadata(staleAt: DateTime.now().add(const Duration(days: 1)), lastModified: null, etag: null));
  }

  @override
  Future<void> putTile({required String url, required CachedMapTileMetadata metadata, Uint8List? bytes}) async => tiles[url] = bytes!;
}

/// Serveur de tuiles simulé : compte les téléchargements.
class _TileServer extends Interceptor {
  int downloads = 0;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    downloads++;
    handler.resolve(Response(
      requestOptions: options,
      statusCode: 200,
      data: [137, 80, 78, 71],
      headers: Headers.fromMap({'cache-control': ['max-age=604800']}),
    ));
  }
}

void main() {
  const niamey = LatLng(13.5116, 2.1254);

  test('tuile d’un point (formule OpenStreetMap)', () {
    expect(tileOf(const LatLng(0, 0), 1), (1, 1));
    expect(tileOf(niamey, 17), (66309, 60570));
  });

  test('tuiles autour d’un domicile : peu nombreuses, du quartier à la rue', () {
    final tiles = tilesAround(niamey);
    expect(tiles.map((t) => t.$1).toSet(), {14, 15, 16, 17});
    expect(tiles.length, inInclusiveRange(10, 60));
    expect(tiles, contains((17, 66309, 60570)));
  });

  test('pré-chargement : tuiles absentes téléchargées une fois, puis ignorées', () async {
    final cache = _MemoryCache();
    final server = _TileServer();
    final dio = Dio(BaseOptions(responseType: ResponseType.bytes))..interceptors.add(server);
    final prefetcher = TilePrefetcher(dio: dio, cache: cache);

    final added = await prefetcher.prefetch({'visite-1': niamey});
    expect(added, tilesAround(niamey).toSet().length);
    expect(cache.tiles.keys, contains('https://tile.openstreetmap.org/17/66309/60570.png'));

    // Même visite : rien de nouveau. Visite voisine : seulement les tuiles manquantes.
    expect(await prefetcher.prefetch({'visite-1': niamey}), 0);
    final before = server.downloads;
    await prefetcher.prefetch({'visite-2': const LatLng(13.5118, 2.1256)});
    expect(server.downloads - before, lessThan(tilesAround(niamey).length));
  });
}
