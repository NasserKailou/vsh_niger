import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Service de calcul d'itinéraire routier (API OSRM). Par défaut, le serveur public de la
/// FOSSGIS (routing.openstreetmap.de, données OpenStreetMap). En production, un serveur OSRM
/// propre à la clinique (carte du Niger seulement, quelques Go) évite toute limite d'usage :
///
/// ```
/// flutter build appbundle --dart-define=VSH_ROUTING_URL=https://itineraire.exemple.ne
/// ```
///
/// Seules les coordonnées de départ et d'arrivée sont envoyées : jamais de nom ni de donnée médicale.
abstract final class RoutingEnv {
  static const baseUrl = String.fromEnvironment('VSH_ROUTING_URL', defaultValue: 'https://routing.openstreetmap.de/routed-car');
}

/// Étape de l'itinéraire : manœuvre à faire au point [location].
class RouteStep {
  const RouteStep({required this.instruction, required this.location, required this.distanceM, required this.icon});

  final String instruction;
  final LatLng location;

  /// Longueur du tronçon qui suit la manœuvre.
  final double distanceM;

  /// `left`, `right`, `straight`, `uturn`, `roundabout`, `arrive`, `depart`.
  final String icon;
}

/// Itinéraire calculé : tracé, longueur, durée estimée et étapes.
class RoutePlan {
  const RoutePlan({required this.points, required this.distanceM, required this.durationS, required this.steps});

  final List<LatLng> points;
  final double distanceM;
  final double durationS;
  final List<RouteStep> steps;

  /// Lecture d'une réponse OSRM `route/v1` (`geometries=geojson`, `steps=true`).
  factory RoutePlan.fromOsrm(Map<String, dynamic> json) {
    if (json['code'] != 'Ok' || (json['routes'] as List?)?.isNotEmpty != true) {
      throw const RoutingException('Aucun itinéraire routier trouvé.');
    }
    final route = (json['routes'] as List).first as Map<String, dynamic>;
    final coordinates = ((route['geometry'] as Map)['coordinates'] as List)
        .map((c) => LatLng(((c as List)[1] as num).toDouble(), (c[0] as num).toDouble()))
        .toList();
    final steps = <RouteStep>[];
    for (final leg in (route['legs'] as List? ?? const [])) {
      for (final raw in ((leg as Map)['steps'] as List? ?? const [])) {
        final step = (raw as Map).cast<String, dynamic>();
        final maneuver = (step['maneuver'] as Map).cast<String, dynamic>();
        final location = maneuver['location'] as List;
        steps.add(RouteStep(
          instruction: instructionFor(maneuver, step['name'] as String?),
          location: LatLng((location[1] as num).toDouble(), (location[0] as num).toDouble()),
          distanceM: ((step['distance'] as num?) ?? 0).toDouble(),
          icon: iconFor(maneuver),
        ));
      }
    }
    return RoutePlan(
      points: coordinates,
      distanceM: ((route['distance'] as num?) ?? 0).toDouble(),
      durationS: ((route['duration'] as num?) ?? 0).toDouble(),
      steps: steps,
    );
  }

  /// Distance restante le long du tracé, depuis le point le plus proche de [position].
  double remainingFrom(LatLng position) {
    if (points.length < 2) return 0;
    final (index, projected) = nearestOnPolyline(position, points);
    var total = _distance.as(LengthUnit.Meter, projected, points[index + 1]);
    for (var i = index + 1; i < points.length - 1; i++) {
      total += _distance.as(LengthUnit.Meter, points[i], points[i + 1]);
    }
    return total;
  }

  /// Prochaine manœuvre : la première étape encore devant, au-delà de 15 m de [position].
  RouteStep? nextStep(LatLng position) {
    if (steps.isEmpty) return null;
    final (index, _) = nearestOnPolyline(position, points);
    for (final step in steps.skip(1)) {
      final (stepIndex, _) = nearestOnPolyline(step.location, points);
      if (stepIndex >= index && _distance.as(LengthUnit.Meter, position, step.location) > 15) return step;
    }
    return steps.last;
  }
}

class RoutingException implements Exception {
  const RoutingException(this.message);

  final String message;

  @override
  String toString() => message;
}

const _distance = Distance(roundResult: false);

/// Segment du tracé le plus proche de [p] et point projeté sur ce segment (approximation plane,
/// suffisante à l'échelle d'une rue).
(int, LatLng) nearestOnPolyline(LatLng p, List<LatLng> line) {
  var best = 0;
  var bestPoint = line.first;
  var bestDistance = double.infinity;
  final cosLat = math.cos(p.latitude * math.pi / 180);
  for (var i = 0; i < line.length - 1; i++) {
    final a = line[i];
    final b = line[i + 1];
    final ax = a.longitude * cosLat, ay = a.latitude;
    final bx = b.longitude * cosLat, by = b.latitude;
    final px = p.longitude * cosLat, py = p.latitude;
    final dx = bx - ax, dy = by - ay;
    final length2 = dx * dx + dy * dy;
    final t = length2 == 0 ? 0.0 : (((px - ax) * dx + (py - ay) * dy) / length2).clamp(0.0, 1.0);
    final projected = LatLng(a.latitude + (b.latitude - a.latitude) * t, a.longitude + (b.longitude - a.longitude) * t);
    final d = _distance.as(LengthUnit.Meter, p, projected);
    if (d < bestDistance) {
      bestDistance = d;
      best = i;
      bestPoint = projected;
    }
  }
  return (best, bestPoint);
}

