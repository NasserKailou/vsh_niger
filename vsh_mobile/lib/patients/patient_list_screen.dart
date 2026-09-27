import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../database/app_database.dart';
import '../network/api_client.dart';
import '../notifications/notifications_screen.dart';
import '../shared/account_menu.dart';
import '../shared/sync_status_badge.dart';
import '../synchronization/sync_providers.dart';
import '../synchronization/sync_scheduler.dart';
import '../theme/app_theme.dart';

final _searchProvider = NotifierProvider<_Search, String>(_Search.new);

class _Search extends Notifier<String> {
  @override
  String build() => '';
  void set(String value) => state = value;
}

final _patientsProvider = StreamProvider<List<Patient>>((ref) => ref.watch(patientRepositoryProvider).watchSearch(ref.watch(_searchProvider)));
final unsyncedPatientsProvider = StreamProvider<Set<String>>((ref) => ref.watch(patientRepositoryProvider).watchUnsynced());

/// Dossiers disponibles sur le téléphone (périmètre de l'utilisateur, D-007), consultables hors ligne.
class PatientListScreen extends ConsumerStatefulWidget {
  const PatientListScreen({super.key});

  @override
  ConsumerState<PatientListScreen> createState() => _PatientListScreenState();
}

class _PatientListScreenState extends ConsumerState<PatientListScreen> {
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final patients = ref.watch(_patientsProvider);
    final unsynced = ref.watch(unsyncedPatientsProvider).value ?? const {};
    final searching = ref.watch(_searchProvider).isNotEmpty;
    return Scaffold(
      appBar: AppBar(title: const Text('Patients'), actions: const [NotificationBell(), SyncStatusBadge(), AccountMenu(), SizedBox(width: 4)]),
      body: Column(children: [
        const OfflineBanner(),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: TextField(
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search_rounded),
              hintText: 'Nom, n° de dossier ou téléphone',
              labelText: 'Rechercher',
            ),
            textInputAction: TextInputAction.search,
            onChanged: (value) {
              _debounce?.cancel();
              _debounce = Timer(const Duration(milliseconds: 250), () => ref.read(_searchProvider.notifier).set(value));
            },
          ),
        ),
        if (ref.watch(_searchProvider).trim().length >= 3 && ref.watch(connectivityProvider).value != false)
          _RemoteHint(ref.watch(_searchProvider).trim())
        else if (ref.watch(_searchProvider).trim().length >= 2)
          Align(
            alignment: Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: TextButton.icon(
                onPressed: () => showModalBottomSheet<void>(
                  context: context,
                  isScrollControlled: true,
                  useSafeArea: true,
                  builder: (_) => _RemoteSearchSheet(ref.read(_searchProvider).trim()),
                ),
                icon: const Icon(Icons.cloud_download_outlined),
                label: const Text('Chercher aussi sur le serveur'),
              ),
            ),
          ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref.read(syncEngineProvider).run(),
            child: patients.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (_, _) => const Center(child: Text('Lecture des dossiers impossible.')),
              data: (items) => items.isEmpty
                  ? ListView(children: [
                      Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(
                          searching
                              ? 'Aucun dossier trouvé sur ce téléphone.'
                              : 'Aucun dossier sur ce téléphone pour le moment. Ils arrivent à la synchronisation '
                                  '(dossiers suivis, créés ou complétés récemment, épinglés).',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: context.vsh.textMuted),
                        ),
                      ),
                    ])
                  : ListView.separated(
                      padding: const EdgeInsets.only(bottom: 96),
                      itemCount: items.length,
                      separatorBuilder: (_, _) => const Divider(indent: 72),
                      itemBuilder: (context, i) => PatientTile(items[i], unsynced: unsynced.contains(items[i].id)),
                    ),
            ),
          ),
        ),
      ]),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final id = await context.push<String>('/patients/new');
          if (id != null) {
            ref.read(syncSchedulerProvider).poke();
            if (context.mounted) context.push('/patients/$id');
          }
        },
        icon: const Icon(Icons.person_add_alt_1_rounded),
        label: const Text('Nouveau patient'),
      ),
    );
  }
}

/// Recherche serveur lancée au fil de la frappe (3 lettres, réseau disponible) : dossiers hors du
/// téléphone trouvés par le serveur, en quelques millisecondes grâce aux index (docs/22).
final _remoteSearchProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, String>(
    (ref, term) => ref.read(patientRemoteProvider).search(term));

class _RemoteHint extends ConsumerWidget {
  const _RemoteHint(this.term);

