import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

import '../notifications/notifications_screen.dart';
import '../shared/account_menu.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'tile_prefetch.dart';
import 'track_recorder.dart';
import 'visit.dart';

/// Tournée du jour : visites de l'équipe en cours, file d'attente, visites closes récentes.
class TourScreen extends ConsumerWidget {
  const TourScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final visits = ref.watch(visitsProvider);
    final recorder = ref.watch(trackRecorderProvider);
    // Cartes hors ligne : fonds de carte autour des domiciles des visites ouvertes, pendant qu'il y a du réseau.
    ref.listen(visitsProvider, (_, next) {
      if (ref.read(connectivityProvider).value == false) return;
      final homes = {
        for (final v in next.value ?? const <Visit>[])
          if (VisitFlow.open.contains(v.status) && v.hasLocation) v.id: LatLng(v.latitude!, v.longitude!),
      };
      if (homes.isNotEmpty) unawaited(ref.read(tilePrefetcherProvider).prefetch(homes));
    });
    return Scaffold(
      appBar: AppBar(title: const Text('Tournée'), actions: const [NotificationBell(), SyncStatusBadge(), AccountMenu(), SizedBox(width: 4)]),
      body: Column(children: [
        const OfflineBanner(),
        if (recorder.visitId != null) const _TrackingBanner(),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref.read(syncEngineProvider).run(),
            child: visits.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (_, _) => const Center(child: Text('Lecture des visites impossible.')),
              data: (all) {
                final mine = all.where((v) => v.available && VisitFlow.active.contains(v.status)).toList();
                final queue = all.where((v) => v.available && v.status == 'EN_ATTENTE').toList()
                  ..sort((a, b) => (b.urgent ? 1 : 0).compareTo(a.urgent ? 1 : 0));
                final since = appNow().subtract(const Duration(hours: 24));
                final closed = all
                    .where((v) => v.available && !v.isOpen && (v.at('closed_at') ?? v.at('completed_at') ?? DateTime(2000)).isAfter(since))
                    .toList();
                if (mine.isEmpty && queue.isEmpty && closed.isEmpty) {
                  return ListView(children: [
                    Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text(
                        'Aucune visite à domicile pour votre équipe. Les nouvelles demandes et les affectations arrivent à la synchronisation.',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: context.vsh.textMuted),
                      ),
                    ),
                  ]);
                }
                return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
                  if (mine.isNotEmpty) ...[
                    const _Section('Mes visites en cours'),
                    for (final v in mine) VisitCard(v),
                  ],
                  if (queue.isNotEmpty) ...[
                    const _Section('File d’attente'),
                    for (final v in queue) VisitCard(v),
                  ],
                  if (closed.isNotEmpty) ...[
                    const _Section('Closes depuis 24 h'),
                    for (final v in closed) VisitCard(v),
                  ],
                ]);
              },
            ),
          ),
        ),
      ]),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section(this.title);
  final String title;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 16, bottom: 8),
        child: Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
      );
}

class _TrackingBanner extends StatelessWidget {
  const _TrackingBanner();

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    return Material(
      color: colors.infoSoft,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(children: [
          Icon(Icons.route_rounded, size: 20, color: colors.info),
          const SizedBox(width: 8),
          Expanded(
            child: Text('Trajet enregistré pendant la visite en cours (application ouverte).',
                style: TextStyle(color: colors.info, fontSize: 13)),
          ),
        ]),
      ),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key});
  final String status;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final (fg, bg) = switch (status) {
      'TERMINEE' || 'FACTUREE' => (colors.success, colors.successSoft),
      'EN_ATTENTE' || 'NOUVELLE' => (colors.warning, colors.warningSoft),
      'ECHEC' || 'ANNULEE' => (colors.neutral, colors.neutralSoft),
      _ => (colors.info, colors.infoSoft),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(VisitFlow.labels[status] ?? status, style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

class VisitCard extends StatelessWidget {
  const VisitCard(this.visit, {super.key});
  final Visit visit;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => context.push('/visits/${visit.id}'),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(visit.patientName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
                StatusChip(visit.status),
              ]),
              const SizedBox(height: 4),
              Text([visit.fileNumber, visit.reason].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                  maxLines: 2, overflow: TextOverflow.ellipsis),
              if ((visit.landmark ?? visit.addressText) != null) ...[
                const SizedBox(height: 4),
                Row(children: [
                  Icon(Icons.place_outlined, size: 16, color: colors.textMuted),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(visit.landmark ?? visit.addressText!,
                        maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.textMuted)),
                  ),
                ]),
              ],
              if (visit.urgent) ...[
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: colors.dangerSoft, borderRadius: BorderRadius.circular(999)),
                  child: Text('Urgente', style: TextStyle(color: colors.danger, fontWeight: FontWeight.w700, fontSize: 12)),
                ),
              ],
            ]),
          ),
        ),
      ),
    );
  }
}
