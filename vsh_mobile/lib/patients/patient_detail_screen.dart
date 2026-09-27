import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../database/app_database.dart';
import '../homecare/clinical_forms.dart';
import '../homecare/visit.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';
import 'patient_list_screen.dart';

final _patientProvider = StreamProvider.family<Patient?, String>((ref, id) => ref.watch(patientRepositoryProvider).watch(id));

class PatientDetailScreen extends ConsumerWidget {
  const PatientDetailScreen({super.key, required this.id});

  final String id;

  static const _statuses = {
    'PENDING': 'En attente de validation',
    'ACTIVE': 'Actif',
    'INACTIVE': 'Inactif',
    'REJECTED': 'Refusé',
    'MERGED': 'Fusionné',
    'DECEASED': 'Décédé',
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(_patientProvider(id));
    final unsynced = ref.watch(unsyncedPatientsProvider).value?.contains(id) ?? false;
    return Scaffold(
      appBar: AppBar(title: const Text('Dossier patient'), actions: const [SyncStatusBadge(), SizedBox(width: 8)]),
      body: patient.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => const Center(child: Text('Lecture du dossier impossible.')),
        data: (p) {
          if (p == null) {
            return const Center(child: Text("Ce dossier n'est plus disponible sur ce téléphone."));
          }
          final data = (jsonDecode(p.data) as Map).cast<String, dynamic>();
          final colors = context.vsh;
          final attending = data['attending_physician'] as Map?;
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text('${p.lastName.toUpperCase()} ${p.firstName}', style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text(p.fileNumber ?? 'N° de dossier attribué à la synchronisation', style: TextStyle(color: colors.textMuted)),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              Chip(label: Text(_statuses[p.status] ?? p.status)),
              if (unsynced)
                Chip(
                  avatar: Icon(Icons.cloud_upload_outlined, size: 18, color: colors.warning),
                  label: const Text('Pas encore envoyé'),
                  backgroundColor: colors.warningSoft,
                ),
              if (data['possible_duplicate_of'] != null)
                Chip(label: const Text('Doublon possible : à vérifier par l’accueil'), backgroundColor: colors.warningSoft),
            ]),
            const SizedBox(height: 16),
            Card(
              child: Column(children: [
                _Row('Sexe', p.sex == 'F' ? 'Féminin' : p.sex == 'M' ? 'Masculin' : '—'),
                _Row('Naissance', [
                  p.birthDate ?? '—',
                  if (data['birth_date_is_estimated'] == true) '(estimée)',
                  if (data['age_years'] != null) '· ${data['age_years']} ans',
                ].join(' ')),
                _Row('Téléphone', p.phone ?? '—'),
                _Row('Médecin traitant', attending?['name'] as String? ?? '—'),
              ]),
            ),
            _Related(p.id),
          ]);
        },
      ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: OutlinedButton.icon(
          onPressed: () async {
            final saved = await context.push<String>('/patients/$id/edit');
            if (saved != null) ref.read(syncSchedulerProvider).poke();
          },
          icon: const Icon(Icons.edit_rounded),
          label: const Text("Modifier l'identité"),
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 130, child: Text(label, style: TextStyle(color: context.vsh.textMuted))),
          Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w500))),
        ]),
      );
}

final _relatedProvider = StreamProvider.family<List<Map<String, dynamic>>, (String, String)>(
    (ref, key) => ref.watch(recordRepositoryProvider).watchEntity(key.$1, patientId: key.$2));

/// Données du dossier présentes sur le téléphone (selon les droits de l'utilisateur, D-010).
class _Related extends ConsumerWidget {
  const _Related(this.patientId);
  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    List<Map<String, dynamic>> of(String entity) => ref.watch(_relatedProvider((entity, patientId))).value ?? const [];
    DateTime? at(Map<String, dynamic> m, String key) => DateTime.tryParse(m[key] as String? ?? '');
    final colors = context.vsh;

    final visits = of('homecare_request').where((v) => v['status'] != null && v['patient'] != null).toList()
      ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
    final care = of('treatment').toList()..sort((a, b) => '${b['performed_at'] ?? b['scheduled_for']}'.compareTo('${a['performed_at'] ?? a['scheduled_for']}'));
    final vitals = of('vital_sign').toList()..sort((a, b) => '${b['recorded_at']}'.compareTo('${a['recorded_at']}'));
    final now = appNow();
    final appointments = of('appointment').where((a) => (at(a, 'scheduled_start') ?? DateTime(2000)).isAfter(now.subtract(const Duration(days: 1)))).toList()
      ..sort((a, b) => '${a['scheduled_start']}'.compareTo('${b['scheduled_start']}'));

    Widget section(String title, List<Widget> children) => children.isEmpty
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(top: 20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Card(child: Column(children: children)),
            ]),
          );

    final last = vitals.isEmpty ? null : vitals.first;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      section('Visites à domicile', [
        for (final v in visits.take(5))
          ListTile(
            title: Text('${v['reason'] ?? 'Visite'}', maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: Text(VisitFlow.labels[v['status']] ?? '${v['status']}'),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: () => context.push('/visits/${v['id']}'),
          ),
      ]),
      section('Rendez-vous à venir', [
        for (final a in appointments.take(5))
          ListTile(
            title: Text(at(a, 'scheduled_start') != null ? formatWhen(at(a, 'scheduled_start')!) : '—'),
            subtitle: Text([(a['service'] as Map?)?['label'], (a['practitioner'] as Map?)?['name']].whereType<String>().join(' · ')),
          ),
      ]),
      section('Soins', [
        for (final t in care.take(8))
          ListTile(
            title: Text('${(t['treatment_type'] as Map?)?['label'] ?? 'Soin'}'),
            subtitle: Text(switch (t['status']) {
              'REALISE' => 'Réalisé ${at(t, 'performed_at') != null ? formatWhen(at(t, 'performed_at')!) : ''}',
              'ANNULE' => 'Annulé',
              _ => 'Prévu ${at(t, 'scheduled_for') != null ? formatWhen(at(t, 'scheduled_for')!) : ''}',
            }),
          ),
      ]),
      if (last != null)
        section('Dernières constantes', [
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (at(last, 'recorded_at') != null) Text(formatWhen(at(last, 'recorded_at')!), style: TextStyle(color: colors.textMuted)),
              const SizedBox(height: 4),
              Wrap(spacing: 12, runSpacing: 4, children: [
                for (final m in measures)
                  if (last[m.key] != null) Text('${m.label} : ${'${last[m.key]}'.replaceAll('.', ',')} ${m.unit}'),
              ]),
            ]),
          ),
        ]),
    ]);
  }
}
