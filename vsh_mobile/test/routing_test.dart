import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';
import 'package:vsh_mobile/homecare/routing.dart';

/// Réponse OSRM `route/v1/driving` réduite : départ, virage à gauche, rond-point, arrivée (Niamey).
Map<String, dynamic> _osrm() => {
      'code': 'Ok',
      'routes': [
        {
          'distance': 1800.0,
          'duration': 300.0,
          'geometry': {
            'type': 'LineString',
            'coordinates': [
              [2.1000, 13.5000],
              [2.1050, 13.5000],
              [2.1050, 13.5060],
              [2.1100, 13.5060],
            ],
          },
          'legs': [
            {
              'steps': [
                {'distance': 540.0, 'name': 'Boulevard de l’Indépendance', 'maneuver': {'type': 'depart', 'location': [2.1000, 13.5000]}},
                {'distance': 660.0, 'name': 'Rue du Grand Marché', 'maneuver': {'type': 'turn', 'modifier': 'left', 'location': [2.1050, 13.5000]}},
                {'distance': 600.0, 'name': '', 'maneuver': {'type': 'roundabout', 'modifier': 'right', 'exit': 2, 'location': [2.1050, 13.5060]}},
                {'distance': 0.0, 'name': '', 'maneuver': {'type': 'arrive', 'location': [2.1100, 13.5060]}},
              ],
            },
          ],
        },
      ],
    };

void main() {
  test('lecture d’une réponse OSRM : tracé, longueur, durée et consignes en français', () {
    final plan = RoutePlan.fromOsrm(_osrm());
    expect(plan.points, hasLength(4));
    expect(plan.points.first, const LatLng(13.5, 2.1));
    expect(plan.distanceM, 1800);
    expect(plan.steps.map((s) => s.instruction), [
      'Partez sur Boulevard de l’Indépendance',
      'Tournez à gauche sur Rue du Grand Marché',
      'Au rond-point, prenez la 2e sortie',
      'Vous êtes arrivé à destination',
    ]);
    expect(plan.steps.map((s) => s.icon), ['depart', 'left', 'roundabout', 'arrive']);
  });

  test('réponse sans itinéraire refusée', () {
    expect(() => RoutePlan.fromOsrm({'code': 'NoRoute', 'routes': []}), throwsA(isA<RoutingException>()));
  });

  test('prochaine consigne et distance restante le long du tracé', () {
    final plan = RoutePlan.fromOsrm(_osrm());
    const start = LatLng(13.5, 2.1010);
    expect(plan.nextStep(start)!.instruction, startsWith('Tournez à gauche'));
    final remaining = plan.remainingFrom(start);
    expect(remaining, lessThan(plan.distanceM + 200));
    expect(remaining, greaterThan(1000));

    // Après le virage : la consigne suivante est le rond-point.
    expect(plan.nextStep(const LatLng(13.5030, 2.1050))!.icon, 'roundabout');
  });

  test('sortie d’itinéraire détectée au-delà de quelques dizaines de mètres', () {
    final plan = RoutePlan.fromOsrm(_osrm());
    expect(distanceToPolyline(const LatLng(13.5, 2.1020), plan.points), lessThan(5));
    expect(distanceToPolyline(const LatLng(13.5020, 2.1020), plan.points), greaterThan(150));
  });

  test('cap et durée lisibles', () {
    expect(bearing(const LatLng(13.5, 2.1), const LatLng(13.6, 2.1)), closeTo(0, 0.5));
    expect(bearing(const LatLng(13.5, 2.1), const LatLng(13.5, 2.2)), closeTo(90, 0.5));
    expect(formatDuration(30), '1 min');
    expect(formatDuration(720), '12 min');
    expect(formatDuration(3900), '1 h 05');
  });
}
