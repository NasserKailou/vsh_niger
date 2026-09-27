import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';

import '../network/api_client.dart';
import '../notifications/notifications_screen.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';
import 'patient_space_repository.dart';
import 'patient_widgets.dart';

/// « Mon dossier » : résultats d'examens validés, ordonnances signées, factures émises.
/// Consultables hors ligne ; les PDF sont téléchargés à la demande.
class PatientRecordScreen extends ConsumerWidget {
  const PatientRecordScreen({super.key, this.tab});

  /// `results`, `prescriptions` ou `invoices` (lien depuis l'accueil ou une notification).
  final String? tab;

  static const _tabs = ['results', 'prescriptions', 'invoices'];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(currentPatientProvider);
    final hasFamily = (ref.watch(myPatientsProvider).value ?? const []).length > 1;
    final initial = _tabs.indexOf(tab ?? '');
    return DefaultTabController(
      key: ValueKey(tab),
      length: _tabs.length,
      initialIndex: initial < 0 ? 0 : initial,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Mon dossier'),
          actions: const [NotificationBell(), AccountMenu(), SizedBox(width: 4)],
          bottom: PreferredSize(
            preferredSize: Size.fromHeight(hasFamily ? 104 : 48),
            child: const Column(children: [
              PatientSwitcher(),
              TabBar(tabs: [Tab(text: 'Résultats'), Tab(text: 'Ordonnances'), Tab(text: 'Factures')]),
            ]),
          ),
        ),
        body: patient == null
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(children: [
                _ResultsTab(patientId: patient['id'] as String),
                _PrescriptionsTab(patientId: patient['id'] as String),
                _InvoicesTab(patientId: patient['id'] as String),
              ]),
      ),
    );
  }
}

/// Liste rafraîchissable (tirer vers le bas), avec le bandeau hors connexion.
class _RefreshableList extends ConsumerWidget {
  const _RefreshableList({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context, WidgetRef ref) => RefreshIndicator(
        onRefresh: () => refreshPatientSpace(ref, context),
        child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [const OfflineBanner(), ...children]),
      );
}

class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final c = context.vsh;
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: c.infoSoft, borderRadius: BorderRadius.circular(12)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.info_outline_rounded, color: c.info),
        const SizedBox(width: 12),
        Expanded(child: Text(text, style: TextStyle(color: c.info))),
      ]),
    );
  }
}

// ---------------------------------------------------------------- Résultats

class _ResultsTab extends ConsumerWidget {
  const _ResultsTab({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final exams = [...ref.watch(patientItemsProvider((PatientSpaceRepository.examinations, patientId))).value ?? const <Map<String, dynamic>>[]]
      ..sort((a, b) => '${b['validated_at']}'.compareTo('${a['validated_at']}'));
    return _RefreshableList(children: [
      const _Note('Seuls les résultats validés par un médecin sont affichés. Un résultat « hors valeurs de référence » n’est pas forcément anormal pour vous : parlez-en à votre médecin.'),
      if (exams.isEmpty)
        const EmptyState(icon: Icons.science_outlined, title: 'Aucun résultat', text: 'Vos résultats apparaîtront ici après validation par le médecin.'),
      for (final exam in exams) _ExamCard(exam: exam),
    ]);
  }
}

class _ExamCard extends StatelessWidget {
  const _ExamCard({required this.exam});

  final Map<String, dynamic> exam;

  static String _value(Map<String, dynamic> r) {
    final numeric = r['value_numeric'];
    if (numeric != null) return '${'$numeric'.replaceAll('.', ',')}${r['unit'] != null ? ' ${r['unit']}' : ''}';
    return '${r['value_text'] ?? '—'}';
  }

  @override
  Widget build(BuildContext context) {
    final muted = context.vsh.textMuted;
    final validated = parseDate(exam['validated_at']);
    final validator = (exam['validated_by'] as Map?)?['name'];
    final results = ((exam['results'] as List?) ?? const []).map((r) => (r as Map).cast<String, dynamic>()).toList();
    return Card(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${(exam['examination_type'] as Map?)?['label'] ?? 'Examen'}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          if (validated != null)
            Text('Validé le ${formatShortDay(validated)}${validator != null ? ' par $validator' : ''}', style: TextStyle(color: muted)),
          const SizedBox(height: 8),
          if (results.isEmpty) Text('Pas de résultat détaillé.', style: TextStyle(color: muted)),
          for (final r in results)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${r['label'] ?? ''}'),
                    if (r['reference_text'] != null) Text('Référence : ${r['reference_text']}', style: TextStyle(color: muted, fontSize: 13)),
                    // Sous le paramètre : à côté de la valeur, la mention déborde sur un petit écran.
                    if (r['is_abnormal'] == true) ...[const SizedBox(height: 4), const StatusChip('Hors valeurs de référence', tone: Tone.warning)],
                  ]),
                ),
                const SizedBox(width: 12),
                Flexible(
                  child: Text(_value(r), textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
              ]),
            ),
          if (exam['comment'] != null) ...[const SizedBox(height: 8), Text('${exam['comment']}', style: TextStyle(color: muted))],
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------- Documents PDF

/// Téléchargement puis ouverture d'un PDF avec le lecteur du téléphone.
class _PdfButton extends ConsumerStatefulWidget {
  const _PdfButton({required this.path, required this.fileName});

  final String path;
  final String fileName;

  @override
  ConsumerState<_PdfButton> createState() => _PdfButtonState();
}

class _PdfButtonState extends ConsumerState<_PdfButton> {
  bool _busy = false;

