import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:latlong2/latlong.dart';

import '../homecare/location_service.dart';
import '../homecare/routing.dart';
import '../homecare/visit_map.dart';
import '../homecare/track_recorder.dart';
import '../network/api_client.dart';
import '../notifications/notifications_screen.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'patient_appointments_screen.dart';
import 'patient_space_repository.dart';
import 'patient_widgets.dart';

/// Visites à domicile du dossier affiché : avancement de la visite en cours, historique.
class PatientHomecareScreen extends ConsumerWidget {
  const PatientHomecareScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(currentPatientProvider);
    final visits = patient == null
        ? const <Map<String, dynamic>>[]
        : [...ref.watch(patientItemsProvider((PatientSpaceRepository.visits, patient['id'] as String))).value ?? const <Map<String, dynamic>>[]]
      ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
    final canAsk = patient?['status'] == 'ACTIVE';
    final hasOpen = visits.any((v) => openVisits.contains(v['status']));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Visites à domicile'),
        actions: const [NotificationBell(), AccountMenu(), SizedBox(width: 4)],
        bottom: const PatientSwitcher(),
      ),
      floatingActionButton: canAsk && !hasOpen
          ? FloatingActionButton.extended(
              onPressed: () => context.push('/p/homecare/new'),
              icon: const Icon(Icons.add_home_rounded),
              label: const Text('Demander une visite'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: () => refreshPatientSpace(ref, context),
        child: ListView(padding: const EdgeInsets.only(bottom: 96), children: [
          const OfflineBanner(),
          if (patient != null) PendingNotice(patient: patient),
          const _EmergencyNotice(),
          if (visits.isEmpty)
            EmptyState(
              icon: Icons.home_work_outlined,
              title: 'Aucune visite',
              text: canAsk ? 'Demandez une visite : la clinique organise le passage d’une équipe de soins.' : null,
            ),
          for (final v in visits) _VisitCard(visit: v),
        ]),
      ),
    );
  }
}

class _EmergencyNotice extends StatelessWidget {
  const _EmergencyNotice();

  @override
  Widget build(BuildContext context) {
    final c = context.vsh;
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: c.dangerSoft, borderRadius: BorderRadius.circular(12)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.warning_amber_rounded, color: c.danger),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            'Urgence vitale (malaise grave, difficulté à respirer, saignement abondant) : n’attendez pas, rendez-vous immédiatement aux urgences les plus proches.',
            style: TextStyle(color: c.danger),
          ),
        ),
      ]),
    );
  }
}

/// Étapes de la visite, de la demande à la fin des soins.
class VisitProgress extends StatelessWidget {
  const VisitProgress({super.key, required this.visit});

  final Map<String, dynamic> visit;

  @override
  Widget build(BuildContext context) {
    final status = visit['status'] as String?;
    final finished = status == 'TERMINEE' || status == 'FACTUREE';
    final current = visitSteps.indexWhere((s) => s.$1 == (status == 'NOUVELLE' ? 'EN_ATTENTE' : status));
    final c = context.vsh;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      for (final (i, (_, label)) in visitSteps.indexed)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(children: [
            Icon(
              finished || i < current
                  ? Icons.check_circle_rounded
                  : i == current
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_unchecked_rounded,
              size: 20,
              color: finished || i < current ? c.success : (i == current ? c.info : c.textMuted),
            ),
            const SizedBox(width: 10),
            Text(label,
                style: TextStyle(
                  fontWeight: i == current && !finished ? FontWeight.w700 : FontWeight.w400,
                  color: i > current && !finished ? c.textMuted : null,
                )),
          ]),
        ),
      if (visit['team'] is Map) ...[const SizedBox(height: 6), Text('Équipe : ${(visit['team'] as Map)['label']}')],
    ]);
  }
}

/// Équipe en route : sa dernière position sur la carte, la distance et l'heure d'arrivée estimée
/// (itinéraire routier, sinon vol d'oiseau). Actualisé toutes les 30 secondes tant que l'équipe roule.
class TeamApproach extends ConsumerStatefulWidget {
  const TeamApproach({super.key, required this.visit});

  final Map<String, dynamic> visit;

  @override
  ConsumerState<TeamApproach> createState() => _TeamApproachState();
}

