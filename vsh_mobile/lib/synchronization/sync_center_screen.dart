import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../database/app_database.dart';
import '../shared/sync_status_badge.dart';
import '../theme/app_theme.dart';
import 'sync_engine.dart';
import 'sync_providers.dart';
import 'sync_queue.dart';

final _visibleOpsProvider = StreamProvider<List<SyncOperation>>((ref) => ref.watch(syncQueueProvider).watchVisible());

/// Centre de synchronisation : état, envoi manuel, éléments refusés ou en conflit (jamais supprimés
/// en silence), modifications en attente.
class SyncCenterScreen extends ConsumerWidget {
  const SyncCenterScreen({super.key});

  static const _entities = {
    'patient': 'Identité du patient',
    'patient_contact': 'Contact',
    'patient_address': 'Adresse',
    'allergy': 'Allergie',
    'medical_history': 'Antécédent',
    'current_treatment': 'Traitement en cours',
    'consultation': 'Consultation',
    'vital_sign': 'Constantes',
    'consultation_note': 'Note de consultation',
    'treatment': 'Soin',
    'examination': 'Examen',
    'prescription': 'Ordonnance',
    'appointment': 'Rendez-vous',
    'homecare_request': 'Visite à domicile',
    'notification': 'Notification',
  };

  static const _operations = {'CREATE': 'Création', 'UPDATE': 'Modification', 'DELETE': 'Suppression', 'ACTION': 'Étape'};

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(syncOverviewProvider);
    final running = ref.watch(syncStateProvider).value?.phase == SyncPhase.running;
    final ops = ref.watch(_visibleOpsProvider).value ?? const [];
    final attention = ops.where((o) => OpStatus.attention.contains(o.status)).toList();
    final waiting = ops.where((o) => OpStatus.outgoing.contains(o.status)).toList();
    final colors = context.vsh;

    return Scaffold(
      appBar: AppBar(title: const Text('Synchronisation')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const SyncStatusBadge(),
              const SizedBox(height: 8),
              Text(
                overview.lastSuccessAt == null
                    ? 'Aucune synchronisation réussie sur ce téléphone.'
                    : 'Dernière synchronisation réussie : ${formatWhen(overview.lastSuccessAt!)}.',
              ),
              if (overview.detail != null) ...[const SizedBox(height: 4), Text(overview.detail!, style: TextStyle(color: colors.textMuted))],
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: running ? null : () => ref.read(syncEngineProvider).run(),
                icon: const Icon(Icons.sync_rounded),
                label: Text(running ? 'Synchronisation…' : 'Synchroniser maintenant'),
              ),
            ]),
          ),
        ),
        if (attention.isNotEmpty) ...[
          const SizedBox(height: 24),
          _Heading('À traiter (${attention.length})', 'Ces modifications n’ont pas été acceptées telles quelles par la clinique.'),
          for (final op in attention) _OpCard(op, entities: _entities, operations: _operations, attention: true),
        ],
        const SizedBox(height: 24),
        _Heading('En attente d’envoi (${waiting.length})',
            waiting.isEmpty ? 'Tout est envoyé.' : 'Envoyées automatiquement dès que le réseau le permet.'),
        for (final op in waiting) _OpCard(op, entities: _entities, operations: _operations),
      ]),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.title, this.subtitle);

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          Text(subtitle, style: TextStyle(color: context.vsh.textMuted)),
        ]),
      );
}

const _actions = {
  'accept': 'prise en charge',
  'release': 'désistement',
  'depart': 'départ',
  'arrive': 'arrivée',
  'start': 'début des soins',
  'complete': 'fin de visite',
  'fail': 'échec',
  'track': 'trajet',
  'cancel': 'annulation',
};

class _OpCard extends ConsumerWidget {
  const _OpCard(this.op, {required this.entities, required this.operations, this.attention = false});

  final SyncOperation op;
  final Map<String, String> entities;
  final Map<String, String> operations;
  final bool attention;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colors = context.vsh;
    final conflict = op.status == OpStatus.conflict;
    final fields = _conflictFields();
    final (label, fg, bg) = switch (op.status) {
      OpStatus.conflict => ('Conflit', colors.warning, colors.warningSoft),
      OpStatus.rejected => ('Refusé', colors.danger, colors.dangerSoft),
      OpStatus.error => ('Nouvel essai prévu', colors.warning, colors.warningSoft),
      OpStatus.deferred => ('Attend une autre modification', colors.info, colors.infoSoft),
      OpStatus.sending => ('Envoi…', colors.info, colors.infoSoft),
      _ => ('En attente', colors.neutral, colors.neutralSoft),
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                child: Text(
                  '${operations[op.operation] ?? op.operation} · ${entities[op.entity] ?? op.entity}${op.action != null ? ' (${_actions[op.action] ?? op.action})' : ''}',
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
                child: Text(label, style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
              ),
            ]),
            const SizedBox(height: 4),
            Text('Saisie le ${formatWhen(op.createdAt)}', style: TextStyle(color: colors.textMuted, fontSize: 13)),
            if (op.lastError != null) ...[const SizedBox(height: 6), Text(op.lastError!)],
            if (fields.isNotEmpty) ...[const SizedBox(height: 4), Text('Champs concernés : ${fields.join(', ')}', style: TextStyle(color: colors.textMuted))],
            if (attention || op.status == OpStatus.error || op.status == OpStatus.deferred)
              Wrap(alignment: WrapAlignment.end, spacing: 8, children: [
                if (!attention)
                  TextButton(onPressed: () => ref.read(syncQueueProvider).retryNow(op), child: const Text('Réessayer maintenant')),
                if (attention)
                  TextButton(
                    onPressed: () => _dismiss(context, ref, conflict),
                    child: Text(conflict ? 'Compris' : 'Abandonner la modification'),
                  ),
              ]),
          ]),
        ),
      ),
    );
  }

  List<String> _conflictFields() {
    if (op.serverResult == null) return const [];
    final result = jsonDecode(op.serverResult!);
    final conflict = result is Map ? result['conflict'] : null;
    final errors = result is Map ? result['errors'] : null;
    if (conflict is Map && conflict['fields'] is List) return (conflict['fields'] as List).map((f) => '$f').toList();
    if (errors is Map) return errors.keys.map((k) => '$k').toList();
    return const [];
  }

  Future<void> _dismiss(BuildContext context, WidgetRef ref, bool conflict) async {
    if (!conflict) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Abandonner la modification ?'),
          content: Text(op.operation == 'ACTION'
              ? 'Cette étape et les étapes suivantes de la visite, refusées elles aussi, seront retirées. La visite reprend son état connu de la clinique.'
              : 'La saisie refusée sera retirée de ce téléphone. Cette action est définitive.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Abandonner')),
          ],
        ),
      );
      if (ok != true) return;
    }
    await ref.read(syncEngineProvider).dismiss(op);
  }
}
