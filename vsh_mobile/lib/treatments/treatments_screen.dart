import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../database/record_repository.dart';
import '../homecare/clinical_forms.dart';
import '../notifications/notifications_screen.dart';
import '../shared/account_menu.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';

/// Soins (`treatment`) : PLANIFIE → perform → REALISE (définitif, facturé) ; PLANIFIE → cancel → ANNULE.
class TreatmentActions {
  TreatmentActions(this.records);

  final RecordRepository records;
  static const entity = 'treatment';

  /// Soin fait : horodaté à l'heure du serveur. Les observations sont des données médicales.
  Future<void> perform(String id, {String? observations, String? performerName}) async {
    final at = await records.serverNowIso();
    final text = observations?.trim();
    await records.act(entity, id, 'perform', {
      'performed_at': at,
      if (text != null && text.isNotEmpty) 'observations': text,
    }, {
      'status': 'REALISE',
      'performed_at': at,
      if (performerName != null) 'performed_by': {'name': performerName},
      if (text != null && text.isNotEmpty) 'observations': text,
    });
  }

  Future<void> cancel(String id, String reason) =>
      records.act(entity, id, 'cancel', {'reason': reason.trim()}, {'status': 'ANNULE', 'cancel_reason': reason.trim()});

  /// Soin réalisé pendant une consultation (à domicile) : type choisi dans le référentiel.
  Future<String> recordDone({
    required String patientId,
    required Map<String, dynamic> type,
    String? consultationId,
    String? dependsOnOp,
    String? observations,
    String? performerName,
  }) async {
    final at = await records.serverNowIso();
    final text = observations?.trim();
    return records.create(
      entity,
      {
        'patient_id': patientId,
        'consultation_id': ?consultationId,
        'treatment_type_id': type['id'],
        'status': 'REALISE',
        'performed_at': at,
        if (text != null && text.isNotEmpty) 'observations': text,
      },
      display: {
        'treatment_type': {'id': type['id'], 'code': type['code'], 'label': type['label']},
        if (performerName != null) 'performed_by': {'name': performerName},
      },
      dependsOnOp: dependsOnOp,
      parentEntity: consultationId != null ? 'consultation' : 'patient',
      parentId: consultationId ?? patientId,
    );
  }
}

final treatmentActionsProvider = Provider<TreatmentActions>((ref) => TreatmentActions(ref.watch(recordRepositoryProvider)));

final _treatmentsProvider = StreamProvider<List<Map<String, dynamic>>>((ref) => ref.watch(recordRepositoryProvider).watchEntity(TreatmentActions.entity));
final _unsyncedProvider = StreamProvider<Set<String>>((ref) => ref.watch(recordRepositoryProvider).watchUnsynced(TreatmentActions.entity));

/// Noms des patients présents sur le téléphone, pour les listes.
final patientNamesProvider = StreamProvider<Map<String, String>>((ref) {
  final db = ref.watch(databaseProvider);
  return db.select(db.patients).watch().map((rows) => {for (final p in rows) p.id: '${p.lastName.toUpperCase()} ${p.firstName}'});
});

/// Soins à faire : en retard et du jour d'abord, puis à venir ; réalisés depuis 24 h.
class TreatmentsScreen extends ConsumerWidget {
  const TreatmentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final all = ref.watch(_treatmentsProvider).value ?? const [];
    final names = ref.watch(patientNamesProvider).value ?? const {};
    final unsynced = ref.watch(_unsyncedProvider).value ?? const {};
    final user = ref.watch(sessionProvider).value?.user;
    final canPerform = user?.can('treatments.perform') ?? false;
    final canObserve = user?.can('patients.medical.write') ?? false;

    DateTime? date(Map<String, dynamic> t, String key) => DateTime.tryParse(t[key] as String? ?? '')?.toLocal();
    final planned = all.where((t) => t['status'] == 'PLANIFIE').toList()
      ..sort((a, b) => (date(a, 'scheduled_for') ?? DateTime(2100)).compareTo(date(b, 'scheduled_for') ?? DateTime(2100)));
    final since = appNow().subtract(const Duration(hours: 24));
    final done = all.where((t) => t['status'] == 'REALISE' && (date(t, 'performed_at') ?? DateTime(2000)).isAfter(since)).toList()
      ..sort((a, b) => date(b, 'performed_at')!.compareTo(date(a, 'performed_at')!));
    final endOfToday = appNow().copyWith(hour: 23, minute: 59, second: 59);
    final due = planned.where((t) => (date(t, 'scheduled_for') ?? DateTime(2000)).isBefore(endOfToday)).toList();
    final later = planned.where((t) => !due.contains(t)).toList();

