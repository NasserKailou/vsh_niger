import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../notifications/notifications_screen.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'patient_homecare_screen.dart';
import 'patient_space_repository.dart';
import 'patient_widgets.dart';

/// Accueil du patient : ce qui l'attend (visite en cours, prochain rendez-vous), les deux demandes
/// principales, et l'accès à ses résultats et documents.
class PatientHomeScreen extends ConsumerWidget {
  const PatientHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).value?.user;
    final patients = ref.watch(myPatientsProvider);
    final patient = ref.watch(currentPatientProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text('Bonjour ${user?.firstName ?? ''}'.trim()),
        actions: const [NotificationBell(), AccountMenu(), SizedBox(width: 4)],
        bottom: const PatientSwitcher(),
      ),
      body: RefreshIndicator(
        onRefresh: () => refreshPatientSpace(ref, context),
        child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
          const OfflineBanner(),
          if (patient == null)
            patients.isLoading
                ? const Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator()))
                : const _FirstLoad()
          else ...[
            PendingNotice(patient: patient),
            _QuickActions(patient: patient),
            _CurrentVisit(patientId: patient['id'] as String),
            _NextAppointment(patientId: patient['id'] as String),
            _RecordShortcuts(patientId: patient['id'] as String),
          ],
        ]),
      ),
    );
  }
}

class _FirstLoad extends ConsumerWidget {
  const _FirstLoad();

  @override
  Widget build(BuildContext context, WidgetRef ref) => Column(children: [
        const EmptyState(
          icon: Icons.cloud_download_outlined,
          title: 'Chargement de votre dossier',
          text: 'Une connexion Internet est nécessaire la première fois. Ensuite, vos informations restent consultables sans réseau.',
        ),
        OutlinedButton.icon(
          onPressed: () => refreshPatientSpace(ref, context),
          icon: const Icon(Icons.refresh_rounded),
          label: const Text('Réessayer'),
        ),
      ]);
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.patient});

  final Map<String, dynamic> patient;

  @override
  Widget build(BuildContext context) {
    final canAskVisit = patient['status'] == 'ACTIVE';
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      child: Row(children: [
        Expanded(
          child: _ActionCard(
            icon: Icons.event_available_rounded,
            label: 'Prendre rendez-vous',
            onTap: () => context.push('/p/appointments/new'),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _ActionCard(
            icon: Icons.home_work_rounded,
            label: 'Demander une visite à domicile',
            onTap: canAskVisit ? () => context.push('/p/homecare/new') : null,
          ),
        ),
      ]),
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({required this.icon, required this.label, this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final enabled = onTap != null;
    return Material(
      color: enabled ? scheme.primaryContainer : scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 112),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Icon(icon, size: 32, color: enabled ? scheme.onPrimaryContainer : scheme.onSurfaceVariant),
              const SizedBox(height: 12),
              Text(label,
                  style: TextStyle(fontWeight: FontWeight.w700, color: enabled ? scheme.onPrimaryContainer : scheme.onSurfaceVariant)),
            ]),
          ),
        ),
      ),
    );
  }
}

class _CurrentVisit extends ConsumerWidget {
  const _CurrentVisit({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final visits = ref.watch(patientItemsProvider((PatientSpaceRepository.visits, patientId))).value ?? const [];
    final open = visits.where((v) => openVisits.contains(v['status'])).toList();
    if (open.isEmpty) return const SizedBox.shrink();
    final visit = open.first;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SectionTitle('Visite à domicile en cours'),
      Card(
        margin: const EdgeInsets.symmetric(horizontal: 16),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => context.go('/p/homecare'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              VisitProgress(visit: visit),
              if (visit['status'] == 'EN_ROUTE') ...[const SizedBox(height: 12), TeamApproach(visit: visit)],
            ]),
          ),
        ),
      ),
    ]);
  }
}

class _NextAppointment extends ConsumerWidget {
  const _NextAppointment({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(patientItemsProvider((PatientSpaceRepository.appointments, patientId))).value ?? const [];
    final now = DateTime.now();
    final upcoming = items
        .where((a) => upcomingAppointments.contains(a['status']) && (parseDate(a['scheduled_start'])?.isAfter(now) ?? false))
        .toList()
      ..sort((a, b) => '${a['scheduled_start']}'.compareTo('${b['scheduled_start']}'));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SectionTitle('Prochain rendez-vous'),
      if (upcoming.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Text('Aucun rendez-vous à venir.', style: TextStyle(color: context.vsh.textMuted)),
        )
      else
        Card(
          margin: const EdgeInsets.symmetric(horizontal: 16),
          child: ListTile(
            leading: const Icon(Icons.event_rounded),
            title: Text(formatDateTime(parseDate(upcoming.first['scheduled_start'])!)),
            subtitle: Text('${(upcoming.first['service'] as Map?)?['label'] ?? ''}'),
            trailing: StatusChip(appointmentLabels[upcoming.first['status']] ?? '${upcoming.first['status']}',
                tone: appointmentTone(upcoming.first['status'] as String?)),
            onTap: () => context.go('/p/appointments'),
          ),
        ),
    ]);
  }
}

class _RecordShortcuts extends ConsumerWidget {
  const _RecordShortcuts({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    int count(String entity) => (ref.watch(patientItemsProvider((entity, patientId))).value ?? const []).length;
    final tiles = [
      (Icons.science_outlined, 'Résultats d’examens', count(PatientSpaceRepository.examinations), 'results'),
      (Icons.medication_outlined, 'Ordonnances', count(PatientSpaceRepository.prescriptions), 'prescriptions'),
      (Icons.receipt_long_outlined, 'Factures', count(PatientSpaceRepository.invoices), 'invoices'),
    ];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SectionTitle('Mon dossier'),
      Card(
        margin: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(children: [
          for (final (icon, label, n, tab) in tiles)
            ListTile(
              leading: Icon(icon),
              title: Text(label),
              trailing: Row(mainAxisSize: MainAxisSize.min, children: [Text('$n'), const Icon(Icons.chevron_right_rounded)]),
              onTap: () => context.go('/p/record?tab=$tab'),
            ),
        ]),
      ),
      const _LastRefresh(),
    ]);
  }
}

class _LastRefresh extends ConsumerWidget {
  const _LastRefresh();

  @override
  Widget build(BuildContext context, WidgetRef ref) => FutureBuilder<DateTime?>(
        future: ref.watch(patientSpaceProvider).lastRefresh(),
        builder: (context, snapshot) => snapshot.data == null
            ? const SizedBox.shrink()
            : Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                child: Text('Mis à jour ${formatWhen(snapshot.data!)}. Tirez vers le bas pour actualiser.',
                    style: TextStyle(color: context.vsh.textMuted, fontSize: 13)),
              ),
      );
}
