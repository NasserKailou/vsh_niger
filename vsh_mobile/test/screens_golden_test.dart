import 'dart:io';

import 'package:drift/drift.dart' show Value, driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:vsh_mobile/appointments/agenda_screen.dart';
import 'package:vsh_mobile/authentication/auth_repository.dart';
import 'package:vsh_mobile/authentication/session_controller.dart';
import 'package:vsh_mobile/authentication/token_store.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/database/local_store.dart';
import 'package:vsh_mobile/homecare/location_service.dart';
import 'package:vsh_mobile/homecare/tour_screen.dart';
import 'package:vsh_mobile/homecare/track_recorder.dart';
import 'package:vsh_mobile/homecare/visit_screen.dart';
import 'package:vsh_mobile/shared/sync_status_badge.dart';
import 'package:vsh_mobile/synchronization/sync_center_screen.dart';
import 'package:vsh_mobile/synchronization/sync_providers.dart';
import 'package:vsh_mobile/theme/app_theme.dart';
import 'package:vsh_mobile/treatments/treatments_screen.dart';

/// Rendu des écrans à la taille d'un téléphone (390 × 844), avec des données fictives.
/// Mise à jour des images : `flutter test --update-goldens test/screens_golden_test.dart`
/// (après une modification volontaire de l'interface ; vérifier les images avant de les garder).
void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  setUpAll(() async {
    await initializeDateFormatting('fr');
    // Heure fixe : les images ne dépendent pas du moment où les tests tournent.
    appNow = () => DateTime(2026, 9, 27, 18, 30);
    // Cache des fonds de carte (dossier de l'application sur le téléphone).
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('plugins.flutter.io/path_provider'),
      (call) async => Directory.systemTemp.createTempSync('vsh_tiles_').path,
    );
    final fonts = '${Platform.environment['FLUTTER_ROOT'] ?? r'C:\Users\kailo\development\flutter'}/bin/cache/artifacts/material_fonts';
    Future<void> load(String family, List<String> files) async {
      final loader = FontLoader(family);
      for (final f in files) {
        final file = File('$fonts/$f');
        if (file.existsSync()) loader.addFont(Future.value(ByteData.sublistView(file.readAsBytesSync())));
      }
      await loader.load();
    }

    await load('Roboto', ['roboto-regular.ttf', 'roboto-medium.ttf', 'roboto-bold.ttf']);
    await load('MaterialIcons', ['materialicons-regular.otf']);
  });

  Future<AppDatabase> seed() async {
    final db = AppDatabase(NativeDatabase.memory());
    final store = LocalStore(db);
    await store.upsert('homecare_request', 'v1', {
      'id': 'v1',
      'status': 'EN_ROUTE',
      'version': 3,
      'patient_id': 'p1',
      'patient': {'id': 'p1', 'name': 'Aïcha TEST', 'file_number': 'VSH-2026-000009', 'sex': 'F'},
      'reason': 'Pansement de plaie au pied, contrôle de la cicatrisation',
      'urgency': 'NORMALE',
      'landmark': 'Derrière la mosquée, portail bleu',
      'address_text': 'Quartier Plateau, Niamey',
      'contact_phone': '+22790000099',
      'team': {'id': 't1', 'label': 'Équipe mobile A'},
      'location': {'latitude': 13.5116, 'longitude': 2.1254, 'accuracy_m': 12.0},
      'history': [
        {'from': 'EN_ATTENTE', 'to': 'PRISE_EN_CHARGE', 'at': '2026-09-27T08:02:00Z', 'by': {'name': 'Aïcha Infirmière'}},
        {'from': 'PRISE_EN_CHARGE', 'to': 'EN_ROUTE', 'at': '2026-09-27T08:10:00Z', 'pending': true},
      ],
    });
    await store.upsert('homecare_request', 'v2', {
      'id': 'v2',
      'status': 'EN_ATTENTE',
      'version': 1,
      'patient': {'name': 'Issa TEST', 'file_number': 'VSH-2026-000011'},
      'reason': 'Injection prescrite',
      'urgency': 'URGENTE',
      'landmark': 'Face au marché',
    });
    final today = appNow();
    await db.into(db.patients).insert(PatientsCompanion.insert(
        id: 'p1', firstName: 'Aïcha', lastName: 'TEST', sex: 'F', updatedAt: today, fileNumber: const Value('VSH-2026-000009')));
    await store.upsert('treatment', 't1', {
      'id': 't1',
      'patient_id': 'p1',
      'status': 'PLANIFIE',
      'scheduled_for': today.subtract(const Duration(hours: 1)).toUtc().toIso8601String(),
      'treatment_type': {'label': 'Injection intramusculaire'},
    });
    await store.upsert('treatment', 't2', {
      'id': 't2',
      'patient_id': 'p1',
      'status': 'REALISE',
      'performed_at': today.subtract(const Duration(hours: 2)).toUtc().toIso8601String(),
      'performed_by': {'name': 'Aïcha Infirmière'},
      'treatment_type': {'label': 'Pansement simple'},
    });
    await store.upsert('appointment', 'a1', {
      'id': 'a1',
      'patient_id': 'p1',
      'status': 'CONFIRME',
      'scheduled_start': DateTime(today.year, today.month, today.day, 10).toUtc().toIso8601String(),
      'patient': {'name': 'Aïcha TEST'},
      'service': {'label': 'Médecine générale'},
      'practitioner': {'name': 'Dr Issa'},
      'reason': 'Contrôle',
    });
    await store.upsert('notification', 'n1', {'id': 'n1', 'title': 'Visite affectée', 'created_at': today.toUtc().toIso8601String()});
    return db;
  }

  Future<void> pump(WidgetTester tester, AppDatabase db, Widget screen) async {
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    final tokens = _Tokens();
    await tester.pumpWidget(ProviderScope(
      overrides: [
        databaseProvider.overrideWithValue(db),
        tokenStoreProvider.overrideWithValue(tokens),
        sessionProvider.overrideWith(_Session.new),
        connectivityProvider.overrideWith((ref) => Stream.value(false)),
        locationServiceProvider.overrideWithValue(const _NoGps()),
      ],
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light().copyWith(textTheme: AppTheme.light().textTheme.apply(fontFamily: 'Roboto')),
        home: screen,
      ),
    ));
    await tester.pumpAndSettle(const Duration(milliseconds: 100));
  }

  testWidgets('tournée', (tester) async {
    final db = await seed();
    await pump(tester, db, const TourScreen());
    await expectLater(find.byType(TourScreen), matchesGoldenFile('goldens/tour.png'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
    await db.close();
  });

  testWidgets('fiche de visite', (tester) async {
    final db = await seed();
    await pump(tester, db, const VisitScreen(id: 'v1'));
    await expectLater(find.byType(VisitScreen), matchesGoldenFile('goldens/visit.png'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
    await db.close();
  });

  testWidgets('soins', (tester) async {
    final db = await seed();
    await pump(tester, db, const TreatmentsScreen());
    await expectLater(find.byType(TreatmentsScreen), matchesGoldenFile('goldens/care.png'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
    await db.close();
  });

  testWidgets('agenda', (tester) async {
    final db = await seed();
    await pump(tester, db, const AgendaScreen());
    await expectLater(find.byType(AgendaScreen), matchesGoldenFile('goldens/agenda.png'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
    await db.close();
  });

  testWidgets('centre de synchronisation', (tester) async {
    final db = await seed();
    await pump(tester, db, const SyncCenterScreen());
    await expectLater(find.byType(SyncCenterScreen), matchesGoldenFile('goldens/sync_center.png'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
    await db.close();
  });
}

class _Tokens extends TokenStore {
  @override
  Future<String?> readRefreshToken() async => 'rt';

  @override
  Future<Map<String, dynamic>?> readUser() async => {
        'id': 'u1',
        'account_type': 'STAFF',
        'first_name': 'Aïcha',
        'last_name': 'Infirmière',
        'permissions': ['homecare.intervene', 'vitals.record', 'treatments.perform', 'appointments.manage'],
      };
}

class _Session extends SessionController {
  @override
  Future<SessionState> build() async => SessionState(SessionStatus.signedIn, user: AuthUser((await _Tokens().readUser())!));
}

class _NoGps extends LocationService {
  const _NoGps();

  @override
  Stream<GeoFix> watch(Duration interval) => const Stream.empty();

  @override
  Future<GeoResult> best({Duration maxWait = const Duration(seconds: 12), double wantedAccuracyM = 25}) async =>
      const GeoResult.failed(GeoProblem.unavailable);
}
