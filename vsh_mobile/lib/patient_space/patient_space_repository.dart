import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import '../database/app_database.dart';
import '../database/local_store.dart';
import '../network/api_client.dart';
import '../synchronization/sync_queue.dart';

/// Accès réseau de l'espace patient (interface pour les tests).
abstract interface class PatientApi {
  Future<dynamic> get(String path, {Map<String, dynamic>? query});
  Future<dynamic> post(String path, [Object? body]);
  Future<List<int>> bytes(String path);
}

class ApiClientPatientApi implements PatientApi {
  ApiClientPatientApi(this.client);

  final ApiClient client;

  @override
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => client.get(path, query: query);

  @override
  Future<dynamic> post(String path, [Object? body]) => client.post(path, body);

  @override
  Future<List<int>> bytes(String path) => client.bytes(path);
}

/// Espace patient : dossiers du compte (le sien et ceux de la famille, D-009), rendez-vous, visites
/// à domicile, résultats validés, ordonnances et factures.
///
/// Lecture en ligne par les routes `/me/patients/…` (celles du portail web), puis copie dans la base
/// locale chiffrée : tout reste consultable hors connexion. Les demandes (rendez-vous, visite,
/// annulation) partent en ligne, car le serveur vérifie les créneaux et les règles au moment même.
///
/// Pas de pull par journal pour les patients : le volume à lire est borné par leurs propres
/// dossiers, quelle que soit la taille de la base (docs/22).
class PatientSpaceRepository {
  PatientSpaceRepository(this.db, this.api, {SyncQueue? queue})
      : store = LocalStore(db),
        queue = queue ?? SyncQueue(db);

  final AppDatabase db;
  final PatientApi? api;
  final LocalStore store;
  final SyncQueue queue;

  static const patients = 'me.patient';
  static const appointments = 'me.appointment';
  static const visits = 'me.homecare';
  static const examinations = 'me.examination';
  static const prescriptions = 'me.prescription';
  static const invoices = 'me.invoice';
  static const notification = 'notification';
  static const lastRefreshKey = 'me.last_refresh_at';

  /// Nombre de notifications gardées sur le téléphone : les plus anciennes sont effacées.
  static const keptNotifications = 50;

  static const _perPatient = {
    appointments: 'appointments',
    visits: 'homecare',
    examinations: 'examinations',
    prescriptions: 'prescriptions',
    invoices: 'invoices',
  };

  PatientApi get _api {
    final value = api;
    if (value == null) throw ApiException('Serveur non configuré.');
    return value;
  }

  /// Mise à jour complète : dossiers, puis leurs éléments (requêtes en parallèle), enregistrés en
  /// une transaction. En cas d'échec réseau, les données précédentes restent affichées.
  Future<void> refresh() async {
    final records = ((await _api.get('/me/patients')) as List).map((p) => (p as Map).cast<String, dynamic>()).toList();
    final perPatient = await Future.wait(records.map((p) async {
      final id = p['id'] as String;
      final lists = await Future.wait(_perPatient.values.map((path) => _api.get('/me/patients/$id/$path')));
      return {
        for (final (i, entity) in _perPatient.keys.indexed)
          entity: (lists[i] as List).map((e) => {...(e as Map).cast<String, dynamic>(), 'patient_id': id}).toList(),
      };
    }));
    final notifications = ((await _api.get('/notifications', query: {'per_page': keptNotifications})) as List)
        .map((n) => (n as Map).cast<String, dynamic>())
        .toList();

    await db.transaction(() async {
      await _replace(patients, [for (final p in records) {...p, 'patient_id': p['id']}]);
      for (final entity in _perPatient.keys) {
        await _replace(entity, [for (final bundle in perPatient) ...bundle[entity]!]);
      }
      await _mergeNotifications(notifications);
      await db.writeMeta(lastRefreshKey, DateTime.now().toUtc().toIso8601String());
    });
  }

  /// Remplace l'ensemble des éléments d'une entité : un élément disparu côté serveur (dossier
  /// détaché du compte, rendez-vous supprimé) disparaît aussi du téléphone.
  Future<void> _replace(String entity, List<Map<String, dynamic>> items) async {
    await (db.delete(db.records)..where((t) => t.entity.equals(entity))).go();
    for (final item in items) {
      await store.upsert(entity, item['id'] as String, item);
    }
  }

