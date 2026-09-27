import 'package:drift/drift.dart' show driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:vsh_mobile/authentication/auth_repository.dart';
import 'package:vsh_mobile/authentication/session_controller.dart';
import 'package:vsh_mobile/authentication/token_store.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/database/local_store.dart';
import 'package:vsh_mobile/routing/app_router.dart';
import 'package:vsh_mobile/synchronization/sync_providers.dart';
import 'package:vsh_mobile/theme/app_theme.dart';

/// Parcours d'un patient dans l'application complète (vrai routeur) : toucher une notification
/// ouvre l'écran concerné de son espace, sans erreur.
void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  setUpAll(() => initializeDateFormatting('fr'));

  Future<AppDatabase> seed() async {
    final db = AppDatabase(NativeDatabase.memory());
    final store = LocalStore(db);
    await store.upsert('me.patient', 'p1', {'id': 'p1', 'patient_id': 'p1', 'first_name': 'Aïcha', 'last_name': 'MOUSSA', 'status': 'ACTIVE'});
    await store.upsert('me.appointment', 'a1', {
      'id': 'a1',
      'patient_id': 'p1',
      'status': 'CONFIRME',
      'scheduled_start': DateTime.now().add(const Duration(days: 2)).toUtc().toIso8601String(),
      'service': {'label': 'Médecine générale'},
    });
    await store.upsert('me.examination', 'e1', {
      'id': 'e1',
      'patient_id': 'p1',
      'validated_at': '2026-09-20T10:00:00Z',
      'examination_type': {'label': 'Glycémie à jeun'},
      'results': [
        {'label': 'Glucose', 'value_numeric': 1.12, 'unit': 'g/L', 'reference_text': '0,70 – 1,10', 'is_abnormal': true},
      ],
    });
    for (final (id, type, entity, title) in [
      ('n1', 'APPOINTMENT_STATUS', 'appointment', 'Rendez-vous confirmé'),
      ('n2', 'EXAM_RESULT', 'examination', 'Résultat disponible'),
      ('n3', 'HOMECARE_STATUS', 'homecare_request', 'Visite à domicile'),
      ('n4', 'INVOICE_ISSUED', 'invoice', 'Nouvelle facture'),
    ]) {
      await store.upsert('notification', id, {
        'id': id,
        'type': type,
        'title': title,
        'body': 'Texte de la notification',
        'entity_type': entity,
        'entity_id': 'x-$id',
        'read_at': null,
        'created_at': '2026-09-27T0${id.substring(1)}:00:00Z',
      });
    }
    return db;
  }

  Future<GoRouter> pumpApp(WidgetTester tester, AppDatabase db) async {
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    final container = ProviderContainer(overrides: [
      databaseProvider.overrideWithValue(db),
      tokenStoreProvider.overrideWithValue(_Tokens()),
      sessionProvider.overrideWith(_PatientSession.new),
      connectivityProvider.overrideWith((ref) => Stream.value(false)),
    ]);
    addTearDown(container.dispose);
    final router = container.read(routerProvider);
    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: MaterialApp.router(theme: AppTheme.light(), routerConfig: router),
    ));
    await _settle(tester);
    return router;
  }

  for (final (title, expected, screenText) in [
    ('Rendez-vous confirmé', '/p/appointments', 'Médecine générale'),
    ('Résultat disponible', '/p/record', 'Glycémie à jeun'),
    ('Visite à domicile', '/p/homecare', 'Aucune visite'),
    ('Nouvelle facture', '/p/record', 'Aucune facture'),
  ]) {
    testWidgets('toucher « $title » ouvre $expected', (tester) async {
      final db = await seed();
      final router = await pumpApp(tester, db);
      expect(router.routerDelegate.currentConfiguration.uri.path, '/p/home');

      router.push('/notifications');
      await _settle(tester);
      await tester.tap(find.text(title));
      await _settle(tester);

      expect(tester.takeException(), isNull);
      expect(router.routerDelegate.currentConfiguration.uri.path, expected);
      expect(find.text(screenText), findsWidgets);
      // Envoi groupé de la lecture (3 s) : laissé aller à son terme.
      await tester.pump(const Duration(seconds: 5));
    });
  }
}

class _Tokens extends TokenStore {
  @override
  Future<String?> readRefreshToken() async => 'rt';

  @override
  Future<Map<String, dynamic>?> readUser() async => {
        'id': 'u-patient',
        'account_type': 'PATIENT',
        'first_name': 'Aïcha',
        'last_name': 'MOUSSA',
        'permissions': ['self.record.read', 'self.invoices.read'],
      };
}

class _PatientSession extends SessionController {
  @override
  Future<SessionState> build() async => SessionState(SessionStatus.signedIn, user: AuthUser((await _Tokens().readUser())!));
}

/// Quelques images, sans attendre la fin des animations sans fin (indicateurs de chargement).
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}
