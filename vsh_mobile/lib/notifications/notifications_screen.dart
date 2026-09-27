import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../database/record_repository.dart';
import '../routing/app_router.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';

/// Notifications de l'utilisateur (sans détail médical, par construction côté serveur), lues hors
/// ligne : `UPDATE read_at`.
class NotificationActions {
  NotificationActions(this.records);

  final RecordRepository records;
  static const entity = 'notification';

  Future<void> markRead(String id) async {
    final current = await records.store.read(entity, id);
    if (current == null || current['read_at'] != null) return;
    await records.update(entity, id, {'read_at': await records.serverNowIso()});
  }

  Future<void> markAllRead(Iterable<String> ids) async {
    for (final id in ids) {
      await markRead(id);
    }
  }
}

final notificationActionsProvider = Provider<NotificationActions>((ref) => NotificationActions(ref.watch(recordRepositoryProvider)));

final notificationsProvider = StreamProvider<List<Map<String, dynamic>>>((ref) => ref
    .watch(recordRepositoryProvider)
    .watchEntity(NotificationActions.entity)
    .map((items) => items..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'))));

final unreadCountProvider = Provider<int>((ref) => (ref.watch(notificationsProvider).value ?? const []).where((n) => n['read_at'] == null).length);

/// Cloche de la barre supérieure avec le nombre de notifications non lues.
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(unreadCountProvider);
    return IconButton(
      tooltip: unread > 0 ? '$unread notification(s) non lue(s)' : 'Notifications',
      onPressed: () => context.push('/notifications'),
      icon: Badge(isLabelVisible: unread > 0, label: Text('$unread'), child: const Icon(Icons.notifications_outlined)),
    );
  }
}

/// Lien vers l'écran concerné, quand l'application le connaît (liste, alerte locale, message push).
/// Un patient est dirigé vers son espace, le personnel vers ses écrans de travail.
String? notificationRoute(Map<String, dynamic> n, {bool patient = false}) => patient
    ? switch (n['entity_type']) {
        'appointment' => '/p/appointments',
        'homecare_request' => '/p/homecare',
        'examination' => '/p/record?tab=results',
        'prescription' => '/p/record?tab=prescriptions',
        'invoice' => '/p/record?tab=invoices',
        'patient' => '/p/home',
        _ => null,
      }
    : switch (n['entity_type']) {
      'homecare_request' => '/visits/${n['entity_id']}',
      'patient' => '/patients/${n['entity_id']}',
      'appointment' => '/agenda',
      'treatment' => '/care',
      _ => null,
    };

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(notificationsProvider).value ?? const [];
    final unread = items.where((n) => n['read_at'] == null).map((n) => n['id'] as String).toList();
    final colors = context.vsh;
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications'), actions: [
        if (unread.isNotEmpty)
          TextButton(
            onPressed: () async {
              await ref.read(notificationActionsProvider).markAllRead(unread);
              ref.read(syncSchedulerProvider).poke();
            },
            child: const Text('Tout marquer lu'),
          ),
      ]),
      body: items.isEmpty
          ? Center(child: Text('Aucune notification.', style: TextStyle(color: colors.textMuted)))
          : ListView.separated(
              itemCount: items.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, i) {
                final n = items[i];
                final isUnread = n['read_at'] == null;
                final at = DateTime.tryParse(n['created_at'] as String? ?? '');
                final target = notificationRoute(n, patient: ref.read(sessionProvider).value?.user?.isPatient == true);
                return ListTile(
                  minTileHeight: 64,
                  leading: Icon(isUnread ? Icons.circle : Icons.circle_outlined, size: 12, color: isUnread ? colors.info : colors.textMuted),
                  title: Text('${n['title']}', style: TextStyle(fontWeight: isUnread ? FontWeight.w700 : FontWeight.w400)),
                  subtitle: Text([if (n['body'] != null) '${n['body']}', if (at != null) formatWhen(at)].join('\n')),
                  onTap: () async {
                    await ref.read(notificationActionsProvider).markRead(n['id'] as String);
                    ref.read(syncSchedulerProvider).poke();
                    if (target != null && context.mounted) openLocation(GoRouter.of(context), target);
                  },
                );
              },
            ),
    );
  }
}
