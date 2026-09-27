import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart';

import 'app_database.dart';

/// Ouvre la base locale **chiffrée** (SQLite3MultipleCiphers, AES-256 par défaut) avec la clé
/// gardée dans le stockage sécurisé. Refuse de fonctionner sans chiffrement.
Future<AppDatabase> openEncryptedDatabase(String hexKey) async {
  final dir = await getApplicationSupportDirectory();
  final file = File(p.join(dir.path, 'vsh.sqlite'));
  final db = AppDatabase(encryptedExecutor(file, hexKey));
  try {
    await db.customSelect('SELECT COUNT(*) FROM sqlite_master').get();
    return db;
  } catch (_) {
    // Clé perdue (réinstallation, restauration du stockage sécurisé) : le fichier est illisible.
    // Les données confirmées sont sur le serveur et reviendront à la prochaine synchronisation.
    await db.close();
    for (final suffix in ['', '-wal', '-shm', '-journal']) {
      final f = File('${file.path}$suffix');
      if (await f.exists()) await f.delete();
    }
    return AppDatabase(encryptedExecutor(file, hexKey));
  }
}

QueryExecutor encryptedExecutor(File file, String hexKey) => NativeDatabase.createInBackground(
      file,
      setup: (Database raw) => applyEncryption(raw, hexKey),
    );

/// À appeler avant toute autre requête sur la connexion.
void applyEncryption(Database raw, String hexKey) {
  final cipher = raw.select('PRAGMA cipher');
  if (cipher.isEmpty) {
    throw StateError('SQLite sans chiffrement : vérifier `hooks.user_defines.sqlite3.source: sqlite3mc`.');
  }
  if (!RegExp(r'^[0-9a-f]{64}$').hasMatch(hexKey)) {
    throw ArgumentError('Clé de base invalide.');
  }
  raw.execute("PRAGMA hexkey = '$hexKey'");
  raw.execute('PRAGMA journal_mode = WAL');
  raw.execute('PRAGMA foreign_keys = ON');
}