  /// Notifications : état du serveur, sauf lecture locale pas encore envoyée ; seules les
  /// [keptNotifications] plus récentes sont conservées.
  Future<void> _mergeNotifications(List<Map<String, dynamic>> items) async {
    for (final n in items) {
      final id = n['id'] as String;
      if (await queue.hasOutgoing(notification, id)) continue;
      await store.upsert(notification, id, n);
    }
    final rows = await (db.select(db.records)..where((t) => t.entity.equals(notification))).get();
    final sorted = rows.map((r) => jsonDecode(r.data) as Map<String, dynamic>).toList()
      ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
    for (final old in sorted.skip(keptNotifications)) {
      if (!await queue.hasOutgoing(notification, old['id'] as String)) await store.remove(notification, old['id'] as String);
    }
  }

  Stream<List<Map<String, dynamic>>> watch(String entity, {String? patientId}) {
    final query = db.select(db.records)..where((t) => t.entity.equals(entity));
    if (patientId != null) query.where((t) => t.patientId.equals(patientId));
    return query.watch().map((rows) => rows.map((r) => jsonDecode(r.data) as Map<String, dynamic>).toList());
  }

  Future<DateTime?> lastRefresh() async => DateTime.tryParse(await db.readMeta(lastRefreshKey) ?? '');

  // ---------------------------------------------------------------- Rendez-vous

  /// Créneaux du jour ([date] au format AAAA-MM-JJ) : `[{start, local_time, available}]`.
  Future<List<Map<String, dynamic>>> slots(String serviceId, String date) async {
    final data = (await _api.get('/appointments/slots', query: {'service_id': serviceId, 'date': date}) as Map).cast<String, dynamic>();
    return ((data['slots'] as List?) ?? const []).map((s) => (s as Map).cast<String, dynamic>()).toList();
  }

  Future<void> requestAppointment({required String patientId, required String serviceId, required String start, String? reason}) async {
    await _api.post('/appointments', {'patient_id': patientId, 'service_id': serviceId, 'scheduled_start': start, 'reason': _blankToNull(reason)});
    await _refreshQuietly();
  }

  Future<void> cancelAppointment(String id, {String? reason}) async {
    await _api.post('/appointments/$id/cancel', {'reason': _blankToNull(reason)});
    await _refreshQuietly();
  }

  // ---------------------------------------------------------------- Visites à domicile

  /// Dossier complet (adresses) pour préremplir la demande de visite.
  Future<Map<String, dynamic>> record(String patientId) async => ((await _api.get('/me/patients/$patientId')) as Map).cast<String, dynamic>();

  Future<void> requestVisit(Map<String, dynamic> payload) async {
    await _api.post('/homecare', payload);
    await _refreshQuietly();
  }

  Future<void> cancelVisit(String id, {String? reason}) async {
    await _api.post('/homecare/$id/cancel', {'reason': _blankToNull(reason)});
    await _refreshQuietly();
  }

  // ---------------------------------------------------------------- Documents

  /// PDF d'une ordonnance ou d'une facture, enregistré dans le cache de l'application (effacé à
  /// la déconnexion) et ouvert par le lecteur du téléphone.
  Future<File> downloadPdf(String path, String fileName) async {
    final bytes = await _api.bytes(path);
    final dir = await documentsCache();
    final file = File('${dir.path}/$fileName');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  static Future<Directory> documentsCache() async {
    final dir = Directory('${(await getTemporaryDirectory()).path}/vsh_documents');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  /// Déconnexion : aucun document médical ne reste sur le téléphone.
  static Future<void> clearDocuments() async {
    try {
      final dir = Directory('${(await getTemporaryDirectory()).path}/vsh_documents');
      if (await dir.exists()) await dir.delete(recursive: true);
    } catch (_) {
      // Rien à effacer, ou stockage indisponible (tests).
    }
  }

  /// Après une demande acceptée : la liste affichée reflète l'état du serveur. Un échec de lecture
  /// n'annule pas la demande, déjà enregistrée.
  Future<void> _refreshQuietly() async {
    try {
      await refresh();
    } catch (_) {}
  }

  static String? _blankToNull(String? value) => value == null || value.trim().isEmpty ? null : value.trim();
}
