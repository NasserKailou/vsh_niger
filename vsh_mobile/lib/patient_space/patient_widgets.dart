import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../network/api_client.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';
import 'patient_space_repository.dart';

export '../shared/account_menu.dart' show AccountMenu;

// ---------------------------------------------------------------- Dossiers du compte

/// Dossiers du compte (le patient et sa famille, D-009), triés par prénom.
final myPatientsProvider = StreamProvider<List<Map<String, dynamic>>>((ref) => ref
    .watch(patientSpaceProvider)
    .watch(PatientSpaceRepository.patients)
    .map((items) => items..sort((a, b) => '${a['first_name']}'.compareTo('${b['first_name']}'))));

/// Dossier affiché ; par défaut le premier du compte.
class SelectedPatient extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String id) => state = id;
}

final selectedPatientIdProvider = NotifierProvider<SelectedPatient, String?>(SelectedPatient.new);

final currentPatientProvider = Provider<Map<String, dynamic>?>((ref) {
  final patients = ref.watch(myPatientsProvider).value ?? const [];
  if (patients.isEmpty) return null;
  final selected = ref.watch(selectedPatientIdProvider);
  return patients.firstWhere((p) => p['id'] == selected, orElse: () => patients.first);
});

/// Éléments d'une entité de l'espace patient pour un dossier.
final patientItemsProvider = StreamProvider.family<List<Map<String, dynamic>>, (String, String)>(
    (ref, key) => ref.watch(patientSpaceProvider).watch(key.$1, patientId: key.$2));

/// Mise à jour à la demande (tirer vers le bas) : envoi des lectures en attente, puis lecture.
Future<void> refreshPatientSpace(WidgetRef ref, BuildContext context) async {
  try {
    await ref.read(patientSpaceProvider).refresh();
    ref.read(syncSchedulerProvider).poke();
  } on ApiException catch (e) {
    if (context.mounted) showMessage(context, e.message);
  }
}

// ---------------------------------------------------------------- Libellés

const appointmentLabels = {
  'DEMANDE': 'En attente de confirmation',
  'CONFIRME': 'Confirmé',
  'DEPLACE': 'Déplacé',
  'ANNULE': 'Annulé',
  'ABSENT': 'Absent',
  'TERMINE': 'Terminé',
};

/// Rendez-vous encore à venir (annulables par le patient dans le délai fixé par la clinique).
const upcomingAppointments = ['DEMANDE', 'CONFIRME', 'DEPLACE'];

const visitLabels = {
  'NOUVELLE': 'Demande reçue',
  'EN_ATTENTE': 'Demande reçue',
  'PRISE_EN_CHARGE': 'Équipe désignée',
  'EN_ROUTE': 'Équipe en route',
  'SUR_PLACE': 'Équipe arrivée',
  'EN_COURS': 'Soins en cours',
  'TERMINEE': 'Visite terminée',
  'FACTUREE': 'Visite terminée',
  'ECHEC': 'Visite non réalisée',
  'ANNULEE': 'Annulée',
};

const openVisits = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];
const patientCancellableVisits = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE'];

/// Étapes affichées au patient pendant une visite.
const visitSteps = [
  ('EN_ATTENTE', 'Demande reçue'),
  ('PRISE_EN_CHARGE', 'Équipe désignée'),
  ('EN_ROUTE', 'Équipe en route'),
  ('SUR_PLACE', 'Équipe arrivée'),
  ('EN_COURS', 'Soins en cours'),
  ('TERMINEE', 'Visite terminée'),
];

const settlementLabels = {
  'NON_REGLEE': 'Non réglée',
  'PARTIELLEMENT_REGLEE': 'Partiellement réglée',
  'REGLEE': 'Réglée',
};

// ---------------------------------------------------------------- Formats

DateTime? parseDate(Object? value) => value is String ? DateTime.tryParse(value)?.toLocal() : null;

String formatDay(DateTime at) => DateFormat('EEEE d MMMM y', 'fr').format(at);

