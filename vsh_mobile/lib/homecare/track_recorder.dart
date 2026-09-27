import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../synchronization/sync_providers.dart';
import 'homecare_repository.dart';
import 'location_service.dart';
import 'visit.dart';

/// Enregistre le trajet de l'équipe pendant une visite en cours (en route, sur place, soins), et
/// seulement à ce moment-là (vie privée du personnel). Fonctionne application ouverte ; les
/// positions de départ et d'arrivée sont de toute façon jointes aux actions.
class TrackRecorder {
  TrackRecorder(this._repository, this._location, {this.interval = const Duration(seconds: 30)});

  final HomecareRepository _repository;
  final LocationService _location;

  /// Intervalle entre deux points (paramètre serveur `geo.track_interval_seconds`, 30 s par défaut),
  /// pris en compte au prochain suivi.
  Duration interval;

  static const _flushEvery = 5;
  static const _flushDelay = Duration(minutes: 2);

  String? _visitId;
  StreamSubscription<GeoFix>? _sub;
  Timer? _timer;
  final _buffer = <GeoFix>[];

  /// Dernière position connue, pour la carte.
  final lastFix = ValueNotifier<GeoFix?>(null);

  String? get visitId => _visitId;
  bool get recording => _sub != null;

  Future<void> follow(String? visitId) async {
    if (visitId == _visitId) return;
    await stop();
    _visitId = visitId;
    if (visitId == null) return;
    _sub = _location.watch(interval).listen((fix) {
      lastFix.value = fix;
      _buffer.add(fix);
      if (_buffer.length >= _flushEvery) unawaited(flush());
    });
    _timer = Timer.periodic(_flushDelay, (_) => flush());
  }

  /// Écrit les points en attente dans la file (avant chaque action, pour que l'ordre soit respecté).
  Future<void> flush() async {
    final visitId = _visitId;
    if (visitId == null || _buffer.isEmpty) return;
    final points = List<GeoFix>.of(_buffer);
    _buffer.clear();
    await _repository.track(visitId, points);
  }

  Future<void> stop() async {
    _timer?.cancel();
    _timer = null;
    final sub = _sub;
    _sub = null;
    await flush();
    _visitId = null;
    await sub?.cancel();
  }

  void dispose() {
    unawaited(stop());
    lastFix.dispose();
  }
}

final locationServiceProvider = Provider<LocationService>((ref) => const LocationService());

final visitsProvider = StreamProvider<List<Visit>>((ref) => ref.watch(homecareRepositoryProvider).watchVisits());

/// Suit automatiquement la visite en cours de l'équipe (la plus récente).
final trackRecorderProvider = Provider<TrackRecorder>((ref) {
  final recorder = TrackRecorder(ref.watch(homecareRepositoryProvider), ref.watch(locationServiceProvider));
  unawaited(ref.read(referenceRepositoryProvider).number('geo.track_interval_seconds', 30).then((seconds) {
    if (seconds >= 5) recorder.interval = Duration(seconds: seconds.toInt());
  }));
  ref.listen<AsyncValue<List<Visit>>>(visitsProvider, (_, next) {
    final visits = next.value ?? const <Visit>[];
    final current = visits.where((v) => v.available && VisitFlow.tracked.contains(v.status)).firstOrNull;
    unawaited(recorder.follow(current?.id));
  }, fireImmediately: true);
  ref.onDispose(recorder.dispose);
  return recorder;
});
