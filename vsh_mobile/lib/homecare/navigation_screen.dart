import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';
import 'package:latlong2/latlong.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'location_service.dart';
import 'tile_prefetch.dart';
import 'routing.dart';
import 'track_recorder.dart';
import 'visit.dart';
import 'visit_map.dart';

final _navVisitProvider = StreamProvider.family<Visit?, String>((ref, id) => ref.watch(homecareRepositoryProvider).watchVisit(id));

/// Navigation vers le domicile du patient sans quitter l'application :
/// - tracé routier calculé par OSRM (données OpenStreetMap), consigne suivante en français ;
/// - position suivie en direct, recalcul automatique en cas de sortie d'itinéraire ;
/// - sans réseau : direction et distance à vol d'oiseau (les fonds de carte déjà vus restent en cache) ;
/// - écran maintenu allumé pendant la navigation.
///
/// Le trajet de l'équipe (visite « en route ») continue d'être enregistré par le TrackRecorder.
class NavigationScreen extends ConsumerStatefulWidget {
  const NavigationScreen({super.key, required this.visitId});

  final String visitId;

  @override
  ConsumerState<NavigationScreen> createState() => _NavigationScreenState();
}

class _NavigationScreenState extends ConsumerState<NavigationScreen> {
  /// Distance au domicile en deçà de laquelle l'équipe est considérée arrivée.
  static const arrivedWithinM = 60.0;

  /// Écart au tracé qui déclenche un nouveau calcul.
  static const offRouteM = 60.0;

  final _map = MapController();
  StreamSubscription<Position>? _positions;
  LatLng? _me;
  double? _accuracy;
  String? _gpsProblem;
  RoutePlan? _plan;
  String? _routeProblem;
  bool _routing = false;
  DateTime? _routedAt;
  bool _follow = true;
  bool _mapReady = false;

  @override
  void initState() {
    super.initState();
    WakelockPlus.enable().catchError((_) {});
    _startTracking();
  }

  @override
  void dispose() {
    WakelockPlus.disable().catchError((_) {});
    _positions?.cancel();
    super.dispose();
  }

