import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Navigation de l'espace patient : 4 entrées (charte §6), comme celle du personnel.
class PatientShell extends StatelessWidget {
  const PatientShell({super.key, required this.child, required this.location});

  final Widget child;
  final String location;

  static const _tabs = [
    ('/p/home', 'Accueil', Icons.home_outlined, Icons.home_rounded),
    ('/p/appointments', 'Rendez-vous', Icons.event_outlined, Icons.event_rounded),
    ('/p/homecare', 'À domicile', Icons.medical_services_outlined, Icons.medical_services_rounded),
    ('/p/record', 'Mon dossier', Icons.folder_shared_outlined, Icons.folder_shared_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final index = _tabs.indexWhere((t) => location.startsWith(t.$1));
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index < 0 ? 0 : index,
        onDestinationSelected: (i) => context.go(_tabs[i].$1),
        destinations: [
          for (final (_, label, icon, selected) in _tabs)
            NavigationDestination(icon: Icon(icon), selectedIcon: Icon(selected), label: label),
        ],
      ),
    );
  }
}
