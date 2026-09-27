import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../config/env.dart';
import '../synchronization/sync_providers.dart';
import '../theme/app_theme.dart';

const _roleLabels = {
  'ADMIN': 'Administrateur',
  'MEDECIN': 'Médecin',
  'INFIRMIER': 'Infirmier',
  'TECHNICIEN': 'Technicien de santé',
  'ACCUEIL': 'Accueil',
  'PATIENT': 'Patient',
};

/// Menu « Mon compte », dans la barre de tous les écrans principaux, pour tous les profils :
/// identité et rôle, centre de synchronisation (personnel), version, déconnexion.
///
/// Déconnexion : les modifications pas encore envoyées restent sur le téléphone et partiront à la
/// prochaine connexion du même compte ; elles sont perdues si un autre compte s'y connecte. Le
/// message le dit clairement avant de confirmer.
class AccountMenu extends ConsumerWidget {
  const AccountMenu({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).value?.user;
    final roles = ((user?.raw['roles'] as List?) ?? const [])
        .map((r) => r is Map ? '${r['code']}' : '$r')
        .map((code) => _roleLabels[code] ?? code)
        .join(', ');
    return PopupMenuButton<String>(
      tooltip: 'Mon compte',
      icon: const Icon(Icons.account_circle_outlined),
      onSelected: (value) {
        switch (value) {
          case 'sync':
            context.push('/sync');
          case 'logout':
            confirmLogout(context, ref);
        }
      },
      itemBuilder: (context) => [
        PopupMenuItem<String>(
          enabled: false,
          child: ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.person_rounded),
            title: Text(user?.displayName ?? 'Mon compte', style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: roles.isEmpty ? null : Text(roles),
          ),
        ),
        const PopupMenuDivider(),
        if (user?.isStaff == true)
          const PopupMenuItem<String>(
            value: 'sync',
            child: ListTile(contentPadding: EdgeInsets.zero, leading: Icon(Icons.sync_rounded), title: Text('Synchronisation')),
          ),
        const PopupMenuItem<String>(
          value: 'logout',
          child: ListTile(contentPadding: EdgeInsets.zero, leading: Icon(Icons.logout_rounded), title: Text('Se déconnecter')),
        ),
        PopupMenuItem<String>(
          enabled: false,
          height: 32,
          child: Text('Vision Homecare ${Env.appVersion}', style: TextStyle(fontSize: 12, color: context.vsh.textMuted)),
        ),
      ],
    );
  }
}

/// Confirmation de déconnexion, avec le nombre de modifications pas encore envoyées.
Future<void> confirmLogout(BuildContext context, WidgetRef ref) async {
  final pending = ref.read(queueCountsProvider).value?.outgoing ?? 0;
  final colors = context.vsh;
  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Se déconnecter ?'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (pending > 0) ...[
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: colors.warningSoft, borderRadius: BorderRadius.circular(8)),
            child: Text(
              '$pending modification${pending > 1 ? 's' : ''} pas encore envoyée${pending > 1 ? 's' : ''} au serveur. '
              'Connectez-vous à Internet et attendez la synchronisation avant de partir, ou reconnectez-vous avec le même compte : '
              'elles seraient perdues si un autre compte se connecte sur ce téléphone.',
              style: TextStyle(color: colors.warning),
            ),
          ),
          const SizedBox(height: 12),
        ],
        const Text('Les informations enregistrées sur ce téléphone seront effacées si un autre compte s’y connecte.'),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Rester connecté')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Se déconnecter')),
      ],
    ),
  );
  if (ok == true) await ref.read(sessionProvider.notifier).logout();
}
