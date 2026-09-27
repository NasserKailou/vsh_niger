import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

import '../authentication/auth_repository.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';
import '../treatments/treatments_screen.dart';
import 'clinical_forms.dart';
import 'location_service.dart';
import 'tour_screen.dart';
import 'track_recorder.dart';
import 'visit.dart';
import 'visit_map.dart';

final _visitProvider = StreamProvider.family<Visit?, String>((ref, id) => ref.watch(homecareRepositoryProvider).watchVisit(id));
final _vitalsProvider = StreamProvider.family<List<Map<String, dynamic>>, String>((ref, id) => ref.watch(homecareRepositoryProvider).watchVitals(id));
final _notesProvider = StreamProvider.family<List<Map<String, dynamic>>, String>((ref, id) => ref.watch(homecareRepositoryProvider).watchNotes(id));

class VisitScreen extends ConsumerStatefulWidget {
  const VisitScreen({super.key, required this.id});
  final String id;

  @override
  ConsumerState<VisitScreen> createState() => _VisitScreenState();
}

class _VisitScreenState extends ConsumerState<VisitScreen> {
  bool _busy = false;
  String? _busyLabel;

  AuthUser? get _user => ref.watch(sessionProvider).value?.user;

  void _toast(String message) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));

  /// Relevé de position joint à l'action. Sans GPS, l'action part quand même.
  Future<GeoFix?> _position({required bool precise}) async {
    final recent = ref.read(trackRecorderProvider).lastFix.value;
    if (!precise && recent != null && DateTime.now().difference(recent.capturedAt) < const Duration(minutes: 2)) return recent;
    setState(() => _busyLabel = 'Relevé de la position GPS…');
    final result = await ref.read(locationServiceProvider).best(maxWait: Duration(seconds: precise ? 12 : 6));
    if (result.fix == null && mounted) _toast('${result.message} L’action est enregistrée sans position.');
    return result.fix;
  }

  Future<void> _act(Visit visit, String action) async {
    String? reason;
    if (action == 'release') {
      reason = await showTextSheet(context,
          title: 'Se désister', label: 'Motif', action: 'Remettre dans la file', help: 'La visite retourne dans la file d’attente.');
      if (reason == null) return;
    } else if (action == 'fail') {
      reason = await showTextSheet(context,
          title: 'Visite impossible', label: 'Motif (patient absent, adresse introuvable…)', action: 'Déclarer l’échec');
      if (reason == null) return;
    } else if (action == 'complete') {
      final ok = await _confirm('Terminer la visite ?', 'Vérifiez que les soins et constantes sont saisis. La consultation reste à clôturer par le médecin.');
      if (!ok) return;
    }
    if (!mounted) return;
    setState(() {
      _busy = true;
      _busyLabel = 'Enregistrement…';
    });
    try {
      final recorder = ref.read(trackRecorderProvider);
      await recorder.flush();
      final fix = await _position(precise: action == 'depart' || action == 'arrive');
      await ref.read(homecareRepositoryProvider).act(visit.id, action, fix: fix, reason: reason);
      ref.read(syncSchedulerProvider).poke();
      if (action == 'arrive' && fix != null && visit.hasLocation) {
        // Rayon d'arrivée de la clinique (`geo.arrival_radius_m`) : information seulement, jamais bloquant.
        final radius = await ref.read(referenceRepositoryProvider).number('geo.arrival_radius_m', 300);
        final d = distanceM(fix.latitude, fix.longitude, visit.latitude!, visit.longitude!) - (fix.accuracyM ?? 0);
        if (d > radius) _toast('Position à ${formatDistance(d)} du domicile enregistré. L’arrivée est enregistrée.');
      }
      if (action == 'complete' || action == 'fail' || action == 'release') await recorder.follow(null);
    } on StateError catch (e) {
      _toast(e.message);
    } on ArgumentError catch (e) {
      _toast('${e.message}');
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _busyLabel = null;
        });
      }
    }
  }

  Future<bool> _confirm(String title, String body) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(title),
          content: Text(body),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Confirmer')),
          ],
        ),
      ) ==
      true;

  Future<void> _addVitals(Visit visit) async {
    final result = await showVitalsSheet(context);
    if (result == null || visit.consultationId == null || visit.patientId == null) return;
    await ref
        .read(homecareRepositoryProvider)
        .addVitals(consultationId: visit.consultationId!, patientId: visit.patientId!, measures: result.values, notes: result.notes);
    ref.read(syncSchedulerProvider).poke();
    _toast('Constantes enregistrées.');
  }

  Future<void> _addCare(Visit visit) async {
    if (visit.consultationId == null || visit.patientId == null) return;
    final types = await ref.read(referenceRepositoryProvider).active('treatment_types');
    if (!mounted) return;
    final type = await pickTreatmentType(context, types);
    if (type == null || !mounted) return;
    String? observations;
    if (_user?.can('patients.medical.write') ?? false) {
      observations = await showTextSheet(context,
          title: '${type['label']}', label: 'Observations (facultatif)', action: 'Enregistrer le soin', required: false, maxLength: 5000);
      if (observations == null) return;
    }
    await ref.read(treatmentActionsProvider).recordDone(
          patientId: visit.patientId!,
          type: type,
          consultationId: visit.consultationId,
          dependsOnOp: await ref.read(homecareRepositoryProvider).pendingStart(visit.consultationId!),
          observations: observations,
          performerName: _user?.displayName,
        );
    ref.read(syncSchedulerProvider).poke();
    _toast('Soin enregistré.');
  }

  Future<void> _addNote(Visit visit) async {
    final text = await showTextSheet(context, title: 'Note de consultation', label: 'Observation', action: 'Enregistrer', maxLength: 10000);
    if (text == null || visit.consultationId == null || visit.patientId == null) return;
    await ref.read(homecareRepositoryProvider).addNote(consultationId: visit.consultationId!, patientId: visit.patientId!, content: text);
    ref.read(syncSchedulerProvider).poke();
    _toast('Note enregistrée.');
  }

  @override
  Widget build(BuildContext context) {
    final visit = ref.watch(_visitProvider(widget.id));
    return Scaffold(
      appBar: AppBar(title: const Text('Visite à domicile'), actions: const [SyncStatusBadge(), SizedBox(width: 8)]),
      body: visit.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => const Center(child: Text('Lecture de la visite impossible.')),
        data: (v) => v == null
            ? const Center(child: Text("Cette visite n'est plus disponible sur ce téléphone."))
            : !v.available
                ? const Center(
                    child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('Cette visite a été prise en charge par une autre équipe.', textAlign: TextAlign.center),
                  ))
                : _body(v),
      ),
      bottomNavigationBar: visit.value?.available == true ? _actions(visit.value!) : null,
    );
  }

  Widget _body(Visit v) {
    final colors = context.vsh;
    final canVitals = _user?.can('vitals.record') ?? false;
    // Notes : données médicales (D-010) dans la consultation ; droits vérifiés aussi par le serveur.
    final canNotes = (_user?.can('patients.medical.write') ?? false) && (_user?.can('consultations.update') ?? false);
    final canCare = _user?.can('treatments.perform') ?? false;
    return ValueListenableBuilder<GeoFix?>(
      valueListenable: ref.watch(trackRecorderProvider).lastFix,
      builder: (context, me, _) => ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          Expanded(child: Text(v.patientName, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700))),
          StatusChip(v.status),
        ]),
        Text([v.fileNumber, ?v.teamLabel].whereType<String>().join(' · '),
            style: TextStyle(color: colors.textMuted)),
        const SizedBox(height: 12),
        if (v.urgent)
          Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: colors.dangerSoft, borderRadius: BorderRadius.circular(6)),
            child: Text('Demande urgente', style: TextStyle(color: colors.danger, fontWeight: FontWeight.w700)),
          ),
        _Info('Motif', v.reason),
        if (v.addressText != null) _Info('Adresse', v.addressText!),
        if (v.landmark != null) _Info('Repère', v.landmark!),
        if (v.failureReason != null) _Info('Motif d’échec', v.failureReason!),
        if (v.contactPhone != null && v.contactPhone!.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: OutlinedButton.icon(
              onPressed: () => callNumber(v.contactPhone!),
              icon: const Icon(Icons.call_rounded),
              label: Text('Appeler ${v.contactPhone}'),
            ),
          ),
        if (v.hasLocation) ...[
          VisitMap(home: LatLng(v.latitude!, v.longitude!), me: me, homeAccuracyM: v.accuracyM),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: Text(
                me != null
                    ? 'À ${formatDistance(distanceM(me.latitude, me.longitude, v.latitude!, v.longitude!))} (vol d’oiseau)'
                    : 'Position du domicile : ${v.latitude!.toStringAsFixed(5)}, ${v.longitude!.toStringAsFixed(5)}',
                style: TextStyle(color: colors.textMuted),
              ),
            ),
          ]),
          const SizedBox(height: 8),
          // Guidage dans l'application ; une autre application reste proposée dans son menu.
          FilledButton.tonalIcon(
            onPressed: () => context.push('/visits/${v.id}/route'),
            icon: const Icon(Icons.navigation_rounded),
            label: const Text('Itinéraire vers le domicile'),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          ),
        ] else
          _Info('Position', 'Pas de position GPS enregistrée pour ce domicile.'),
        if (v.consultationId != null && (canVitals || canNotes || canCare)) ...[
          const SizedBox(height: 16),
          Text('Consultation à domicile', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          if (v.status == 'EN_COURS')
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (canVitals)
                OutlinedButton.icon(onPressed: () => _addVitals(v), icon: const Icon(Icons.monitor_heart_outlined), label: const Text('Constantes')),
              if (canCare)
                OutlinedButton.icon(onPressed: () => _addCare(v), icon: const Icon(Icons.healing_rounded), label: const Text('Soin réalisé')),
              if (canNotes)
                OutlinedButton.icon(onPressed: () => _addNote(v), icon: const Icon(Icons.edit_note_rounded), label: const Text('Note')),
            ]),
          _CareList(v.consultationId!),
          _VitalsList(v.consultationId!),
          _NotesList(v.consultationId!),
        ],
        const SizedBox(height: 16),
        Text('Historique', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        for (final h in v.history.reversed) _HistoryTile(h),
        if (v.history.isEmpty) Text('Aucune étape enregistrée.', style: TextStyle(color: colors.textMuted)),
        const SizedBox(height: 80),
      ]),
    );
  }

  Widget? _actions(Visit v) {
    if (!(_user?.can('homecare.intervene') ?? false)) return null;
    const primary = {
      'EN_ATTENTE': ('accept', 'Prendre en charge', Icons.assignment_turned_in_rounded),
      'PRISE_EN_CHARGE': ('depart', 'Partir vers le domicile', Icons.directions_car_rounded),
      'EN_ROUTE': ('arrive', 'Je suis arrivé', Icons.where_to_vote_rounded),
      'SUR_PLACE': ('start', 'Commencer les soins', Icons.medical_services_rounded),
      'EN_COURS': ('complete', 'Terminer la visite', Icons.check_circle_rounded),
    };
    final main = primary[v.status];
    final secondary = [
      if (VisitFlow.allowed('release', v.status)) ('release', 'Se désister'),
      if (VisitFlow.allowed('fail', v.status)) ('fail', 'Visite impossible'),
    ];
    if (main == null && secondary.isEmpty) return null;
    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_busyLabel != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2)),
              const SizedBox(width: 8),
              Text(_busyLabel!),
            ]),
          ),
        if (secondary.isNotEmpty)
          Row(children: [
            for (final (action, label) in secondary)
              Expanded(
                child: TextButton(
                  onPressed: _busy ? null : () => _act(v, action),
                  style: TextButton.styleFrom(foregroundColor: action == 'fail' ? context.vsh.danger : context.vsh.neutral),
                  child: Text(label),
                ),
              ),
          ]),
        if (main != null)
          FilledButton.icon(
            onPressed: _busy ? null : () => _act(v, main.$1),
            icon: Icon(main.$3),
            label: Text(main.$2),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
          ),
      ]),
    );
  }
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(color: context.vsh.textMuted, fontSize: 13)),
          Text(value, style: const TextStyle(fontSize: 16)),
        ]),
      );
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile(this.entry);
  final Map<String, dynamic> entry;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final at = DateTime.tryParse(entry['at'] as String? ?? '');
    final pending = entry['pending'] == true;
    final by = (entry['by'] as Map?)?['name'] as String?;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Icon(pending ? Icons.cloud_upload_outlined : Icons.circle, size: pending ? 16 : 10, color: pending ? colors.warning : colors.success),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(VisitFlow.labels[entry['to']] ?? '${entry['to']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            Text(
              [if (at != null) formatWhen(at), ?by, if (pending) 'pas encore envoyé'].join(' · '),
              style: TextStyle(color: colors.textMuted, fontSize: 13),
            ),
            if (entry['comment'] != null) Text('${entry['comment']}'),
          ]),
        ),
      ]),
    );
  }
}

