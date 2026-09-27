import 'dart:convert';

import '../database/app_database.dart';
import '../network/api_client.dart';

/// Référentiels et paramètres pour le travail hors ligne (`GET /reference/bundle`), gardés dans
/// `sync_meta`. Mise à jour incrémentale (`since`) à chaque synchronisation réussie.
class ReferenceRepository {
  ReferenceRepository(this.db, [this.api]);

  final AppDatabase db;
  final ApiClient? api;

  static const _generatedKey = 'ref.generated_at';
  static const lists = ['services', 'medical_acts', 'treatment_types', 'examination_types', 'medications', 'tariffs', 'prescription_templates'];

  Future<void> refresh() async {
    if (api == null) return;
    final since = await db.readMeta(_generatedKey);
    final data = (await api!.get('/reference/bundle', query: {'since': ?since}) as Map).cast<String, dynamic>();
    await apply(data);
  }

  /// Fusion par identifiant ; un lot complet (`full`) remplace les listes.
  Future<void> apply(Map<String, dynamic> bundle) => db.transaction(() async {
        final full = bundle['full'] == true;
        for (final key in lists) {
          final incoming = bundle[key];
          if (incoming is! List) continue;
          final merged = <String, Map<String, dynamic>>{};
          if (!full) {
            for (final item in await list(key)) {
              merged['${item['id']}'] = item;
            }
          }
          for (final item in incoming) {
            final map = (item as Map).cast<String, dynamic>();
            merged['${map['id']}'] = map;
          }
          await db.writeMeta('ref.$key', jsonEncode(merged.values.toList()));
        }
        if (bundle['settings'] is Map) {
          await db.writeMeta('ref.settings', jsonEncode(bundle['settings']));
        }
        if (bundle['generated_at'] is String) {
          await db.writeMeta(_generatedKey, bundle['generated_at'] as String);
        }
      });

  Future<List<Map<String, dynamic>>> list(String key) async {
    final raw = await db.readMeta('ref.$key');
    if (raw == null) return const [];
    return (jsonDecode(raw) as List).map((e) => (e as Map).cast<String, dynamic>()).toList();
  }

  /// Éléments actifs d'un référentiel, triés par libellé.
  Future<List<Map<String, dynamic>>> active(String key) async =>
      (await list(key)).where((e) => e['active'] != false).toList()..sort((a, b) => '${a['label']}'.compareTo('${b['label']}'));

  Future<Map<String, dynamic>> settings() async {
    final raw = await db.readMeta('ref.settings');
    return raw == null ? const {} : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  /// Paramètre numérique de la clinique, avec la valeur par défaut documentée côté serveur.
  Future<num> number(String key, num fallback) async {
    final value = (await settings())[key];
    return value is num ? value : fallback;
  }
}
