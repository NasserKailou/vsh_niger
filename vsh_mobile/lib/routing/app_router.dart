import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../appointments/agenda_screen.dart';
import '../authentication/login_screen.dart';
import '../authentication/register_screen.dart';
import '../authentication/session_controller.dart';
import '../homecare/navigation_screen.dart';
import '../homecare/tour_screen.dart';
import '../homecare/visit_screen.dart';
import '../notifications/notifications_screen.dart';
import '../patient_space/patient_appointments_screen.dart';
import '../patient_space/patient_home_screen.dart';
import '../patient_space/patient_homecare_screen.dart';
import '../patient_space/patient_record_screen.dart';
import '../patient_space/patient_shell.dart';
import '../patients/patient_detail_screen.dart';
import '../patients/patient_form_screen.dart';
import '../patients/patient_list_screen.dart';
import '../shared/home_shell.dart';
import '../synchronization/sync_center_screen.dart';
import '../synchronization/sync_providers.dart';
import '../treatments/treatments_screen.dart';

/// Pages de la navigation principale (onglets du personnel et de l'espace patient).
const _tabRoots = ['/tour', '/care', '/agenda', '/patients', '/p/home', '/p/appointments', '/p/homecare', '/p/record'];

/// Pages plein écran déclarées hors des onglets, bien que leur chemin commence comme un onglet.
const _fullScreen = ['/patients/new', '/p/appointments/new', '/p/homecare/new'];

bool isTabLocation(String location) {
  final path = Uri.parse(location).path;
  if (_fullScreen.contains(path) || path.endsWith('/edit')) return false;
  return _tabRoots.any((root) => path == root || path.startsWith('$root/'));
}

/// Ouverture d'un écran depuis une notification (liste, alerte locale, message push).
///
/// Une page des onglets est ouverte par `go` : l'empiler (`push`) depuis un écran hors onglets
/// (liste des notifications) créerait une seconde navigation à onglets avec les mêmes clés de page,
/// et l'application s'arrêterait (« Failed assertion: !keyReservation.contains(key) »).
void openLocation(GoRouter router, String location) =>
    isTabLocation(location) ? router.go(location) : router.push(location);

/// Les identifiants dans les chemins sont des UUID : aucune donnée médicale dans les URL.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(sessionProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: '/tour',
    refreshListenable: refresh,
    redirect: (context, state) {
      final session = ref.read(sessionProvider).value;
      final path = state.matchedLocation;
      final target = switch (session?.status) {
        null => '/loading',
        SessionStatus.unconfigured => '/unconfigured',
        SessionStatus.signedOut => '/login',
        SessionStatus.mustChangePassword => '/password',
        SessionStatus.signedIn => null,
      };
      // Déconnecté : l'inscription d'un nouveau patient reste accessible depuis l'écran de connexion.
      if (target == '/login' && path == '/register') return null;
      if (target != null) return path == target ? null : target;
      // Une seule application, deux espaces : le personnel (tournée, soins, agenda, dossiers) et
      // les patients (rendez-vous, visites, résultats, documents). Chacun reste dans le sien.
      final patient = session?.user?.isPatient == true;
      final home = patient ? '/p/home' : '/tour';
      const outside = ['/loading', '/unconfigured', '/login', '/password'];
      if (outside.contains(path)) return home;
      const shared = ['/notifications'];
      if (shared.contains(path)) return null;
      final inPatientSpace = path.startsWith('/p/');
      return patient == inPatientSpace ? null : home;
    },
    routes: [
      GoRoute(path: '/loading', builder: (_, _) => const _Loading()),
      GoRoute(path: '/unconfigured', builder: (_, _) => const UnconfiguredScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, _) => const RegisterScreen()),
      GoRoute(path: '/password', builder: (_, _) => const ChangePasswordScreen()),
      GoRoute(path: '/sync', builder: (_, _) => const SyncCenterScreen()),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
      GoRoute(path: '/visits/:id', builder: (_, s) => VisitScreen(id: s.pathParameters['id']!)),
      GoRoute(path: '/visits/:id/route', builder: (_, s) => NavigationScreen(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/patients/new', builder: (_, _) => const PatientFormScreen()),
      GoRoute(path: '/patients/:id/edit', builder: (_, s) => PatientFormScreen(id: s.pathParameters['id']!)),
      GoRoute(path: '/p/appointments/new', builder: (_, _) => const NewAppointmentScreen()),
      GoRoute(path: '/p/homecare/new', builder: (_, _) => const NewVisitScreen()),
      ShellRoute(
        builder: (_, state, child) => PatientShell(location: state.matchedLocation, child: child),
        routes: [
          GoRoute(path: '/p/home', builder: (_, _) => const PatientHomeScreen()),
          GoRoute(path: '/p/appointments', builder: (_, _) => const PatientAppointmentsScreen()),
          GoRoute(path: '/p/homecare', builder: (_, _) => const PatientHomecareScreen()),
          GoRoute(path: '/p/record', builder: (_, s) => PatientRecordScreen(tab: s.uri.queryParameters['tab'])),
        ],
      ),
      ShellRoute(
        builder: (_, state, child) => HomeShell(location: state.matchedLocation, child: child),
        routes: [
          GoRoute(path: '/tour', builder: (_, _) => const TourScreen()),
          GoRoute(path: '/care', builder: (_, _) => const TreatmentsScreen()),
          GoRoute(path: '/agenda', builder: (_, _) => const AgendaScreen()),
          GoRoute(
            path: '/patients',
            builder: (_, _) => const PatientListScreen(),
            routes: [
              GoRoute(path: ':id', builder: (_, s) => PatientDetailScreen(id: s.pathParameters['id']!)),
            ],
          ),
        ],
      ),
    ],
  );
});

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}
