import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../network/api_client.dart';
import '../notifications/notifications_screen.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'patient_space_repository.dart';
import 'patient_widgets.dart';

/// Rendez-vous du dossier affiché : à venir (annulables), puis historique.
class PatientAppointmentsScreen extends ConsumerWidget {
  const PatientAppointmentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(currentPatientProvider);
    final items = patient == null
        ? const <Map<String, dynamic>>[]
        : ref.watch(patientItemsProvider((PatientSpaceRepository.appointments, patient['id'] as String))).value ?? const [];
    final now = DateTime.now();
    bool isUpcoming(Map<String, dynamic> a) =>
        upcomingAppointments.contains(a['status']) && (parseDate(a['scheduled_start'])?.isAfter(now) ?? false);
    final upcoming = items.where(isUpcoming).toList()..sort((a, b) => '${a['scheduled_start']}'.compareTo('${b['scheduled_start']}'));
    final past = items.where((a) => !isUpcoming(a)).toList()..sort((a, b) => '${b['scheduled_start']}'.compareTo('${a['scheduled_start']}'));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Rendez-vous'),
        actions: const [NotificationBell(), AccountMenu(), SizedBox(width: 4)],
        bottom: const PatientSwitcher(),
      ),
      floatingActionButton: patient == null
          ? null
          : FloatingActionButton.extended(
              onPressed: () => context.push('/p/appointments/new'),
              icon: const Icon(Icons.add_rounded),
              label: const Text('Demander un rendez-vous'),
            ),
      body: RefreshIndicator(
        onRefresh: () => refreshPatientSpace(ref, context),
        child: ListView(padding: const EdgeInsets.only(bottom: 96), children: [
          const OfflineBanner(),
          const SectionTitle('À venir'),
          if (upcoming.isEmpty)
            const EmptyState(
              icon: Icons.event_available_outlined,
              title: 'Aucun rendez-vous à venir',
              text: 'Demandez un rendez-vous : la clinique le confirme et vous prévient par notification.',
            ),
          for (final a in upcoming) _AppointmentCard(appointment: a, cancellable: true),
          if (past.isNotEmpty)
            ExpansionTile(
              title: Text('Historique (${past.length})'),
              children: [for (final a in past) _AppointmentCard(appointment: a, cancellable: false)],
            ),
        ]),
      ),
    );
  }
}

class _AppointmentCard extends ConsumerWidget {
  const _AppointmentCard({required this.appointment, required this.cancellable});

  final Map<String, dynamic> appointment;
  final bool cancellable;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final a = appointment;
    final start = parseDate(a['scheduled_start']);
    final muted = context.vsh.textMuted;
    return Card(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Text(start == null ? '—' : formatDateTime(start), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            ),
            StatusChip(appointmentLabels[a['status']] ?? '${a['status']}', tone: appointmentTone(a['status'] as String?)),
          ]),
          const SizedBox(height: 6),
          Text([
            (a['service'] as Map?)?['label'],
            (a['practitioner'] as Map?)?['name'],
          ].whereType<String>().join(' · ')),
          if (a['reason'] != null) Text('${a['reason']}', style: TextStyle(color: muted)),
          if (a['cancel_reason'] != null) Text('Annulé : ${a['cancel_reason']}', style: TextStyle(color: muted)),
          if (cancellable)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _cancel(context, ref),
                icon: const Icon(Icons.event_busy_outlined),
                label: const Text('Annuler'),
              ),
            ),
        ]),
      ),
    );
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final reason = await askReason(context, title: 'Annuler ce rendez-vous ?', confirm: 'Annuler le rendez-vous');
    if (reason == null || !context.mounted) return;
    try {
      await ref.read(patientSpaceProvider).cancelAppointment(appointment['id'] as String, reason: reason);
      if (context.mounted) showMessage(context, 'Rendez-vous annulé.');
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message);
    }
  }
}

/// Motif facultatif d'une annulation ; null si l'utilisateur renonce.
Future<String?> askReason(BuildContext context, {required String title, required String confirm}) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: TextField(
        controller: controller,
        maxLength: 500,
        decoration: const InputDecoration(labelText: 'Motif (facultatif)'),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Retour')),
        FilledButton(onPressed: () => Navigator.pop(context, controller.text), child: Text(confirm)),
      ],
    ),
  ).whenComplete(controller.dispose);
}

/// Demande de rendez-vous : service, jour, créneau libre calculé par le serveur, motif.
class NewAppointmentScreen extends ConsumerStatefulWidget {
  const NewAppointmentScreen({super.key});

  @override
  ConsumerState<NewAppointmentScreen> createState() => _NewAppointmentScreenState();
}