  Future<void> _open() async {
    setState(() => _busy = true);
    try {
      final file = await ref.read(patientSpaceProvider).downloadPdf(widget.path, widget.fileName);
      final result = await OpenFilex.open(file.path, type: 'application/pdf');
      if (result.type != ResultType.done && mounted) {
        showMessage(context, 'Aucune application ne peut ouvrir ce PDF. Installez un lecteur de PDF.');
      }
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.isNetwork ? 'Téléchargement impossible sans connexion.' : e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: _busy ? null : _open,
        icon: _busy
            ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
            : const Icon(Icons.picture_as_pdf_outlined),
        label: const Text('PDF'),
      );
}

class _PrescriptionsTab extends ConsumerWidget {
  const _PrescriptionsTab({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = [...ref.watch(patientItemsProvider((PatientSpaceRepository.prescriptions, patientId))).value ?? const <Map<String, dynamic>>[]]
      ..sort((a, b) => '${b['signed_at']}'.compareTo('${a['signed_at']}'));
    final muted = context.vsh.textMuted;
    return _RefreshableList(children: [
      if (items.isEmpty)
        const EmptyState(icon: Icons.medication_outlined, title: 'Aucune ordonnance', text: 'Vos ordonnances signées par le médecin apparaîtront ici.'),
      for (final p in items)
        Card(
          margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(
                      parseDate(p['signed_at']) == null ? 'Ordonnance' : 'Ordonnance du ${formatShortDay(parseDate(p['signed_at'])!)}',
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
                    ),
                    if ((p['prescriber'] as Map?)?['name'] != null) Text('${(p['prescriber'] as Map)['name']}', style: TextStyle(color: muted)),
                  ]),
                ),
                _PdfButton(path: '/me/patients/$patientId/prescriptions/${p['id']}/pdf', fileName: 'ordonnance-${p['id']}.pdf'),
              ]),
              const SizedBox(height: 8),
              for (final (i, raw) in ((p['items'] as List?) ?? const []).indexed) _PrescriptionLine(index: i, item: (raw as Map).cast<String, dynamic>()),
              if (p['notes'] != null) ...[const SizedBox(height: 8), Text('${p['notes']}', style: TextStyle(color: muted))],
            ]),
          ),
        ),
    ]);
  }
}

class _PrescriptionLine extends StatelessWidget {
  const _PrescriptionLine({required this.index, required this.item});

  final int index;
  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context) {
    final muted = context.vsh.textMuted;
    final it = item;
    final detail = [it['posology'], it['frequency'], if (it['duration'] != null) 'pendant ${it['duration']}'].whereType<String>().join(' · ');
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${index + 1}. ${[it['medication_label'], it['dosage'], it['form']].whereType<String>().join(' ')}',
            style: const TextStyle(fontWeight: FontWeight.w600)),
        if (detail.isNotEmpty) Text(detail, style: TextStyle(color: muted)),
        if (it['instructions'] != null) Text('${it['instructions']}', style: TextStyle(color: muted)),
      ]),
    );
  }
}

class _InvoicesTab extends ConsumerWidget {
  const _InvoicesTab({required this.patientId});

  final String patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = [...ref.watch(patientItemsProvider((PatientSpaceRepository.invoices, patientId))).value ?? const <Map<String, dynamic>>[]]
      ..sort((a, b) => '${b['issued_at']}'.compareTo('${a['issued_at']}'));
    final muted = context.vsh.textMuted;
    return _RefreshableList(children: [
      const _Note('Le paiement se fait à la clinique. L’état de règlement affiché est celui enregistré par la clinique.'),
      if (items.isEmpty)
        const EmptyState(icon: Icons.receipt_long_outlined, title: 'Aucune facture', text: 'Vos factures émises par la clinique apparaîtront ici.'),
      for (final inv in items)
        Card(
          margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${inv['number'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                    if (parseDate(inv['issued_at']) != null) Text('Émise le ${formatShortDay(parseDate(inv['issued_at'])!)}', style: TextStyle(color: muted)),
                  ]),
                ),
                _PdfButton(path: '/me/patients/$patientId/invoices/${inv['id']}/pdf', fileName: 'facture-${inv['number'] ?? inv['id']}.pdf'),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: Text(formatMoney(inv['net_amount'], inv['currency']), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 18))),
                inv['status'] == 'ANNULEE'
                    ? const StatusChip('Annulée')
                    : StatusChip(
                        settlementLabels[inv['settlement_status']] ?? '${inv['settlement_status'] ?? ''}',
                        tone: inv['settlement_status'] == 'REGLEE' ? Tone.success : Tone.warning,
                      ),
              ]),
              if (inv['status'] == 'EMISE' && ((inv['outstanding_amount'] as num?) ?? 0) > 0)
                Text('Reste à régler : ${formatMoney(inv['outstanding_amount'], inv['currency'])}', style: TextStyle(color: muted)),
              if (((inv['items'] as List?) ?? const []).isNotEmpty)
                ExpansionTile(
                  tilePadding: EdgeInsets.zero,
                  title: Text('Détail (${(inv['items'] as List).length} ligne${(inv['items'] as List).length > 1 ? 's' : ''})'),
                  children: [
                    for (final raw in inv['items'] as List) _InvoiceLine(item: (raw as Map).cast<String, dynamic>(), currency: inv['currency']),
                  ],
                ),
            ]),
          ),
        ),
    ]);
  }
}

class _InvoiceLine extends StatelessWidget {
  const _InvoiceLine({required this.item, required this.currency});

  final Map<String, dynamic> item;
  final Object? currency;

  @override
  Widget build(BuildContext context) {
    final quantity = (item['quantity'] as num?) ?? 1;
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      title: Text('${item['description'] ?? ''}${quantity > 1 ? ' × $quantity' : ''}'),
      trailing: Text(formatMoney(item['total_price'], currency)),
    );
  }
}
