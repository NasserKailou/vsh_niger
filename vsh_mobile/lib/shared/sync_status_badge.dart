import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../synchronization/sync_engine.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';

/// Pastille d'état de synchronisation (🟢 Synchronisé / 🟠 Synchronisation en cours / 🔴 Hors
/// connexion). Couleur **et** texte : l'information ne repose jamais sur la couleur seule.
class SyncStatusBadge extends ConsumerWidget {
  const SyncStatusBadge({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(syncOverviewProvider);
    final colors = context.vsh;
    final (fg, bg) = switch (overview.indicator) {
      SyncIndicator.synced => (colors.success, colors.successSoft),
      SyncIndicator.syncing => (colors.warning, colors.warningSoft),
      SyncIndicator.offline => (colors.danger, colors.dangerSoft),
    };
    final count = overview.attention > 0 ? overview.attention : overview.outgoing;
    return Semantics(
      button: true,
      label: '${overview.label}${count > 0 ? ', $count élément(s)' : ''}. Ouvrir le centre de synchronisation',
      excludeSemantics: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: () => context.push('/sync'),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Center(
            widthFactor: 1,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                ref.watch(syncStateProvider).value?.phase == SyncPhase.running
                    ? SizedBox.square(dimension: 10, child: CircularProgressIndicator(strokeWidth: 2, color: fg))
                    : Container(width: 10, height: 10, decoration: BoxDecoration(color: fg, shape: BoxShape.circle)),
                const SizedBox(width: 6),
                Text(overview.label, style: TextStyle(color: fg, fontWeight: FontWeight.w600, fontSize: 13)),
                if (count > 0) ...[
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                    decoration: BoxDecoration(
                      color: overview.attention > 0 ? colors.danger : fg,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text('$count', style: TextStyle(color: bg, fontSize: 12, fontWeight: FontWeight.w700)),
                  ),
                ],
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Bandeau discret hors connexion : rassure sans bloquer (charte §6).
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(syncOverviewProvider);
    if (overview.indicator != SyncIndicator.offline) return const SizedBox.shrink();
    final colors = context.vsh;
    final last = overview.lastSuccessAt;
    return Material(
      color: colors.neutralSoft,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(children: [
          Icon(Icons.cloud_off_rounded, size: 20, color: colors.neutral, semanticLabel: 'Hors connexion'),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              overview.detail ??
                  'Vos informations restent consultables et vos saisies sont enregistrées sur le téléphone.'
                      '${last != null ? ' Dernière synchronisation : ${formatWhen(last)}.' : ''}',
              style: TextStyle(color: colors.neutral, fontSize: 13),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Heure utilisée par l'interface (« aujourd'hui », « en retard »…), remplaçable dans les tests d'écrans.
DateTime Function() appNow = DateTime.now;

String formatWhen(DateTime at) {
  final local = at.toLocal();
  final now = appNow();
  final sameDay = local.year == now.year && local.month == now.month && local.day == now.day;
  return sameDay ? "aujourd'hui à ${DateFormat.Hm('fr').format(local)}" : DateFormat('d MMM à HH:mm', 'fr').format(local);
}