class _TeamApproachState extends ConsumerState<TeamApproach> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) async {
      try {
        await ref.read(patientSpaceProvider).refresh();
      } catch (_) {
        // Hors ligne : la dernière position reçue reste affichée, avec son heure.
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  static double _round(num value) => (value * 1000).roundToDouble() / 1000;

  @override
  Widget build(BuildContext context) {
    final approach = (widget.visit['team_approach'] as Map?)?.cast<String, dynamic>();
    final home = (approach?['home'] as Map?)?.cast<String, dynamic>();
    final colors = context.vsh;
    if (approach == null || home == null) {
      return Text('L’équipe est partie. Sa position s’affichera ici dès qu’elle sera connue.', style: TextStyle(color: colors.textMuted));
    }
    final team = GeoFix(
      (approach['latitude'] as num).toDouble(),
      (approach['longitude'] as num).toDouble(),
      (approach['accuracy_m'] as num?)?.toDouble(),
      parseDate(approach['captured_at']) ?? DateTime.now(),
    );
    final homePoint = LatLng((home['latitude'] as num).toDouble(), (home['longitude'] as num).toDouble());
    final plan = ref.watch(approachPlanProvider((_round(team.latitude), _round(team.longitude), _round(homePoint.latitude), _round(homePoint.longitude))));
    final straight = ((approach['distance_m'] as num?) ?? 0).toDouble();
    final (String distance, String? eta) = plan.maybeWhen(
      data: (p) => (
        formatDistance(p.distanceM),
        DateFormat.Hm('fr').format(DateTime.now().add(Duration(seconds: p.durationS.round()))),
      ),
      orElse: () => ('${formatDistance(straight)} à vol d’oiseau', null),
    );
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      VisitMap(home: homePoint, me: team),
      const SizedBox(height: 8),
      Text(
        eta == null ? 'L’équipe est à $distance.' : 'L’équipe est à $distance : arrivée prévue vers $eta.',
        style: const TextStyle(fontWeight: FontWeight.w700),
      ),
      Text(
        approach['stale'] == true
            ? 'Dernière position reçue ${formatWhen(team.capturedAt)} (réseau de l’équipe faible).'
            : 'Position mise à jour ${formatWhen(team.capturedAt)}. Tenez-vous prêt à accueillir l’équipe.',
        style: TextStyle(color: colors.textMuted),
      ),
    ]);
  }
}

class _VisitCard extends ConsumerWidget {
  const _VisitCard({required this.visit});

  final Map<String, dynamic> visit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final v = visit;
    final status = v['status'] as String?;
    final created = parseDate(v['created_at']);
    final muted = context.vsh.textMuted;
    return Card(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusChip(visitLabels[status] ?? '$status', tone: visitTone(status)),
            if (v['urgency'] == 'URGENTE') const StatusChip('Urgent', tone: Tone.danger),
          ]),
          const SizedBox(height: 8),
          Text('${v['reason'] ?? ''}'),
          if (created != null) Text('Demandée le ${formatDateTime(created)}', style: TextStyle(color: muted)),
          if (openVisits.contains(status)) ...[const SizedBox(height: 12), VisitProgress(visit: v)],
          if (status == 'EN_ROUTE') ...[const SizedBox(height: 12), TeamApproach(visit: v)],
          if (parseDate(v['arrived_at']) != null) Text('Arrivée de l’équipe : ${formatDateTime(parseDate(v['arrived_at'])!)}', style: TextStyle(color: muted)),
          if (v['cancel_reason'] != null) Text('Annulée : ${v['cancel_reason']}', style: TextStyle(color: muted)),
          if (patientCancellableVisits.contains(status))
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _cancel(context, ref),
                icon: const Icon(Icons.cancel_outlined),
                label: const Text('Annuler la visite'),
              ),
            ),
        ]),
      ),
    );
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final reason = await askReason(context, title: 'Annuler la visite ?', confirm: 'Annuler la visite');
    if (reason == null || !context.mounted) return;
    try {
      await ref.read(patientSpaceProvider).cancelVisit(visit['id'] as String, reason: reason);
      if (context.mounted) showMessage(context, 'Visite annulée. L’équipe est prévenue.');
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message);
    }
  }
}

/// Demande de visite : motif, urgence, téléphone à joindre, adresse, repère et position du
/// domicile (préremplis depuis le dossier ; la position GPS aide l'équipe à trouver la maison).
class NewVisitScreen extends ConsumerStatefulWidget {
  const NewVisitScreen({super.key});

  @override
  ConsumerState<NewVisitScreen> createState() => _NewVisitScreenState();
}

class _NewVisitScreenState extends ConsumerState<NewVisitScreen> {
  final _form = GlobalKey<FormState>();
  final _reason = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _landmark = TextEditingController();
  bool _urgent = false;
  bool _busy = false;
  bool _locating = false;
  GeoFix? _fix;
  String? _gpsMessage;
  String? _prefilledFor;

  @override
  void dispose() {
    _reason.dispose();
    _phone.dispose();
    _address.dispose();
    _landmark.dispose();
    super.dispose();
  }