  Future<void> _startTracking() async {
    final problem = await ref.read(locationServiceProvider).ensurePermission();
    if (!mounted) return;
    if (problem != null) {
      setState(() => _gpsProblem = problem == GeoProblem.serviceDisabled
          ? 'Activez la localisation du téléphone pour être guidé.'
          : 'Autorisez la localisation pour être guidé jusqu’au domicile.');
      return;
    }
    _positions = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, distanceFilter: 5),
    ).listen(_onPosition, onError: (_) {
      if (mounted) setState(() => _gpsProblem = 'Position GPS introuvable pour le moment.');
    });
  }

  void _onPosition(Position p) {
    if (!mounted || (p.latitude.abs() < 1e-6 && p.longitude.abs() < 1e-6)) return;
    final me = LatLng(p.latitude, p.longitude);
    setState(() {
      _me = me;
      _accuracy = p.accuracy;
      _gpsProblem = null;
    });
    if (_follow && _mapReady) _map.move(me, math.max(_map.camera.zoom, 16));
    final home = _home();
    if (home == null) return;
    final plan = _plan;
    final stale = _routedAt == null || DateTime.now().difference(_routedAt!) > const Duration(seconds: 20);
    if (plan == null && !_routing && (_routeProblem == null || stale)) {
      _route(me, home);
    } else if (plan != null && !_routing && stale && distanceToPolyline(me, plan.points) > offRouteM) {
      _route(me, home);
    }
  }

  LatLng? _home() {
    final visit = ref.read(_navVisitProvider(widget.visitId)).value;
    return visit != null && visit.hasLocation ? LatLng(visit.latitude!, visit.longitude!) : null;
  }

  Future<void> _route(LatLng from, LatLng to) async {
    setState(() => _routing = true);
    try {
      final plan = await ref.read(routingServiceProvider).route(from, to);
      if (!mounted) return;
      setState(() {
        _plan = plan;
        _routeProblem = null;
      });
    } on RoutingException catch (e) {
      if (mounted) setState(() => _routeProblem = e.message);
    } finally {
      _routedAt = DateTime.now();
      if (mounted) setState(() => _routing = false);
    }
  }

  void _recenter() {
    setState(() => _follow = true);
    final me = _me;
    if (me != null && _mapReady) _map.move(me, math.max(_map.camera.zoom, 16));
  }

  @override
  Widget build(BuildContext context) {
    final visit = ref.watch(_navVisitProvider(widget.visitId)).value;
    final colors = context.vsh;
    if (visit == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (!visit.hasLocation) {
      return Scaffold(
        appBar: AppBar(title: const Text('Itinéraire')),
        body: const Padding(
          padding: EdgeInsets.all(24),
          child: Text('Pas de position GPS enregistrée pour ce domicile. Appelez le patient ou suivez l’adresse et le repère indiqués.'),
        ),
      );
    }
    final home = LatLng(visit.latitude!, visit.longitude!);
    final me = _me;
    final plan = _plan;
    final straight = me == null ? null : distanceM(me.latitude, me.longitude, home.latitude, home.longitude);
    final arrived = straight != null && straight <= arrivedWithinM;
    final remaining = me != null && plan != null ? plan.remainingFrom(me) : null;

    return Scaffold(
      appBar: AppBar(
        title: Text(visit.patientName, overflow: TextOverflow.ellipsis),
        actions: [
          IconButton(
            tooltip: 'Recalculer l’itinéraire',
            onPressed: me == null || _routing ? null : () => _route(me, home),
            icon: _routing
                ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.alt_route_rounded),
          ),
          PopupMenuButton<String>(
            tooltip: 'Plus d’options',
            onSelected: (value) async {
              if (value == 'external' && !await openDirections(home.latitude, home.longitude) && context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Aucune application de navigation disponible.')));
              }
              if (value == 'call' && visit.contactPhone != null) await callNumber(visit.contactPhone!);
            },
            itemBuilder: (_) => [
              if (visit.contactPhone != null && visit.contactPhone!.isNotEmpty)
                PopupMenuItem(value: 'call', child: ListTile(leading: const Icon(Icons.call_rounded), title: Text('Appeler ${visit.contactPhone}'))),
              const PopupMenuItem(value: 'external', child: ListTile(leading: Icon(Icons.open_in_new_rounded), title: Text('Ouvrir dans une autre application'))),
            ],
          ),
        ],
      ),
      body: Stack(children: [
        FlutterMap(
          mapController: _map,
          options: MapOptions(
            initialCenter: me ?? home,
            initialZoom: 16,
            interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
            onMapReady: () => _mapReady = true,
            onPositionChanged: (camera, hasGesture) {
              if (hasGesture && _follow) setState(() => _follow = false);
            },
          ),
          children: [
            TileLayer(
              urlTemplate: MapTiles.urlTemplate,
              userAgentPackageName: MapTiles.userAgentPackageName,
              maxNativeZoom: 19,
            ),
            if (plan != null)
              PolylineLayer(polylines: [
                Polyline(points: plan.points, strokeWidth: 7, color: colors.info, borderStrokeWidth: 2, borderColor: Colors.white),
              ])
            else if (me != null)
              PolylineLayer(polylines: [
                Polyline(points: [me, home], strokeWidth: 4, color: colors.warning, pattern: StrokePattern.dashed(segments: const [14, 10])),
              ]),
            MarkerLayer(markers: [
              Marker(
                point: home,
                width: 44,
                height: 44,
                alignment: Alignment.topCenter,
                child: const Icon(Icons.home_rounded, size: 40, color: VshColors.brandOrange, semanticLabel: 'Domicile'),
              ),
              if (me != null)
                Marker(
                  point: me,
                  width: 26,
                  height: 26,
                  child: Container(
                    decoration: BoxDecoration(color: colors.info, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3)),
                  ),
                ),
            ]),
            const RichAttributionWidget(attributions: [TextSourceAttribution('© contributeurs OpenStreetMap')]),
          ],
        ),
        Positioned(
          top: 12,
          left: 12,
          right: 12,
          child: _GuidanceCard(me: me, home: home, plan: plan, arrived: arrived, gpsProblem: _gpsProblem, routeProblem: _routeProblem, straightM: straight),
        ),
        if (!_follow && me != null)
          Positioned(
            right: 16,
            bottom: 150,
            child: FloatingActionButton.small(
              heroTag: 'recenter',
              tooltip: 'Recentrer sur ma position',
              onPressed: _recenter,
              child: const Icon(Icons.my_location_rounded),
            ),
          ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: _SummaryPanel(
            visit: visit,
            remainingM: remaining ?? straight,
            durationS: plan == null || remaining == null || plan.distanceM == 0 ? null : plan.durationS * remaining / plan.distanceM,
            roadDistance: remaining != null,
            accuracyM: _accuracy,
            arrived: arrived,
          ),
        ),
      ]),
    );
  }
}

