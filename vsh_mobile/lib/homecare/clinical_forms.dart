import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_theme.dart';

/// Constante saisissable : bornes de plausibilité identiques à `VitalSignService::MEASURES`
/// (contrôle de saisie seulement, aucune interprétation clinique).
class Measure {
  const Measure(this.key, this.label, this.unit, this.min, this.max, {this.integer = false});

  final String key;
  final String label;
  final String unit;
  final num min;
  final num max;
  final bool integer;
}

const measures = [
  Measure('temperature_c', 'Température', '°C', 25, 45),
  Measure('systolic_mmhg', 'Tension systolique', 'mmHg', 40, 300, integer: true),
  Measure('diastolic_mmhg', 'Tension diastolique', 'mmHg', 20, 200, integer: true),
  Measure('pulse_bpm', 'Pouls', 'bpm', 20, 250, integer: true),
  Measure('respiratory_rate', 'Fréquence respiratoire', '/min', 4, 80, integer: true),
  Measure('spo2_percent', 'SpO₂', '%', 50, 100, integer: true),
  Measure('weight_kg', 'Poids', 'kg', 0.3, 400),
  Measure('height_cm', 'Taille', 'cm', 20, 250),
  Measure('glycemia_g_l', 'Glycémie', 'g/L', 0.1, 10),
];

/// Nombre saisi avec virgule ou point (clavier français).
num? parseNumber(String text) {
  final value = text.trim().replaceAll(' ', '').replaceAll(',', '.');
  return value.isEmpty ? null : num.tryParse(value);
}

class VitalsResult {
  const VitalsResult(this.values, this.notes);
  final Map<String, num?> values;
  final String? notes;
}

Future<VitalsResult?> showVitalsSheet(BuildContext context) => showModalBottomSheet<VitalsResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => const _VitalsSheet(),
    );

class _VitalsSheet extends StatefulWidget {
  const _VitalsSheet();

  @override
  State<_VitalsSheet> createState() => _VitalsSheetState();
}

class _VitalsSheetState extends State<_VitalsSheet> {
  final _form = GlobalKey<FormState>();
  final _controllers = {for (final m in measures) m.key: TextEditingController()};
  final _notes = TextEditingController();
  String? _error;

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    _notes.dispose();
    super.dispose();
  }

  String? _validate(Measure m, String? text) {
    if ((text ?? '').trim().isEmpty) return null;
    final value = parseNumber(text!);
    if (value == null || (m.integer && value != value.roundToDouble())) return m.integer ? 'Nombre entier attendu.' : 'Nombre attendu.';
    if (value < m.min || value > m.max) return 'Entre ${m.min} et ${m.max} ${m.unit}.';
    return null;
  }

  void _submit() {
    if (!_form.currentState!.validate()) return;
    final values = <String, num?>{};
    for (final m in measures) {
      final value = parseNumber(_controllers[m.key]!.text);
      values[m.key] = value == null ? null : (m.integer ? value.round() : value);
    }
    if (values.values.every((v) => v == null)) {
      setState(() => _error = 'Saisissez au moins une mesure.');
      return;
    }
    Navigator.pop(context, VitalsResult(values, _notes.text));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: Form(
        key: _form,
        child: ListView(shrinkWrap: true, padding: const EdgeInsets.all(16), children: [
          Text('Constantes', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text('Remplissez seulement ce qui a été mesuré.', style: TextStyle(color: context.vsh.textMuted)),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
          const SizedBox(height: 16),
          Wrap(spacing: 12, runSpacing: 12, children: [
            for (final m in measures)
              SizedBox(
                width: 160,
                child: TextFormField(
                  controller: _controllers[m.key],
                  keyboardType: TextInputType.numberWithOptions(decimal: !m.integer),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(m.integer ? r'[0-9]' : r'[0-9.,]'))],
                  decoration: InputDecoration(labelText: m.label, suffixText: m.unit),
                  validator: (v) => _validate(m, v),
                  textInputAction: TextInputAction.next,
                ),
              ),
          ]),
          const SizedBox(height: 12),
          TextFormField(
            controller: _notes,
            maxLength: 500,
            decoration: const InputDecoration(labelText: 'Remarque (facultatif)'),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _submit,
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
            child: const Text('Enregistrer les constantes'),
          ),
        ]),
      ),
    );
  }
}

/// Saisie d'un texte (note clinique, motif d'échec ou de désistement, commentaire).
Future<String?> showTextSheet(
  BuildContext context, {
  required String title,
  required String label,
  required String action,
  bool required = true,
  int maxLength = 500,
  String? help,
}) =>
    showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _TextSheet(title: title, label: label, action: action, required: required, maxLength: maxLength, help: help),
    );

class _TextSheet extends StatefulWidget {
  const _TextSheet({required this.title, required this.label, required this.action, required this.required, required this.maxLength, this.help});

  final String title;
  final String label;
  final String action;
  final bool required;
  final int maxLength;
  final String? help;

  @override
  State<_TextSheet> createState() => _TextSheetState();
}

class _TextSheetState extends State<_TextSheet> {
  final _controller = TextEditingController();
  final _form = GlobalKey<FormState>();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: Form(
          key: _form,
          child: ListView(shrinkWrap: true, padding: const EdgeInsets.all(16), children: [
            Text(widget.title, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            if (widget.help != null) ...[const SizedBox(height: 4), Text(widget.help!, style: TextStyle(color: context.vsh.textMuted))],
            const SizedBox(height: 16),
            TextFormField(
              controller: _controller,
              autofocus: true,
              minLines: 3,
              maxLines: 8,
              maxLength: widget.maxLength,
              decoration: InputDecoration(labelText: widget.label, alignLabelWithHint: true),
              validator: (v) => widget.required && (v ?? '').trim().isEmpty ? 'Obligatoire.' : null,
            ),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: () {
                if (_form.currentState!.validate()) Navigator.pop(context, _controller.text.trim());
              },
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
              child: Text(widget.action),
            ),
          ]),
        ),
      );
}
