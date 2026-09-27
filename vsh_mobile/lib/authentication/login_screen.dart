import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../network/api_client.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _phone = TextEditingController(text: '+227');
  final _password = TextEditingController();
  bool _busy = false;
  bool _hidden = true;
  String? _error;
  String? _info;

  /// Patient : n° de dossier + téléphone, puis code SMS (D-001). Sinon : téléphone + mot de passe.
  bool _smsMode = true;
  final _fileNumber = TextEditingController();
  final _code = TextEditingController();
  bool _codeSent = false;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _phone.dispose();
    _password.dispose();
    _fileNumber.dispose();
    _code.dispose();
    super.dispose();
  }

  void _setMode(bool sms) => setState(() {
        _smsMode = sms;
        _error = null;
        _info = null;
      });

  Future<void> _requestCode() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authRepositoryProvider).requestPatientCode(_fileNumber.text, _phone.text);
      _timer?.cancel();
      setState(() {
        _codeSent = true;
        _resendIn = 60;
        _info = 'Si le dossier ${_fileNumber.text.trim().toUpperCase()} correspond à ce numéro, un code vient d’être envoyé par SMS.';
      });
      _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
        if (!mounted || _resendIn <= 1) timer.cancel();
        if (mounted) setState(() => _resendIn = _resendIn > 0 ? _resendIn - 1 : 0);
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submitCode() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(sessionProvider.notifier).loginWithCode(_fileNumber.text, _phone.text, _code.text);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      _code.clear();
      if (mounted) setState(() => _busy = false);
    }
  }

  List<Widget> _smsFields() => [
        TextFormField(
          controller: _fileNumber,
          enabled: !_codeSent,
          textCapitalization: TextCapitalization.characters,
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(labelText: 'N° de dossier', helperText: 'Ex. VSH-2026-000123, sur votre carte ou vos documents'),
          validator: (v) => (v ?? '').trim().length < 5 ? 'Indiquez votre numéro de dossier.' : null,
        ),
        const SizedBox(height: 16),
        TextFormField(
          controller: _phone,
          enabled: !_codeSent,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          decoration: const InputDecoration(labelText: 'Téléphone', helperText: 'Le numéro enregistré à la clinique'),
          validator: (v) => (v ?? '').replaceAll(RegExp(r'\s'), '').length < 8 ? 'Saisissez votre numéro de téléphone.' : null,
        ),
        if (_codeSent) ...[
          const SizedBox(height: 16),
          TextFormField(
            controller: _code,
            keyboardType: TextInputType.number,
            autofillHints: const [AutofillHints.oneTimeCode],
            maxLength: 8,
            textInputAction: TextInputAction.done,
            onFieldSubmitted: (_) => _busy ? null : _submitCode(),
            decoration: const InputDecoration(labelText: 'Code reçu par SMS'),
            validator: (v) => RegExp(r'^\d{4,8}$').hasMatch((v ?? '').replaceAll(RegExp(r'\s'), '')) ? null : 'Saisissez le code à chiffres reçu par SMS.',
          ),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _busy ? null : (_codeSent ? _submitCode : _requestCode),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
          child: _busy
              ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : Text(_codeSent ? 'Me connecter' : 'Recevoir mon code par SMS'),
        ),
        if (_codeSent)
          Wrap(alignment: WrapAlignment.spaceBetween, children: [
            TextButton(
              onPressed: _busy || _resendIn > 0 ? null : _requestCode,
              child: Text(_resendIn > 0 ? 'Renvoyer le code (dans $_resendIn s)' : 'Renvoyer le code'),
            ),
            TextButton(
              onPressed: _busy
                  ? null
                  : () => setState(() {
                        _codeSent = false;
                        _info = null;
                        _timer?.cancel();
                      }),
              child: const Text('Modifier le dossier ou le téléphone'),
            ),
          ]),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: _busy ? null : () => context.push('/register'),
          icon: const Icon(Icons.person_add_alt_1_rounded),
          label: const Text('Nouveau patient ? Créer mon compte'),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
        ),
      ];

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(sessionProvider.notifier).login(_phone.text, _password.text);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      _password.clear();
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final notice = ref.watch(sessionProvider).value?.notice;
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _form,
                child: AutofillGroup(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const _Brand(),
                    const SizedBox(height: 32),
                    SegmentedButton<bool>(
                      segments: const [
                        ButtonSegment(value: true, label: Text('Code SMS'), icon: Icon(Icons.sms_outlined)),
                        ButtonSegment(value: false, label: Text('Mot de passe'), icon: Icon(Icons.password_rounded)),
                      ],
                      selected: {_smsMode},
                      onSelectionChanged: _busy ? null : (s) => _setMode(s.first),
                    ),
                    const SizedBox(height: 24),
                    Text(_smsMode ? 'Espace patient' : 'Connexion', style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    Text(
                        _smsMode
                            ? 'Sans mot de passe : un code à usage unique vous est envoyé par SMS. Vous restez connecté sur ce téléphone.'
                            : 'Personnel de la clinique, ou patient inscrit avec un mot de passe. Ensuite, l’application fonctionne hors ligne.',
                        style: TextStyle(color: context.vsh.textMuted)),
                    if (notice != null) ...[const SizedBox(height: 16), _Message(notice, warning: true)],
                    if (_info != null && _smsMode) ...[const SizedBox(height: 16), _Message(_info!, warning: true)],
                    if (_error != null) ...[const SizedBox(height: 16), _Message(_error!)],
                    const SizedBox(height: 24),
                    if (_smsMode) ..._smsFields() else ...[
                    TextFormField(
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      textInputAction: TextInputAction.next,
                      decoration: const InputDecoration(labelText: 'Téléphone', helperText: 'Format +227XXXXXXXX'),
                      validator: (v) => (v ?? '').replaceAll(RegExp(r'\s'), '').length < 8 ? 'Saisissez votre numéro de téléphone.' : null,
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _password,
                      obscureText: _hidden,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => _busy ? null : _submit(),
                      decoration: InputDecoration(
                        labelText: 'Mot de passe',
                        suffixIcon: IconButton(
                          tooltip: _hidden ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
                          icon: Icon(_hidden ? Icons.visibility_rounded : Icons.visibility_off_rounded),
                          onPressed: () => setState(() => _hidden = !_hidden),
                        ),
                      ),
                      validator: (v) => (v ?? '').isEmpty ? 'Saisissez votre mot de passe.' : null,
                    ),
                    const SizedBox(height: 24),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                      child: _busy
                          ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                          : const Text('Se connecter'),
                    ),
                    ],
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Mot de passe temporaire à remplacer avant d'accéder aux données.
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [_current, _next, _confirm]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(sessionProvider.notifier).changePassword(_current.text, _next.text);
    } on ApiException catch (e) {
      setState(() => _error = [e.message, ...e.errors.values.expand((m) => m)].join('\n'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Nouveau mot de passe'),
        actions: [
          TextButton(onPressed: () => ref.read(sessionProvider.notifier).logout(), child: const Text('Se déconnecter')),
        ],
      ),
      body: SafeArea(
        child: Form(
          key: _form,
          child: ListView(padding: const EdgeInsets.all(24), children: [
            const Text('Votre mot de passe est temporaire. Choisissez-en un nouveau pour continuer.'),
            if (_error != null) ...[const SizedBox(height: 16), _Message(_error!)],
            const SizedBox(height: 24),
            TextFormField(
              controller: _current,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Mot de passe actuel'),
              validator: (v) => (v ?? '').isEmpty ? 'Obligatoire.' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _next,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Nouveau mot de passe', helperText: 'Les règles sont vérifiées par le serveur.'),
              validator: (v) => (v ?? '').length < 8 ? 'Au moins 8 caractères.' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _confirm,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Confirmation'),
              validator: (v) => v != _next.text ? 'Les deux mots de passe diffèrent.' : null,
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
              child: const Text('Enregistrer'),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Configuration absente à la compilation : aucune adresse de serveur n'est écrite dans le code.
class UnconfiguredScreen extends StatelessWidget {
  const UnconfiguredScreen({super.key});

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: SafeArea(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Center(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                _Brand(),
                SizedBox(height: 24),
                Text('Application non configurée', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                SizedBox(height: 8),
                Text(
                  "L'adresse du serveur (HTTPS) doit être fournie à la compilation : "
                  '--dart-define=VSH_API_BASE_URL=https://…/api/v1',
                  textAlign: TextAlign.center,
                ),
              ]),
            ),
          ),
        ),
      );
}

class _Brand extends StatelessWidget {
  const _Brand();

  @override
  Widget build(BuildContext context) => Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(color: VshColors.brandGreen, borderRadius: BorderRadius.circular(10)),
          child: const Icon(Icons.home_work_rounded, color: Colors.white, semanticLabel: 'Vision Homecare'),
        ),
        const SizedBox(width: 12),
        const Text.rich(TextSpan(children: [
          TextSpan(text: 'VISION ', style: TextStyle(color: VshColors.brandOrange, fontWeight: FontWeight.w800, fontSize: 20)),
          TextSpan(text: 'HOMECARE', style: TextStyle(color: VshColors.brandGreen, fontWeight: FontWeight.w800, fontSize: 20)),
        ])),
      ]);
}

class _Message extends StatelessWidget {
  const _Message(this.text, {this.warning = false});

  final String text;
  final bool warning;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: warning ? colors.warningSoft : colors.dangerSoft,
          borderRadius: BorderRadius.circular(6),
        ),
        child: Text(text, style: TextStyle(color: warning ? colors.warning : colors.danger)),
      ),
    );
  }
}

