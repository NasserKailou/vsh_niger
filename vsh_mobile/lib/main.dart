import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'app.dart';
import 'authentication/token_store.dart';
import 'database/database_opener.dart';
import 'synchronization/sync_providers.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr');

  // Base locale chiffrée, clé propre à l'installation gardée dans le stockage sécurisé.
  final tokens = TokenStore();
  final database = await openEncryptedDatabase(await tokens.databaseKey());

  runApp(ProviderScope(
    overrides: [
      tokenStoreProvider.overrideWithValue(tokens),
      databaseProvider.overrideWithValue(database),
    ],
    child: const VshApp(),
  ));
}
