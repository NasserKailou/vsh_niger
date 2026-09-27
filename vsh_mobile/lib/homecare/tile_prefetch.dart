import 'dart:io' show HttpDate;
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Fonds de carte. Par défaut, les serveurs d'OpenStreetMap, dont la politique d'usage interdit le
/// téléchargement massif : le pré-chargement ci-dessous reste limité (quelques centaines de tuiles
/// autour des domiciles de la tournée). En production, un fournisseur de tuiles de la clinique permet
/// d'aller plus loin : `--dart-define=VSH_TILE_URL=https://tuiles.exemple.ne/{z}/{x}/{y}.png`.
abstract final class MapTiles {
  static const urlTemplate = String.fromEnvironment('VSH_TILE_URL', defaultValue: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
  static const userAgentPackageName = 'ne.visionhomecare.vsh_mobile';

  static String url(int z, int x, int y) =>
      urlTemplate.replaceAll('{z}', '$z').replaceAll('{x}', '$x').replaceAll('{y}', '$y');
}

/// Tuile (colonne, ligne) contenant un point au [zoom] donné : formule « slippy map » d'OpenStreetMap.
(int, int) tileOf(LatLng p, int zoom) {
  final n = 1 << zoom;
  final x = ((p.longitude + 180) / 360 * n).floor();
  final lat = p.latitude * math.pi / 180;
  final y = ((1 - math.log(math.tan(lat) + 1 / math.cos(lat)) / math.pi) / 2 * n).floor();
  return (x.clamp(0, n - 1), y.clamp(0, n - 1));
}

/// Tuiles couvrant un carré de [radiusM] mètres autour de [p], pour chaque zoom de [zooms].
List<(int, int, int)> tilesAround(LatLng p, {double radiusM = 500, List<int> zooms = TilePrefetcher.zooms}) {
  const distance = Distance(roundResult: false);
  final north = distance.offset(p, radiusM, 0);
  final south = distance.offset(p, radiusM, 180);
  final east = distance.offset(p, radiusM, 90);
  final west = distance.offset(p, radiusM, 270);
  final tiles = <(int, int, int)>[];
  for (final z in zooms) {
    final (x0, y0) = tileOf(LatLng(north.latitude, west.longitude), z);
    final (x1, y1) = tileOf(LatLng(south.latitude, east.longitude), z);
    for (var x = x0; x <= x1; x++) {
      for (var y = y0; y <= y1; y++) {
        tiles.add((z, x, y));
      }
    }
  }
  return tiles;
}

/// Pré-chargement des fonds de carte autour des domiciles de la tournée, pendant qu'il y a du réseau :
/// sur place, souvent sans réseau, la carte s'affiche quand même. Les tuiles vont dans le cache disque
/// de flutter_map (celui qu'utilisent toutes les cartes de l'application), déjà présentes = ignorées.
class TilePrefetcher {
  TilePrefetcher({Dio? dio, MapCachingProvider? cache})
      : _dio = dio ?? Dio(BaseOptions(responseType: ResponseType.bytes, receiveTimeout: const Duration(seconds: 15))),
        _cache = cache; // ignore: prefer_initializing_formals

  /// Du quartier (14) à la rue (17).
  static const zooms = [14, 15, 16, 17];

  /// Plafond par passage : usage raisonnable des serveurs publics.
  static const maxTilesPerRun = 250;

  final Dio _dio;
  MapCachingProvider? _cache;
  final _done = <String>{};
  bool _running = false;

  MapCachingProvider get _provider => _cache ??= BuiltInMapCachingProvider.getOrCreateInstance();

  /// Télécharge les tuiles manquantes autour de [homes] (clé : identifiant de la visite).
  /// Renvoie le nombre de tuiles ajoutées au cache.
  Future<int> prefetch(Map<String, LatLng> homes) async {
    if (_running || !_provider.isSupported) return 0;
    _running = true;
    var added = 0;
    try {
      final tiles = <(int, int, int)>{};
      for (final entry in homes.entries) {
        if (_done.add(entry.key)) tiles.addAll(tilesAround(entry.value));
      }
      for (final (z, x, y) in tiles.take(maxTilesPerRun)) {
        final url = MapTiles.url(z, x, y);
        if (await _provider.getTile(url) != null) continue;
        final response = await _dio.get<List<int>>(url, options: Options(headers: {'User-Agent': MapTiles.userAgentPackageName}));
        final bytes = response.data;
        if (response.statusCode != 200 || bytes == null || bytes.isEmpty) continue;
        await _provider.putTile(url: url, metadata: _metadata(response.headers), bytes: Uint8List.fromList(bytes));
        added++;
      }
    } on DioException {
      // Réseau perdu en cours de route : les tuiles déjà obtenues restent, le reste au prochain passage.
      _done.clear();
    } finally {
      _running = false;
    }
    return added;
  }

  /// Fraîcheur de la tuile d'après les en-têtes HTTP. flutter_map exige l'en-tête `Date` avec
  /// `max-age` : il est ajouté s'il manque ; en-têtes illisibles = tuile gardée 30 jours.
  static CachedMapTileMetadata _metadata(Headers raw) {
    final headers = {for (final e in raw.map.entries) e.key.toLowerCase(): e.value.join(', ')};
    headers.putIfAbsent('date', () => HttpDate.format(DateTime.now().toUtc()));
    try {
      return CachedMapTileMetadata.fromHttpHeaders(headers, fallbackFreshnessAge: const Duration(days: 30));
    } catch (_) {
      return CachedMapTileMetadata(staleAt: DateTime.now().toUtc().add(const Duration(days: 30)), lastModified: null, etag: null);
    }
  }
}

final tilePrefetcherProvider = Provider<TilePrefetcher>((ref) => TilePrefetcher());
