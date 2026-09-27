import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';

/// Création ou modification de l'identité, hors ligne. Le serveur revérifie tout à la réception.
class PatientFormScreen extends ConsumerStatefulWidget {
  const PatientFormScreen({super.key, this.id});

  final String? id;

  @override
  ConsumerState<PatientFormScreen> createState() => _PatientFormScreenState();
}

class _PatientFormScreenState extends ConsumerState<PatientFormScreen> {
  final _form = GlobalKey<FormState>();
  final _first = TextEditingController();
  final _last = TextEditingController();
  final _phone = TextEditingController();
  final _birth = TextEditingController();
  String? _sex;
  bool _estimated = false;
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (widget.id != null) {
      final db = ref.read(databaseProvider);
      final row = await (db.select(db.patients)..where((t) => t.id.equals(widget.id!))).getSingleOrNull();
      if (row != null) {
        final data = (jsonDecode(row.data) as Map).cast<String, dynamic>();
        _first.text = row.firstName;
        _last.text = row.lastName;
        _phone.text = row.phone ?? '';
        _birth.text = row.birthDate ?? '';
        _sex = row.sex;
        _estimated = data['birth_date_is_estimated'] == true;
      }
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  void dispose() {
    for (final c in [_first, _last, _phone, _birth]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickDate() async {
    final initial = DateTime.tryParse(_birth.text) ?? DateTime(DateTime.now().year - 30);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(1900),
      lastDate: DateTime.now(),
      helpText: 'Date de naissance',
    );
    if (picked != null) {
      _birth.text = '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    }
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    final values = {
      'first_name': _first.text,
      'last_name': _last.text,
      'sex': _sex,
      'birth_date': _birth.text,
      'birth_date_is_estimated': _estimated,
      'phone': _phone.text.replaceAll(RegExp(r'\s'), ''),
    };
    final repository = ref.read(patientRepositoryProvider);
    try {
      if (widget.id == null) {
        final id = await repository.create(values);
        if (mounted) context.pop(id);
      } else {
        final changed = await repository.update(widget.id!, values);
        if (mounted) context.pop(changed ? widget.id : null);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final creating = widget.id == null;
    return Scaffold(
      appBar: AppBar(title: Text(creating ? 'Nouveau patient' : "Modifier l'identité")),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _form,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Text(
                  creating
                      ? 'Enregistré sur le téléphone, puis envoyé à la clinique dès que possible. Le n° de dossier sera attribué à la réception.'
                      : 'Seuls les champs modifiés sont envoyés. Si quelqu’un a modifié le même champ entre-temps, la valeur de la clinique est conservée et vous êtes prévenu.',
                  style: TextStyle(color: context.vsh.textMuted),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _last,
                  textCapitalization: TextCapitalization.characters,
                  decoration: const InputDecoration(labelText: 'Nom *'),
                  validator: _required,
                  maxLength: 100,
                ),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _first,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(labelText: 'Prénom *'),
                  validator: _required,
                  maxLength: 100,
                ),
                const SizedBox(height: 8),
                FormField<String>(
                  initialValue: _sex,
                  validator: (v) => v == null ? 'Choisissez le sexe.' : null,
                  builder: (field) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('Sexe *'),
                    const SizedBox(height: 8),
                    SegmentedButton<String>(
                      emptySelectionAllowed: true,
                      segments: const [
                        ButtonSegment(value: 'F', label: Text('Féminin')),
                        ButtonSegment(value: 'M', label: Text('Masculin')),
                      ],
                      selected: {?_sex},
                      onSelectionChanged: (s) {
                        setState(() => _sex = s.isEmpty ? null : s.first);
                        field.didChange(_sex);
                      },
                    ),
                    if (field.hasError)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Text(field.errorText!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
                      ),
                  ]),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _birth,
                  readOnly: true,
                  onTap: _pickDate,
                  decoration: InputDecoration(
                    labelText: 'Date de naissance',
                    suffixIcon: _birth.text.isEmpty
                        ? const Icon(Icons.calendar_today_rounded)
                        : IconButton(
                            tooltip: 'Effacer la date',
                            icon: const Icon(Icons.clear_rounded),
                            onPressed: () => setState(_birth.clear),
                          ),
                  ),
                ),
                CheckboxListTile(
                  value: _estimated,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  title: const Text('Date estimée (âge approximatif)'),
                  onChanged: (v) => setState(() => _estimated = v ?? false),
                ),
                TextFormField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Téléphone', helperText: 'Format +227XXXXXXXX'),
                  validator: (v) {
                    final value = (v ?? '').replaceAll(RegExp(r'\s'), '');
                    if (value.isEmpty) return null;
                    return RegExp(r'^\+?[0-9]{8,15}$').hasMatch(value) ? null : 'Numéro invalide.';
                  },
                ),
              ]),
            ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: FilledButton(
          onPressed: _busy || _loading ? null : _save,
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
          child: const Text('Enregistrer'),
        ),
      ),
    );
  }

  static String? _required(String? v) => (v ?? '').trim().isEmpty ? 'Obligatoire.' : null;
}