  final String term;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final local = ref.watch(_localIdsProvider).value ?? const {};
    final remote = ref.watch(_remoteSearchProvider(term));
    final muted = context.vsh.textMuted;
    final (IconData icon, String label, bool open) = remote.when(
      loading: () => (Icons.cloud_sync_outlined, 'Recherche sur le serveur…', false),
      error: (_, _) => (Icons.cloud_off_outlined, 'Serveur injoignable : résultats du téléphone seulement', false),
      data: (items) {
        final others = items.where((p) => !local.contains(p['id'])).length;
        return others == 0
            ? (Icons.cloud_done_outlined, 'Aucun autre dossier sur le serveur', false)
            : (Icons.cloud_download_outlined, '$others autre${others > 1 ? 's' : ''} dossier${others > 1 ? 's' : ''} sur le serveur', true);
      },
    );
    return ListTile(
      dense: true,
      leading: Icon(icon, color: open ? null : muted),
      title: Text(label, style: TextStyle(color: open ? null : muted)),
      trailing: open ? const Icon(Icons.chevron_right_rounded) : null,
      onTap: open
          ? () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                useSafeArea: true,
                builder: (_) => _RemoteSearchSheet(term),
              )
          : null,
    );
  }
}

class PatientTile extends StatelessWidget {
  const PatientTile(this.patient, {super.key, this.unsynced = false});

  final Patient patient;
  final bool unsynced;

  @override
  Widget build(BuildContext context) {
    final colors = context.vsh;
    final subtitle = [
      patient.fileNumber ?? 'N° attribué à la synchronisation',
      if (patient.birthDate != null) patient.birthDate!,
    ].join(' · ');
    return ListTile(
      minTileHeight: 64,
      leading: CircleAvatar(
        backgroundColor: Theme.of(context).colorScheme.primaryContainer,
        child: Text(
          '${patient.lastName.isNotEmpty ? patient.lastName[0] : ''}${patient.firstName.isNotEmpty ? patient.firstName[0] : ''}',
          style: TextStyle(color: Theme.of(context).colorScheme.primary, fontWeight: FontWeight.w700),
        ),
      ),
      title: Text('${patient.lastName.toUpperCase()} ${patient.firstName}', style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(subtitle),
      trailing: unsynced
          ? Tooltip(
              message: 'Modification pas encore envoyée',
              child: Icon(Icons.cloud_upload_outlined, color: colors.warning, semanticLabel: 'Modification pas encore envoyée'),
            )
          : const Icon(Icons.chevron_right_rounded),
      onTap: () => context.push('/patients/${patient.id}'),
    );
  }
}

/// Recherche en ligne (dossiers hors du téléphone) et épinglage pour le travail hors ligne.
class _RemoteSearchSheet extends ConsumerStatefulWidget {
  const _RemoteSearchSheet(this.term);
  final String term;

  @override
  ConsumerState<_RemoteSearchSheet> createState() => _RemoteSearchSheetState();
}

class _RemoteSearchSheetState extends ConsumerState<_RemoteSearchSheet> {
  List<Map<String, dynamic>>? _results;
  String? _error;
  final _pinned = <String>{};
  final _busy = <String>{};

  @override
  void initState() {
    super.initState();
    _search();
  }

  Future<void> _search() async {
    try {
      final results = await ref.read(patientRemoteProvider).search(widget.term);
      if (mounted) setState(() => _results = results);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.isNetwork ? 'Recherche impossible hors connexion.' : e.message);
    }
  }

  Future<void> _pin(String id) async {
    setState(() => _busy.add(id));
    try {
      await ref.read(patientRemoteProvider).pin(id);
      _pinned.add(id);
      await ref.read(syncEngineProvider).run();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy.remove(id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final local = ref.watch(_localIdsProvider).value ?? const {};
    return SizedBox(
      height: MediaQuery.sizeOf(context).height * 0.75,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Serveur : « ${widget.term} »', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text('« Garder sur le téléphone » rend le dossier disponible hors ligne.', style: TextStyle(color: context.vsh.textMuted)),
          ]),
        ),
        Expanded(
          child: _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)))
              : _results == null
                  ? const Center(child: CircularProgressIndicator())
                  : _results!.isEmpty
                      ? const Center(child: Text('Aucun dossier trouvé.'))
                      : ListView.builder(
                          itemCount: _results!.length,
                          itemBuilder: (context, i) {
                            final p = _results![i];
                            final id = p['id'] as String;
                            final onPhone = local.contains(id);
                            return ListTile(
                              title: Text('${'${p['last_name']}'.toUpperCase()} ${p['first_name']}'),
                              subtitle: Text([p['file_number'], p['birth_date']].whereType<String>().join(' · ')),
                              trailing: onPhone
                                  ? const Chip(label: Text('Sur le téléphone'))
                                  : _pinned.contains(id)
                                      ? const Chip(label: Text('Épinglé'))
                                      : FilledButton.tonal(
                                          onPressed: _busy.contains(id) ? null : () => _pin(id),
                                          child: const Text('Garder sur le téléphone'),
                                        ),
                              onTap: onPhone
                                  ? () {
                                      Navigator.pop(context);
                                      context.push('/patients/$id');
                                    }
                                  : null,
                            );
                          },
                        ),
        ),
      ]),
    );
  }
}

final _localIdsProvider = StreamProvider<Set<String>>((ref) {
  final db = ref.watch(databaseProvider);
  return db.select(db.patients).watch().map((rows) => rows.map((p) => p.id).toSet());
});
