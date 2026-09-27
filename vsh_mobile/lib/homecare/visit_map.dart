import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import '../theme/app_theme.dart';
import 'location_service.dart';
import 'tile_prefetch.dart';

/// Carte OpenStreetMap : domicile du patient et position de l'équipe. Sans réseau, les fonds de
/// carte manquent mais les repères, la distance et les coordonnées restent affichés.
class VisitMap extends StatelessWidget {
  const VisitMap({super.key, required this.home, this.me, this.homeAccuracyM});

  final LatLng home;
  final GeoFix? me;
  final double? homeAccuracyM;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final mine = me == null ? null : LatLng(me!.latitude, me!.longitude);
    final points = [home, ?mine];
    final camera = points.length > 1
        ? CameraFit.coordinates(coordinates: points, padding: const EdgeInsets.all(48), maxZoom: 17)
        : null;
    return ClipRRect(
      borderRadius: BorderRadius.circular(10),
      child: SizedBox(
        height: 220,
        child: Semantics(
          label: 'Carte du domicile${mine != null ? ' et de votre position' : ''}',
          child: FlutterMap(
            options: MapOptions(
              initialCenter: home,
              initialZoom: 16,
              initialCameraFit: camera,
              interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
            ),
            children: [
              TileLayer(
                urlTemplate: MapTiles.urlTemplate,
                userAgentPackageName: MapTiles.userAgentPackageName,
                maxNativeZoom: 19,
              ),
              if (homeAccuracyM != null && homeAccuracyM! > 0)
                CircleLayer(circles: [
                  CircleMarker(
                    point: home,
                    radius: homeAccuracyM!,
                    useRadiusInMeter: true,
                    color: colors.success.withValues(alpha: 0.12),
                    borderColor: colors.success,
                    borderStrokeWidth: 1,
                  ),
                ]),
              MarkerLayer(markers: [
                Marker(
                  point: home,
                  width: 40,
                  height: 40,
                  alignment: Alignment.topCenter,
                  child: Icon(Icons.home_rounded, size: 36, color: VshColors.brandOrange, semanticLabel: 'Domicile'),
                ),
                if (mine != null)
                  Marker(
                    point: mine,
                    width: 22,
                    height: 22,
                    child: Container(
                      decoration: BoxDecoration(
                        color: colors.info,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 3),
                      ),
                    ),
                  ),
              ]),
              const RichAttributionWidget(attributions: [TextSourceAttribution('© contributeurs OpenStreetMap')]),
            ],
          ),
        ),
      ),
    );
  }
}

/// Distance à vol d'oiseau (haversine), comme côté serveur (`Geo::distance`).
double distanceM(double lat1, double lng1, double lat2, double lng2) =>
    const Distance(roundResult: false).as(LengthUnit.Meter, LatLng(lat1, lng1), LatLng(lat2, lng2));

String formatDistance(double meters) =>
    meters < 1000 ? '${meters.round()} m' : '${(meters / 1000).toStringAsFixed(meters < 10000 ? 1 : 0).replaceAll('.', ',')} km';

/// Ouvre l'application de navigation du téléphone vers le domicile.
Future<bool> openDirections(double lat, double lng) async {
  final geo = Uri.parse('geo:$lat,$lng?q=$lat,$lng');
  if (await launchUrl(geo, mode: LaunchMode.externalApplication).catchError((_) => false)) return true;
  return launchUrl(
    Uri.parse('https://www.openstreetmap.org/directions?route=%3B$lat%2C$lng'),
    mode: LaunchMode.externalApplication,
  ).catchError((_) => false);
}

Future<bool> callNumber(String phone) =>
    launchUrl(Uri(scheme: 'tel', path: phone.replaceAll(' ', ''))).catchError((_) => false);