/// Consigne en haut de l'écran : prochaine manœuvre, direction à vol d'oiseau, ou problème GPS.
class _GuidanceCard extends StatelessWidget {
  const _GuidanceCard({
    required this.me,
    required this.home,
    required this.plan,
    required this.arrived,
    required this.gpsProblem,
    required this.routeProblem,
    required this.straightM,
  });

  final LatLng? me;
  final LatLng home;
  final RoutePlan? plan;
  final bool arrived;
  final String? gpsProblem;
  final String? routeProblem;
  final double? straightM;

  static IconData _icon(String name) => switch (name) {
        'left' => Icons.turn_left_rounded,
        'right' => Icons.turn_right_rounded,
        'uturn' => Icons.u_turn_left_rounded,
        'roundabout' => Icons.roundabout_right_rounded,
        'arrive' => Icons.flag_rounded,
        'depart' => Icons.navigation_rounded,
        _ => Icons.straight_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final colors = context.vsh;
    final (IconData icon, String title, String? subtitle, Color background) = switch (this) {
      _ when gpsProblem != null => (Icons.location_disabled_rounded, gpsProblem!, null, colors.warningSoft),
      _ when me == null => (Icons.gps_not_fixed_rounded, 'Recherche de votre position…', null, scheme.surface),
      _ when arrived => (Icons.flag_rounded, 'Vous êtes arrivé', 'Signalez votre arrivée depuis la fiche de la visite.', colors.successSoft),
      _ when plan != null => () {
          final step = plan!.nextStep(me!);
          if (step == null) return (Icons.navigation_rounded, 'Suivez le tracé', null, scheme.surface);
          final toStep = distanceM(me!.latitude, me!.longitude, step.location.latitude, step.location.longitude);
          return (_icon(step.icon), step.instruction, 'Dans ${formatDistance(toStep)}', scheme.surface);
        }(),
      _ => (
          Icons.explore_rounded,
          'Direction ${_cardinal(bearing(me!, home))} · ${formatDistance(straightM ?? 0)} à vol d’oiseau',
          routeProblem ?? 'Calcul de l’itinéraire…',
          colors.warningSoft,
        ),
    };
    return Material(
      elevation: 4,
      color: background,
      borderRadius: BorderRadius.circular(16),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Icon(icon, size: 40),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              if (subtitle != null) Text(subtitle, style: TextStyle(color: colors.textMuted)),
            ]),
          ),
        ]),
      ),
    );
  }

  static String _cardinal(double degrees) {
    const names = ['nord', 'nord-est', 'est', 'sud-est', 'sud', 'sud-ouest', 'ouest', 'nord-ouest'];
    return names[((degrees + 22.5) % 360 ~/ 45)];
  }
}

/// Bas de l'écran : distance et heure d'arrivée, adresse et repère, précision GPS.
class _SummaryPanel extends StatelessWidget {
  const _SummaryPanel({
    required this.visit,
    required this.remainingM,
    required this.durationS,
    required this.roadDistance,
    required this.accuracyM,
    required this.arrived,
  });

  final Visit visit;
  final double? remainingM;
  final double? durationS;
  final bool roadDistance;
  final double? accuracyM;
  final bool arrived;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final eta = durationS == null ? null : DateTime.now().add(Duration(seconds: durationS!.round()));
    return Material(
      elevation: 8,
      color: Theme.of(context).colorScheme.surface,
      borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              Expanded(
                child: Text(
                  remainingM == null
                      ? '—'
                      : [
                          formatDistance(remainingM!),
                          if (durationS != null) formatDuration(durationS!),
                          if (eta != null) 'arrivée vers ${DateFormat.Hm('fr').format(eta)}',
                        ].join(' · '),
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              if (accuracyM != null && accuracyM! > 50)
                Tooltip(
                  message: 'Précision GPS ${accuracyM!.round()} m',
                  child: Icon(Icons.gps_not_fixed_rounded, color: colors.warning),
                ),
            ]),
            if (!roadDistance && remainingM != null) Text('Distance à vol d’oiseau', style: TextStyle(color: colors.textMuted, fontSize: 13)),
            if (visit.addressText != null) Text(visit.addressText!, style: TextStyle(color: colors.textMuted)),
            if (visit.landmark != null) Text('Repère : ${visit.landmark}', style: TextStyle(color: colors.textMuted)),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: arrived
                  ? FilledButton.icon(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.check_circle_rounded),
                      label: const Text('Je suis arrivé : retour à la visite'),
                    )
                  : OutlinedButton.icon(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.assignment_rounded),
                      label: const Text('Retour à la visite'),
                    ),
            ),
          ]),
        ),
      ),
    );
  }
}