    Future<void> perform(Map<String, dynamic> t) async {
      String? observations;
      if (canObserve) {
        observations = await showTextSheet(context,
            title: 'Soin réalisé', label: 'Observations (facultatif)', action: 'Confirmer le soin', required: false, maxLength: 5000,
            help: 'Un soin réalisé est définitif : il sera facturé.');
        if (observations == null) return;
      } else {
        final ok = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Confirmer le soin ?'),
            content: const Text('Un soin réalisé est définitif : il sera facturé.'),
            actions: [
              TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
              FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Confirmer')),
            ],
          ),
        );
        if (ok != true) return;
      }
      await ref.read(treatmentActionsProvider).perform(t['id'] as String, observations: observations, performerName: user?.displayName);
      ref.read(syncSchedulerProvider).poke();
    }

    Future<void> cancel(Map<String, dynamic> t) async {
      if (!context.mounted) return;
      final reason = await showTextSheet(context, title: 'Annuler le soin', label: 'Motif', action: 'Annuler le soin');
      if (reason == null) return;
      await ref.read(treatmentActionsProvider).cancel(t['id'] as String, reason);
      ref.read(syncSchedulerProvider).poke();
    }

    Widget tile(Map<String, dynamic> t) => _TreatmentTile(
          t,
          patientName: names[t['patient_id']],
          unsynced: unsynced.contains(t['id']),
          onPerform: canPerform && t['status'] == 'PLANIFIE' ? () => perform(t) : null,
          onCancel: canPerform && t['status'] == 'PLANIFIE' ? () => cancel(t) : null,
        );

    return Scaffold(
      appBar: AppBar(title: const Text('Soins'), actions: const [NotificationBell(), SyncStatusBadge(), AccountMenu(), SizedBox(width: 4)]),
      body: Column(children: [
        const OfflineBanner(),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref.read(syncEngineProvider).run(),
            child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
              _Heading('À faire aujourd’hui (${due.length})'),
              if (due.isEmpty) _Empty('Aucun soin programmé pour aujourd’hui.'),
              for (final t in due) tile(t),
              if (later.isNotEmpty) ...[_Heading('À venir (${later.length})'), for (final t in later) tile(t)],
              if (done.isNotEmpty) ...[_Heading('Réalisés depuis 24 h'), for (final t in done) tile(t)],
            ]),
          ),
        ),
      ]),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 16, bottom: 8),
        child: Text(text, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
      );
}

class _Empty extends StatelessWidget {
  const _Empty(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Text(text, style: TextStyle(color: context.vsh.textMuted));
}

class _TreatmentTile extends StatelessWidget {
  const _TreatmentTile(this.t, {this.patientName, this.unsynced = false, this.onPerform, this.onCancel});

  final Map<String, dynamic> t;
  final String? patientName;
  final bool unsynced;
  final VoidCallback? onPerform;
  final VoidCallback? onCancel;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final type = (t['treatment_type'] as Map?)?['label'] as String? ?? 'Soin';
    final scheduled = DateTime.tryParse(t['scheduled_for'] as String? ?? '');
    final performed = DateTime.tryParse(t['performed_at'] as String? ?? '');
    final late = t['status'] == 'PLANIFIE' && scheduled != null && scheduled.isBefore(appNow());
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(type, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
            if (unsynced) Icon(Icons.cloud_upload_outlined, color: colors.warning, semanticLabel: 'Pas encore envoyé'),
          ]),
          if (patientName != null)
            InkWell(
              onTap: () => context.push('/patients/${t['patient_id']}'),
              child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(patientName!)),
            ),
          Text(
            performed != null
                ? 'Réalisé ${formatWhen(performed)}${(t['performed_by'] as Map?)?['name'] != null ? ' par ${(t['performed_by'] as Map)['name']}' : ''}'
                : scheduled != null
                    ? '${late ? 'En retard · prévu ' : 'Prévu '}${formatWhen(scheduled)}'
                    : 'Sans date prévue',
            style: TextStyle(color: late ? colors.danger : colors.textMuted, fontSize: 13),
          ),
          if (t['observations'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${t['observations']}')),
          if (onPerform != null || onCancel != null)
            Row(mainAxisAlignment: MainAxisAlignment.end, children: [
              if (onCancel != null)
                TextButton(onPressed: onCancel, style: TextButton.styleFrom(foregroundColor: colors.neutral), child: const Text('Annuler')),
              if (onPerform != null) FilledButton.tonal(onPressed: onPerform, child: const Text('Soin fait')),
            ]),
        ]),
      ),
    );
  }
}

/// Choix d'un type de soin dans le référentiel (hors ligne).
Future<Map<String, dynamic>?> pickTreatmentType(BuildContext context, List<Map<String, dynamic>> types) => showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _TypePicker(types),
    );

class _TypePicker extends StatefulWidget {
  const _TypePicker(this.types);
  final List<Map<String, dynamic>> types;

  @override
  State<_TypePicker> createState() => _TypePickerState();
}

class _TypePickerState extends State<_TypePicker> {
  String _filter = '';

  @override
  Widget build(BuildContext context) {
    final f = _filter.toLowerCase();
    final items = widget.types.where((t) => '${t['label']} ${t['code']}'.toLowerCase().contains(f)).toList();
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.7,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: TextField(
              autofocus: true,
              decoration: const InputDecoration(labelText: 'Type de soin', prefixIcon: Icon(Icons.search_rounded)),
              onChanged: (v) => setState(() => _filter = v),
            ),
          ),
          Expanded(
            child: items.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(24),
                    child: Text(
                      widget.types.isEmpty
                          ? 'Aucun type de soin sur ce téléphone. Synchronisez une fois en ligne, ou demandez à la clinique de compléter le référentiel.'
                          : 'Aucun résultat.',
                      textAlign: TextAlign.center,
                    ),
                  )
                : ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (context, i) => ListTile(
                      title: Text('${items[i]['label']}'),
                      subtitle: Text('${items[i]['code']}'),
                      onTap: () => Navigator.pop(context, items[i]),
                    ),
                  ),
          ),
        ]),
      ),
    );
  }
}