/// Distance entre [p] et le tracé, pour détecter une sortie d'itinéraire.
double distanceToPolyline(LatLng p, List<LatLng> line) {
  if (line.length < 2) return line.isEmpty ? double.infinity : _distance.as(LengthUnit.Meter, p, line.first);
  final (_, projected) = nearestOnPolyline(p, line);
  return _distance.as(LengthUnit.Meter, p, projected);
}

/// Cap (0 à 360°, 0 = nord) de [from] vers [to] : flèche de direction sans itinéraire routier.
double bearing(LatLng from, LatLng to) => (_distance.bearing(from, to) + 360) % 360;

String _direction(String? modifier) => switch (modifier) {
      'left' => 'à gauche',
      'right' => 'à droite',
      'slight left' => 'légèrement à gauche',
      'slight right' => 'légèrement à droite',
      'sharp left' => 'franchement à gauche',
      'sharp right' => 'franchement à droite',
      'uturn' => 'demi-tour',
      _ => 'tout droit',
    };

/// Consigne en français à partir de la manœuvre OSRM.
String instructionFor(Map<String, dynamic> maneuver, String? roadName) {
  final type = maneuver['type'] as String?;
  final modifier = maneuver['modifier'] as String?;
  final road = roadName == null || roadName.trim().isEmpty ? '' : ' sur ${roadName.trim()}';
  switch (type) {
    case 'depart':
      return 'Partez$road';
    case 'arrive':
      return 'Vous êtes arrivé à destination';
    case 'roundabout':
    case 'rotary':
      final exit = maneuver['exit'];
      return exit is int ? 'Au rond-point, prenez la ${exit == 1 ? '1re' : '${exit}e'} sortie$road' : 'Prenez le rond-point$road';
    case 'continue':
    case 'new name':
      return 'Continuez ${_direction(modifier)}$road';
    case 'merge':
      return 'Insérez-vous$road';
    case 'fork':
      return 'À la bifurcation, restez ${_direction(modifier)}$road';
    case 'end of road':
      return 'Au bout de la route, tournez ${_direction(modifier)}$road';
    default:
      if (modifier == 'uturn') return 'Faites demi-tour$road';
      if (modifier == 'straight' || modifier == null) return 'Continuez tout droit$road';
      return 'Tournez ${_direction(modifier)}$road';
  }
}

String iconFor(Map<String, dynamic> maneuver) {
  final type = maneuver['type'] as String?;
  final modifier = maneuver['modifier'] as String? ?? '';
  if (type == 'arrive') return 'arrive';
  if (type == 'depart') return 'depart';
  if (type == 'roundabout' || type == 'rotary') return 'roundabout';
  if (modifier == 'uturn') return 'uturn';
  if (modifier.contains('left')) return 'left';
  if (modifier.contains('right')) return 'right';
  return 'straight';
}

/// Durée lisible : « 12 min », « 1 h 05 ».
String formatDuration(double seconds) {
  final minutes = (seconds / 60).round();
  if (minutes < 60) return '${math.max(1, minutes)} min';
  return '${minutes ~/ 60} h ${(minutes % 60).toString().padLeft(2, '0')}';
}

final routingServiceProvider = Provider<RoutingService>((ref) => RoutingService());

/// Itinéraire de l'équipe vers le domicile pour l'estimation d'arrivée vue par le patient. Positions
/// arrondies (~100 m) : un nouveau calcul seulement quand l'équipe a vraiment avancé.
final approachPlanProvider = FutureProvider.autoDispose.family<RoutePlan, (double, double, double, double)>(
    (ref, key) => ref.read(routingServiceProvider).route(LatLng(key.$1, key.$2), LatLng(key.$3, key.$4)));

/// Appel du service d'itinéraire.
class RoutingService {
  RoutingService({Dio? dio, String? baseUrl})
      : _dio = dio ?? Dio(BaseOptions(connectTimeout: const Duration(seconds: 8), receiveTimeout: const Duration(seconds: 12))),
        _baseUrl = baseUrl ?? RoutingEnv.baseUrl;

  final Dio _dio;
  final String _baseUrl;

  Future<RoutePlan> route(LatLng from, LatLng to) async {
    final coordinates = '${from.longitude},${from.latitude};${to.longitude},${to.latitude}';
    try {
      final response = await _dio.get<Map<String, dynamic>>(
        '$_baseUrl/route/v1/driving/$coordinates',
        queryParameters: {'overview': 'full', 'geometries': 'geojson', 'steps': 'true'},
      );
      return RoutePlan.fromOsrm(response.data ?? const {});
    } on DioException {
      throw const RoutingException('Itinéraire routier indisponible (pas de connexion ou service injoignable).');
    }
  }
}
