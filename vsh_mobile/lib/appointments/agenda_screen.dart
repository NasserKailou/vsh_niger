import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../database/record_repository.dart';
import '../notifications/notifications_screen.dart';
import '../shared/account_menu.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';

/// Rendez-vous (`appointment`) : arrivée (`check_in`, horodatée par le serveur) et absence
/// (`no_show` → ABSENT), depuis un rendez-vous confirmé ou déplacé.
class AppointmentActions {
  AppointmentActions(this.records);

  final RecordRepository records;
  static const entity = 'appointment';
  static const actionable = ['CONFIRME', 'DEPLACE'];

  Future<void> checkIn(String id) async =>
      records.act(entity, id, 'check_in', const {}, {'checked_in_at': await records.serverNowIso()});

  Future<void> noShow(String id) => records.act(entity, id, 'no_show', const {}, {'status': 'ABSENT'});
}

final appointmentActionsProvider = Provider<AppointmentActions>((ref) => AppointmentActions(ref.watch(recordRepositoryProvider)));
final _appointmentsProvider = StreamProvider<List<Map<String, dynamic>>>((ref) => ref.watch(recordRepositoryProvider).watchEntity(AppointmentActions.entity));

final _dayProvider = NotifierProvider<_Day, DateTime>(_Day.new);

class _Day extends Notifier<DateTime> {
  @override
  DateTime build() => DateUtils.dateOnly(appNow());
  void shift(int days) => state = state.add(Duration(days: days));
  void today() => state = DateUtils.dateOnly(appNow());
}

const _statusLabels = {
  'DEMANDE': 'Demandé',
  'CONFIRME': 'Confirmé',
  'DEPLACE': 'Déplacé',
  'ANNULE': 'Annulé',
  'ABSENT': 'Absent',
  'TERMINE': 'Terminé',
};

class AgendaScreen extends ConsumerWidget {
  const AgendaScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final day = ref.watch(_dayProvider);
    final all = ref.watch(_appointmentsProvider).value ?? const [];
    final user = ref.watch(sessionProvider).value?.user;
    final canManage = user?.can('appointments.manage') ?? false;
    final isToday = DateUtils.isSameDay(day, appNow());

    DateTime? start(Map<String, dynamic> a) => DateTime.tryParse(a['scheduled_start'] as String? ?? '')?.toLocal();
    final items = all.where((a) => start(a) != null && DateUtils.isSameDay(start(a), day)).toList()
      ..sort((a, b) => start(a)!.compareTo(start(b)!));

    Future<void> run(Future<void> Function() action) async {
      await action();
      ref.read(syncSchedulerProvider).poke();
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Agenda'), actions: const [NotificationBell(), SyncStatusBadge(), AccountMenu(), SizedBox(width: 4)]),
      body: Column(children: [
        const OfflineBanner(),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          child: Row(children: [
            IconButton(tooltip: 'Jour précédent', onPressed: () => ref.read(_dayProvider.notifier).shift(-1), icon: const Icon(Icons.chevron_left_rounded)),
            Expanded(
              child: TextButton(
                onPressed: isToday ? null : () => ref.read(_dayProvider.notifier).today(),
                style: TextButton.styleFrom(disabledForegroundColor: Theme.of(context).colorScheme.onSurface),
                child: Text(
                  isToday ? "Aujourd'hui, ${DateFormat('EEEE d MMMM', 'fr').format(day)}" : DateFormat('EEEE d MMMM', 'fr').format(day),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
            ),
            IconButton(tooltip: 'Jour suivant', onPressed: () => ref.read(_dayProvider.notifier).shift(1), icon: const Icon(Icons.chevron_right_rounded)),
          ]),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref.read(syncEngineProvider).run(),
            child: items.isEmpty
                ? ListView(children: [
                    Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text('Aucun rendez-vous ce jour-là sur ce téléphone.',
                          textAlign: TextAlign.center, style: TextStyle(color: context.vsh.textMuted)),
                    ),
                  ])
                : ListView.builder(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
                    itemCount: items.length,
                    itemBuilder: (context, i) {
                      final a = items[i];
                      final canAct = canManage && isToday && AppointmentActions.actionable.contains(a['status']) && a['checked_in_at'] == null;
                      return _AppointmentCard(
                        a,
                        start: start(a)!,
                        onCheckIn: canAct ? () => run(() => ref.read(appointmentActionsProvider).checkIn(a['id'] as String)) : null,
                        onNoShow: canAct ? () => run(() => ref.read(appointmentActionsProvider).noShow(a['id'] as String)) : null,
                      );
                    },
                  ),
          ),
        ),
      ]),
    );
  }
}

class _AppointmentCard extends StatelessWidget {
  const _AppointmentCard(this.a, {required this.start, this.onCheckIn, this.onNoShow});

  final Map<String, dynamic> a;
  final DateTime start;
  final VoidCallback? onCheckIn;
  final VoidCallback? onNoShow;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final patient = (a['patient'] as Map?)?.cast<String, dynamic>();
    final arrived = a['checked_in_at'] != null;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        onTap: a['patient_id'] != null ? () => context.push('/patients/${a['patient_id']}') : null,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(
              width: 56,
              child: Text(DateFormat.Hm('fr').format(start), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            ),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${patient?['name'] ?? 'Patient'}', style: const TextStyle(fontWeight: FontWeight.w600)),
                Text([
                  (a['service'] as Map?)?['label'],
                  (a['practitioner'] as Map?)?['name'],
                ].whereType<String>().join(' · '), style: TextStyle(color: colors.textMuted, fontSize: 13)),
                if (a['reason'] != null) Text('${a['reason']}', maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 6),
                Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Chip(
                    label: Text(arrived ? 'Arrivé' : _statusLabels[a['status']] ?? '${a['status']}'),
                    backgroundColor: arrived ? colors.successSoft : null,
                    visualDensity: VisualDensity.compact,
                  ),
                  if (onNoShow != null)
                    TextButton(onPressed: onNoShow, style: TextButton.styleFrom(foregroundColor: colors.neutral), child: const Text('Absent')),
                  if (onCheckIn != null) FilledButton.tonal(onPressed: onCheckIn, child: const Text('Arrivé')),
                ]),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}
