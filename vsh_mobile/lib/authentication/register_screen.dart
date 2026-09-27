import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../network/api_client.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';

/// Inscription d'un nouveau patient depuis l'application (même route que le portail web).
///
/// Étape 1 : téléphone, puis code de vérification par SMS si la clinique l'exige.
/// Étape 2 : identité, mot de passe, adresse facultative. Le dossier est créé « en attente de
/// validation » : l'accueil le valide (vérification de l'identité) et lui donne son numéro. En attendant,
/// le patient peut déjà demander un rendez-vous.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _phone = TextEditingController(text: '+227');
  final _code = TextEditingController();
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  final _city = TextEditingController(text: 'Niamey');
  final _district = TextEditingController();
  final _landmark = TextEditingController();
  String? _sex;
  DateTime? _birthDate;

  /// null : téléphone pas encore vérifié ; true/false : code exigé ou non.
  bool? _codeRequired;
  bool _busy = false;
  bool _hidden = true;
  String? _error;
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void dispose() {
    for (final c in [_phone, _code, _firstName, _lastName, _password, _confirm, _city, _district, _landmark]) {
      c.dispose();
    }
    super.dispose();
  }

  String? _serverError(String field) => _fieldErrors[field]?.join(' ');

  Future<void> _requestCode() async {
    if (_phone.text.replaceAll(RegExp(r'\s'), '').length < 8) {
      setState(() => _error = 'Saisissez votre numéro de téléphone.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final required = await ref.read(authRepositoryProvider).requestRegistrationCode(_phone.text);
      setState(() => _codeRequired = required);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _fieldErrors = e.errors;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    setState(() => _fieldErrors = const {});
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    String? blank(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    final address = {
      'label': 'Domicile',
      'city': blank(_city),
      'district': blank(_district),
      'landmark': blank(_landmark),
      'is_primary': true,
    };
    try {
      await ref.read(sessionProvider.notifier).register({
        'phone': _phone.text.trim(),
        if (_codeRequired == true) 'code': _code.text.replaceAll(RegExp(r'\s'), ''),
        'first_name': _firstName.text.trim(),
        'last_name': _lastName.text.trim(),
        'sex': _sex,
        if (_birthDate != null) 'birth_date': DateFormat('yyyy-MM-dd').format(_birthDate!),
        'password': _password.text,
        if (address['district'] != null || address['landmark'] != null) 'address': address,
      });
      // Session ouverte : le routeur conduit à l'espace patient.
    } on ApiException catch (e) {
      setState(() {
        _error = e.code == 'PHONE_ALREADY_REGISTERED' ? '${e.message} (écran de connexion, « Mot de passe »).' : e.message;
        _fieldErrors = e.errors;
      });
      _form.currentState!.validate();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickBirthDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _birthDate ?? DateTime(now.year - 30),
      firstDate: DateTime(now.year - 120),
      lastDate: now,
      helpText: 'Date de naissance',
    );
    if (picked != null) setState(() => _birthDate = picked);
  }

  @override
  Widget build(BuildContext context) {
    final muted = context.vsh.textMuted;
    final verified = _codeRequired != null;
    return Scaffold(
      appBar: AppBar(title: const Text('Créer mon compte patient')),
      body: SafeArea(
        child: Form(
          key: _form,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Text(
              'Votre dossier sera validé par l’accueil de la clinique, qui vérifiera votre identité et vous donnera votre numéro de dossier.',
              style: TextStyle(color: muted),
            ),
            if (_error != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: context.vsh.dangerSoft, borderRadius: BorderRadius.circular(8)),
                child: Text(_error!, style: TextStyle(color: context.vsh.danger)),
              ),
            ],
            const SizedBox(height: 16),
            TextFormField(
              controller: _phone,
              enabled: !verified,
              keyboardType: TextInputType.phone,
              autofillHints: const [AutofillHints.telephoneNumber],
              decoration: const InputDecoration(labelText: 'Téléphone', helperText: 'Il servira à vous connecter et à recevoir les messages de la clinique.'),
              validator: (v) => _serverError('phone') ?? ((v ?? '').replaceAll(RegExp(r'\s'), '').length < 8 ? 'Saisissez votre numéro de téléphone.' : null),
            ),
            if (!verified) ...[
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _busy ? null : _requestCode,
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : const Text('Continuer'),
              ),
            ] else ...[
              if (_codeRequired == true) ...[
                const SizedBox(height: 12),
                TextFormField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  autofillHints: const [AutofillHints.oneTimeCode],
                  maxLength: 8,
                  decoration: const InputDecoration(labelText: 'Code reçu par SMS'),
                  validator: (v) => _serverError('code') ??
                      (RegExp(r'^\d{4,8}$').hasMatch((v ?? '').replaceAll(RegExp(r'\s'), '')) ? null : 'Saisissez le code reçu par SMS.'),
                ),
              ],
              const SizedBox(height: 12),
              TextFormField(
                controller: _firstName,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(labelText: 'Prénom'),
                validator: (v) => _serverError('first_name') ?? ((v ?? '').trim().isEmpty ? 'Indiquez votre prénom.' : null),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _lastName,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(labelText: 'Nom'),
                validator: (v) => _serverError('last_name') ?? ((v ?? '').trim().isEmpty ? 'Indiquez votre nom.' : null),
              ),
              const SizedBox(height: 16),
              FormField<String>(
                validator: (_) => _serverError('sex') ?? (_sex == null ? 'Indiquez le sexe.' : null),
                builder: (field) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SegmentedButton<String>(
                    segments: const [ButtonSegment(value: 'F', label: Text('Femme')), ButtonSegment(value: 'M', label: Text('Homme'))],
                    selected: {?_sex},
                    emptySelectionAllowed: true,
                    onSelectionChanged: (s) => setState(() => _sex = s.isEmpty ? null : s.first),
                  ),
                  if (field.errorText != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(field.errorText!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
                    ),
                ]),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _pickBirthDate,
                icon: const Icon(Icons.cake_outlined),
                label: Text(_birthDate == null ? 'Date de naissance (facultatif)' : 'Né(e) le ${DateFormat('d MMMM y', 'fr').format(_birthDate!)}'),
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), alignment: Alignment.centerLeft),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _password,
                obscureText: _hidden,
                autofillHints: const [AutofillHints.newPassword],
                decoration: InputDecoration(
                  labelText: 'Mot de passe',
                  helperText: '8 caractères au moins, avec une lettre et un chiffre',
                  suffixIcon: IconButton(
                    tooltip: _hidden ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
                    icon: Icon(_hidden ? Icons.visibility_rounded : Icons.visibility_off_rounded),
                    onPressed: () => setState(() => _hidden = !_hidden),
                  ),
                ),
                validator: (v) {
                  final value = v ?? '';
                  if (_serverError('password') != null) return _serverError('password');
                  if (value.length < 8 || !RegExp(r'\p{L}', unicode: true).hasMatch(value) || !RegExp(r'\d').hasMatch(value)) {
                    return '8 caractères au moins, avec une lettre et un chiffre.';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _confirm,
                obscureText: _hidden,
                decoration: const InputDecoration(labelText: 'Confirmer le mot de passe'),
                validator: (v) => v != _password.text ? 'Les deux mots de passe sont différents.' : null,
              ),
              const SizedBox(height: 20),
              Text('Adresse (facultatif, aide l’équipe à domicile)', style: Theme.of(context).textTheme.titleSmall),
              TextFormField(controller: _city, decoration: const InputDecoration(labelText: 'Ville')),
              TextFormField(controller: _district, decoration: const InputDecoration(labelText: 'Quartier')),
              TextFormField(
                controller: _landmark,
                decoration: const InputDecoration(labelText: 'Repère', helperText: 'Ex. derrière la mosquée, portail bleu.'),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _busy ? null : _submit,
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : const Text('Créer mon compte'),
              ),
            ],
            const SizedBox(height: 8),
            TextButton(onPressed: () => context.pop(), child: const Text('J’ai déjà un compte')),
          ]),
        ),
      ),
    );
  }
}