class _VitalsList extends ConsumerWidget {
  const _VitalsList(this.consultationId);
  final String consultationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(_vitalsProvider(consultationId)).value ?? const [];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final v in items)
        Card(
          margin: const EdgeInsets.only(top: 8),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Constantes${v['recorded_at'] != null ? ' · ${formatWhen(DateTime.parse(v['recorded_at'] as String))}' : ''}',
                  style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              Wrap(spacing: 12, runSpacing: 4, children: [
                for (final m in measures)
                  if (v[m.key] != null) Text('${m.label} : ${'${v[m.key]}'.replaceAll('.', ',')} ${m.unit}'),
              ]),
              if (v['notes'] != null) Text('${v['notes']}', style: TextStyle(color: context.vsh.textMuted)),
            ]),
          ),
        ),
    ]);
  }
}

final _careProvider = StreamProvider.family<List<Map<String, dynamic>>, String>((ref, consultationId) => ref
    .watch(recordRepositoryProvider)
    .watchEntity(TreatmentActions.entity)
    .map((items) => items.where((t) => t['consultation_id'] == consultationId).toList()));

class _CareList extends ConsumerWidget {
  const _CareList(this.consultationId);
  final String consultationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(_careProvider(consultationId)).value ?? const [];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final t in items)
        Card(
          margin: const EdgeInsets.only(top: 8),
          child: ListTile(
            leading: const Icon(Icons.healing_rounded),
            title: Text('${(t['treatment_type'] as Map?)?['label'] ?? 'Soin'}'),
            subtitle: t['observations'] != null ? Text('${t['observations']}') : null,
          ),
        ),
    ]);
  }
}

class _NotesList extends ConsumerWidget {
  const _NotesList(this.consultationId);
  final String consultationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(_notesProvider(consultationId)).value ?? const [];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final n in items)
        Card(
          margin: const EdgeInsets.only(top: 8),
          child: Padding(padding: const EdgeInsets.all(12), child: Text('${n['content']}')),
        ),
    ]);
  }
}