String formatShortDay(DateTime at) => DateFormat('d MMM y', 'fr').format(at);

String formatDateTime(DateTime at) => DateFormat("d MMM y 'à' HH:mm", 'fr').format(at);

/// Montant en francs CFA (XOF), sans décimale.
String formatMoney(Object? amount, [Object? currency]) {
  final value = amount is num ? amount : num.tryParse('$amount') ?? 0;
  final unit = currency == null || currency == 'XOF' ? 'FCFA' : '$currency';
  return '${NumberFormat.decimalPattern('fr').format(value.round())} $unit';
}

void showMessage(BuildContext context, String message) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message), behavior: SnackBarBehavior.floating));

// ---------------------------------------------------------------- Composants

enum Tone { success, info, warning, danger, neutral }

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.tone = Tone.neutral});

  final String label;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    final c = context.vsh;
    final (fg, bg) = switch (tone) {
      Tone.success => (c.success, c.successSoft),
      Tone.info => (c.info, c.infoSoft),
      Tone.warning => (c.warning, c.warningSoft),
      Tone.danger => (c.danger, c.dangerSoft),
      Tone.neutral => (c.neutral, c.neutralSoft),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(label, style: TextStyle(color: fg, fontWeight: FontWeight.w600, fontSize: 13)),
    );
  }
}

Tone appointmentTone(String? status) => switch (status) {
      'CONFIRME' || 'TERMINE' => Tone.success,
      'DEMANDE' || 'DEPLACE' => Tone.warning,
      'ANNULE' || 'ABSENT' => Tone.danger,
      _ => Tone.neutral,
    };

Tone visitTone(String? status) => switch (status) {
      'TERMINEE' || 'FACTUREE' => Tone.success,
      'ANNULEE' || 'ECHEC' => Tone.danger,
      'EN_ROUTE' || 'SUR_PLACE' || 'EN_COURS' => Tone.info,
      _ => Tone.warning,
    };

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.text});

  final IconData icon;
  final String title;
  final String? text;

  @override
  Widget build(BuildContext context) {
    final muted = context.vsh.textMuted;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 48),
      child: Column(children: [
        Icon(icon, size: 48, color: muted),
        const SizedBox(height: 12),
        Text(title, style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
        if (text != null) ...[const SizedBox(height: 6), Text(text!, style: TextStyle(color: muted), textAlign: TextAlign.center)],
      ]),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
        child: Text(text, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
      );
}

/// Choix du dossier quand le compte en gère plusieurs (enfants, proches).
class PatientSwitcher extends ConsumerWidget implements PreferredSizeWidget {
  const PatientSwitcher({super.key});

  @override
  Size get preferredSize => const Size.fromHeight(56);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patients = ref.watch(myPatientsProvider).value ?? const [];
    final current = ref.watch(currentPatientProvider);
    if (patients.length < 2 || current == null) return const SizedBox.shrink();
    return SizedBox(
      height: 56,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        children: [
          for (final p in patients)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text('${p['first_name']}'),
                selected: p['id'] == current['id'],
                onSelected: (_) => ref.read(selectedPatientIdProvider.notifier).select(p['id'] as String),
              ),
            ),
        ],
      ),
    );
  }
}

/// Bandeau d'un dossier encore en attente de validation par l'accueil.
class PendingNotice extends StatelessWidget {
  const PendingNotice({super.key, required this.patient});

  final Map<String, dynamic> patient;

  @override
  Widget build(BuildContext context) {
    if (patient['status'] != 'PENDING') return const SizedBox.shrink();
    final c = context.vsh;
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: c.warningSoft, borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        Icon(Icons.hourglass_top_rounded, color: c.warning),
        const SizedBox(width: 12),
        Expanded(child: Text('Dossier en attente de validation par l’accueil. Certaines demandes seront possibles après validation.', style: TextStyle(color: c.warning))),
      ]),
    );
  }
}