  /// Préremplissage depuis le dossier (adresse principale) ; sans réseau, les champs restent vides.
  Future<void> _prefill(Map<String, dynamic> patient) async {
    final id = patient['id'] as String;
    if (_prefilledFor == id) return;
    _prefilledFor = id;
    _phone.text = '${patient['phone'] ?? ''}';
    try {
      final record = await ref.read(patientSpaceProvider).record(id);
      final addresses = ((record['addresses'] as List?) ?? const []).map((a) => (a as Map).cast<String, dynamic>()).toList();
      if (addresses.isEmpty || !mounted) return;
      final home = addresses.firstWhere((a) => a['is_primary'] == true, orElse: () => addresses.first);
      setState(() {
        _address.text = [home['address_line'], home['district'], home['city']].whereType<String>().where((s) => s.isNotEmpty).join(', ');
        _landmark.text = '${home['landmark'] ?? ''}';
        final lat = (home['latitude'] as num?)?.toDouble();
        final lng = (home['longitude'] as num?)?.toDouble();
        if (lat != null && lng != null) {
          _fix = GeoFix(lat, lng, (home['gps_accuracy_m'] as num?)?.toDouble(), parseDate(home['gps_captured_at']) ?? DateTime.now());
          _gpsMessage = 'Position enregistrée dans votre dossier.';
        }
      });
    } catch (_) {}
  }

  Future<void> _locate() async {
    setState(() {
      _locating = true;
      _gpsMessage = null;
    });
    final result = await ref.read(locationServiceProvider).best();
    if (!mounted) return;
    setState(() {
      _locating = false;
      if (result.fix != null) {
        _fix = result.fix;
        final accuracy = result.fix!.accuracyM;
        _gpsMessage = accuracy == null ? 'Position relevée.' : 'Position relevée (précision ${accuracy.round()} m).';
      } else {
        _gpsMessage = result.message;
      }
    });
  }

  Future<void> _submit(Map<String, dynamic> patient) async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    final fix = _fix;
    try {
      await ref.read(patientSpaceProvider).requestVisit({
        'patient_id': patient['id'],
        'reason': _reason.text.trim(),
        'urgency': _urgent ? 'URGENTE' : 'NORMALE',
        'contact_phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'address_text': _address.text.trim().isEmpty ? null : _address.text.trim(),
        'landmark': _landmark.text.trim().isEmpty ? null : _landmark.text.trim(),
        if (fix != null) ...{
          'latitude': double.parse(fix.latitude.toStringAsFixed(7)),
          'longitude': double.parse(fix.longitude.toStringAsFixed(7)),
          if (fix.accuracyM != null) 'gps_accuracy_m': double.parse(fix.accuracyM!.toStringAsFixed(1)),
          'gps_captured_at': fix.capturedAt.toUtc().toIso8601String(),
        },
      });
      if (!mounted) return;
      showMessage(context, 'Demande envoyée. Vous serez prévenu quand une équipe sera désignée.');
      context.pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.code == 'HOMECARE_ALREADY_OPEN' ? 'Une visite est déjà en cours pour ce dossier.' : e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final patient = ref.watch(currentPatientProvider);
    if (patient != null) _prefill(patient);
    final muted = context.vsh.textMuted;
    return Scaffold(
      appBar: AppBar(title: const Text('Demander une visite')),
      body: patient == null
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _form,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Text('Pour ${patient['first_name']} ${patient['last_name']}', style: TextStyle(color: muted)),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _reason,
                  maxLength: 2000,
                  maxLines: 3,
                  decoration: const InputDecoration(labelText: 'Motif de la demande', helperText: 'Ex. pansement, injection, surveillance après une hospitalisation.'),
                  validator: (v) => (v ?? '').trim().isEmpty ? 'Indiquez le motif de la demande.' : null,
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _urgent,
                  onChanged: (v) => setState(() => _urgent = v),
                  title: const Text('C’est urgent'),
                  subtitle: const Text('La régulation traitera la demande en priorité.'),
                ),
                TextFormField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Téléphone à joindre'),
                  validator: (v) => (v ?? '').replaceAll(RegExp(r'\s'), '').length < 8 ? 'Indiquez un numéro pour joindre le patient.' : null,
                ),
                const SizedBox(height: 12),
                TextFormField(controller: _address, maxLength: 255, decoration: const InputDecoration(labelText: 'Adresse')),
                TextFormField(
                  controller: _landmark,
                  maxLength: 255,
                  decoration: const InputDecoration(labelText: 'Repère', helperText: 'Ex. derrière la mosquée, portail bleu.'),
                ),
                const SizedBox(height: 8),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      const Text('Position du domicile (recommandée)', style: TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 4),
                      Text('Chez vous, relevez votre position : l’équipe vous trouvera plus facilement.', style: TextStyle(color: muted)),
                      if (_gpsMessage != null) ...[const SizedBox(height: 8), Text(_gpsMessage!)],
                      const SizedBox(height: 8),
                      OutlinedButton.icon(
                        onPressed: _locating ? null : _locate,
                        icon: _locating
                            ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(Icons.my_location_rounded),
                        label: Text(_locating ? 'Recherche de la position…' : 'Ma position actuelle'),
                      ),
                    ]),
                  ),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : () => _submit(patient),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                  child: _busy
                      ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                      : const Text('Envoyer la demande'),
                ),
              ]),
            ),
    );
  }
}
