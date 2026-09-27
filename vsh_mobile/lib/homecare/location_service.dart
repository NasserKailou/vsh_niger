import 'dart:async';

import 'package:geolocator/geolocator.dart';

/// Position relevée sur le téléphone.
class GeoFix {
  const GeoFix(this.latitude, this.longitude, this.accuracyM, this.capturedAt);

  final double latitude;
  final double longitude;
  final double? accuracyM;
  final DateTime capturedAt;

  Map<String, dynamic> toPayload() => {
        'latitude': double.parse(latitude.toStringAsFixed(7)),
        'longitude': double.parse(longitude.toStringAsFixed(7)),
        if (accuracyM != null) 'accuracy_m': double.parse(accuracyM!.toStringAsFixed(1)),
      };

  Map<String, dynamic> toTrackPoint() => {...toPayload(), 'captured_at': capturedAt.toUtc().toIso8601String()};

  /// (0, 0) : valeur renvoyée par certains GPS avant d'avoir une position (refusée par le serveur).
  bool get usable =>
      !(latitude.abs() < 1e-6 && longitude.abs() < 1e-6) && latitude.abs() <= 90 && longitude.abs() <= 180;
}

enum GeoProblem { serviceDisabled, denied, deniedForever, unavailable }

class GeoResult {
  const GeoResult.ok(GeoFix this.fix) : problem = null;
  const GeoResult.failed(GeoProblem this.problem) : fix = null;

  final GeoFix? fix;
  final GeoProblem? problem;

  String? get message => switch (problem) {
        null => null,
        GeoProblem.serviceDisabled => 'La localisation du téléphone est désactivée.',
        GeoProblem.denied => 'Autorisation de localisation refusée.',
        GeoProblem.deniedForever => 'Localisation refusée : autorisez-la dans les réglages du téléphone.',
        GeoProblem.unavailable => 'Position GPS introuvable pour le moment.',
      };
}

/// Lecture du GPS. Aucune action n'est bloquée par le GPS : sans position, l'action part sans elle.
class LocationService {
  const LocationService();

  Future<GeoProblem?> ensurePermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) return GeoProblem.serviceDisabled;
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    return switch (permission) {
      LocationPermission.denied => GeoProblem.denied,
      LocationPermission.deniedForever => GeoProblem.deniedForever,
      _ => null,
    };
  }

  /// Meilleure position obtenue en [maxWait] (le GPS s'affine pendant quelques secondes).
  Future<GeoResult> best({Duration maxWait = const Duration(seconds: 12), double wantedAccuracyM = 25}) async {
    final problem = await ensurePermission();
    if (problem != null) return GeoResult.failed(problem);
    GeoFix? best;
    final done = Completer<void>();
    final sub = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, distanceFilter: 0),
    ).listen((p) {
      final fix = GeoFix(p.latitude, p.longitude, p.accuracy, p.timestamp);
      if (!fix.usable) return;
      if (best == null || (fix.accuracyM ?? 1e9) < (best!.accuracyM ?? 1e9)) best = fix;
      if ((fix.accuracyM ?? 1e9) <= wantedAccuracyM && !done.isCompleted) done.complete();
    }, onError: (_) {
      if (!done.isCompleted) done.complete();
    });
    await done.future.timeout(maxWait, onTimeout: () {});
    await sub.cancel();
    if (best == null) {
      final last = await Geolocator.getLastKnownPosition();
      if (last != null) {
        final fix = GeoFix(last.latitude, last.longitude, last.accuracy, last.timestamp);
        if (fix.usable) best = fix;
      }
    }
    return best == null ? const GeoResult.failed(GeoProblem.unavailable) : GeoResult.ok(best!);
  }

  /// Positions successives pendant le déplacement (au plus une par [interval]).
  Stream<GeoFix> watch(Duration interval) async* {
    if (await ensurePermission() != null) return;
    DateTime? last;
    await for (final p in Geolocator.getPositionStream(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 10),
    )) {
      final fix = GeoFix(p.latitude, p.longitude, p.accuracy, p.timestamp);
      if (!fix.usable) continue;
      if (last != null && fix.capturedAt.difference(last) < interval) continue;
      last = fix.capturedAt;
      yield fix;
    }
  }

  Future<void> openSettings() => Geolocator.openAppSettings();
}
