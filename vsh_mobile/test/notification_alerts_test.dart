import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vsh_mobile/database/app_database.dart';
import 'package:vsh_mobile/notifications/notification_alerts.dart';
import 'package:vsh_mobile/notifications/notifications_screen.dart';

Map<String, dynamic> _notification(String id, String createdAt, {String? readAt, String? entityType, String? entityId}) => {
      'id': id,
      'title': 'Titre $id',
      'body': null,
      'created_at': createdAt,
      'read_at': readAt,
      'entity_type': entityType,
      'entity_id': entityId,
    };

void main() {
  late AppDatabase db;
  late NotificationAlertTracker tracker;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    tracker = NotificationAlertTracker(db);
  });

  tearDown(() => db.close());

  test('la première synchronisation ne sonne pas pour tout l\'historique', () async {
    final history = [_notification('a', '2026-09-20T08:00:00Z'), _notification('b', '2026-09-26T08:00:00Z')];
    expect(await tracker.fresh(history), isEmpty);
    expect(await db.readMeta(NotificationAlertTracker.markerKey), '2026-09-26T08:00:00Z');
  });

  test('seules les nouvelles notifications non lues sont signalées, dans l\'ordre d\'arrivée', () async {
    final history = [_notification('a', '2026-09-26T08:00:00Z')];
    await tracker.fresh(history);

    final next = [
      ...history,
      _notification('c', '2026-09-27T10:05:00Z'),
      _notification('b', '2026-09-27T10:00:00Z'),
      _notification('lue', '2026-09-27T10:01:00Z', readAt: '2026-09-27T10:02:00Z'),
    ];
    expect((await tracker.fresh(next)).map((n) => n['id']), ['b', 'c']);
    // Même liste relue (autre émission de la base) : rien de plus.
    expect(await tracker.fresh(next), isEmpty);
  });

  test('une liste vide ne change rien', () async {
    expect(await tracker.fresh(const []), isEmpty);
    expect(await db.readMeta(NotificationAlertTracker.markerKey), isNull);
  });

  test('le lien ouvre l\'écran de l\'élément concerné', () {
    expect(notificationRoute(_notification('v', 'x', entityType: 'homecare_request', entityId: 'v-1')), '/visits/v-1');
    expect(notificationRoute({'entity_type': 'appointment', 'entity_id': 'r-1'}), '/agenda');
    expect(notificationRoute({'type': 'INVOICE_ISSUED'}), isNull);
  });
}