class _NewAppointmentScreenState extends ConsumerState<NewAppointmentScreen> {
  final _reason = TextEditingController();
  List<Map<String, dynamic>>? _services;
  int _maxDays = 60;
  String? _serviceId;
  DateTime _day = DateUtils.dateOnly(DateTime.now());
  Future<List<Map<String, dynamic>>>? _slots;
  Map<String, dynamic>? _chosen;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadReference();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _loadReference() async {
    final reference = ref.read(referenceRepositoryProvider);
    final services = (await reference.active('services')).where((s) => s['accepts_appointments'] == true).toList();
    final maxDays = (await reference.number('appointments.max_days_ahead', 60)).toInt();
    if (!mounted) return;
    setState(() {
      _services = services;
      _maxDays = maxDays;
      if (services.length == 1) _serviceId = services.first['id'] as String;
    });
    _loadSlots();
  }

  void _loadSlots() {
    if (_serviceId == null) return;
    setState(() {
      _chosen = null;
      _slots = ref.read(patientSpaceProvider).slots(_serviceId!, DateFormat('yyyy-MM-dd').format(_day));
    });
  }

  Future<void> _pickDay() async {
    final today = DateUtils.dateOnly(DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: today,
      lastDate: today.add(Duration(days: _maxDays)),
    );
    if (picked == null) return;
    _day = picked;
    _loadSlots();
  }

  Future<void> _submit(Map<String, dynamic> patient) async {
    final slot = _chosen;
    if (_serviceId == null || slot == null) return;
    setState(() => _busy = true);
    try {
      await ref.read(patientSpaceProvider).requestAppointment(
            patientId: patient['id'] as String,
            serviceId: _serviceId!,
            start: slot['start'] as String,
            reason: _reason.text,
          );
      if (!mounted) return;
      showMessage(context, 'Demande envoyée. La clinique vous confirmera le rendez-vous.');
      context.pop();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message);
      _loadSlots();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final patient = ref.watch(currentPatientProvider);
    final services = _services;
    final muted = context.vsh.textMuted;
    return Scaffold(
      appBar: AppBar(title: const Text('Demander un rendez-vous')),
      body: patient == null || services == null
          ? const Center(child: CircularProgressIndicator())
          : services.isEmpty
              ? const EmptyState(
                  icon: Icons.event_busy_outlined,
                  title: 'Prise de rendez-vous indisponible',
                  text: 'La prise de rendez-vous en ligne n’est pas ouverte pour le moment. Contactez l’accueil.',
                )
              : ListView(padding: const EdgeInsets.all(16), children: [
                  Text('Pour ${patient['first_name']} ${patient['last_name']}', style: TextStyle(color: muted)),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    initialValue: _serviceId,
                    decoration: const InputDecoration(labelText: 'Service'),
                    items: [
                      for (final s in services) DropdownMenuItem(value: s['id'] as String, child: Text('${s['label']}')),
                    ],
                    onChanged: (value) {
                      _serviceId = value;
                      _loadSlots();
                    },
                  ),
                  const SizedBox(height: 16),
                  OutlinedButton.icon(
                    onPressed: _pickDay,
                    icon: const Icon(Icons.calendar_month_rounded),
                    label: Text(formatDay(_day)),
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), alignment: Alignment.centerLeft),
                  ),
                  const SizedBox(height: 16),
                  Text('Heure', style: Theme.of(context).textTheme.titleSmall),
                  const SizedBox(height: 8),
                  _slotsView(),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _reason,
                    maxLength: 500,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'Motif (facultatif)', helperText: 'Quelques mots pour préparer votre venue.'),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    patient['status'] == 'PENDING'
                        ? 'Votre dossier sera validé à l’accueil lors de votre venue.'
                        : 'La clinique confirme chaque demande ; vous êtes prévenu par notification.',
                    style: TextStyle(color: muted),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _busy || _chosen == null ? null : () => _submit(patient),
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                    child: _busy
                        ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : const Text('Envoyer la demande'),
                  ),
                ]),
    );
  }

  Widget _slotsView() {
    final muted = context.vsh.textMuted;
    if (_serviceId == null) return Text('Choisissez un service.', style: TextStyle(color: muted));
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _slots,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const LinearProgressIndicator();
        if (snapshot.hasError) {
          final error = snapshot.error;
          return Text(error is ApiException ? error.message : 'Créneaux indisponibles. Vérifiez votre connexion.',
              style: TextStyle(color: context.vsh.danger));
        }
        final slots = snapshot.data ?? const [];
        if (slots.isEmpty) return Text('Aucun créneau ce jour-là. Essayez une autre date.', style: TextStyle(color: muted));
        return Wrap(spacing: 8, runSpacing: 8, children: [
          for (final slot in slots)
            ChoiceChip(
              label: Text('${slot['local_time']}'),
              selected: identical(slot, _chosen),
              onSelected: ((slot['available'] as num?) ?? 0) <= 0 ? null : (_) => setState(() => _chosen = slot),
              tooltip: ((slot['available'] as num?) ?? 0) <= 0 ? 'Complet' : 'Libre',
            ),
        ]);
      },
    );
  }
}
