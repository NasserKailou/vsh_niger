import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../homecare/track_recorder.dart';
import '../synchronization/sync_providers.dart';

/// Navigation principale : 4 entrées au plus (charte §6). Garde actif l'enregistrement du trajet.
class HomeShell extends ConsumerWidget {
  const HomeShell({super.key, required this.child, required this.location});

  final Widget child;
  final String location;

  static const _tabs = [
    ('/tour', 'Tournée', Icons.route_outlined, Icons.route_rounded),
    ('/care', 'Soins', Icons.healing_outlined, Icons.healing_rounded),
    ('/agenda', 'Agenda', Icons.event_outlined, Icons.event_rounded),
    ('/patients', 'Patients', Icons.people_outline_rounded, Icons.people_rounded),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(trackRecorderProvider);
    final attention = ref.watch(syncOverviewProvider).attention;
    final index = _tabs.indexWhere((t) => location.startsWith(t.$1));
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index < 0 ? 0 : index,
        onDestinationSelected: (i) => context.go(_tabs[i].$1),
        destinations: [
          for (final (_, label, icon, selected) in _tabs)
            NavigationDestination(
              icon: label == 'Tournée' && attention > 0 ? Badge(label: Text('$attention'), child: Icon(icon)) : Icon(icon),
              selectedIcon: Icon(selected),
              label: label,
            ),
        ],
      ),
    );
  }
}
