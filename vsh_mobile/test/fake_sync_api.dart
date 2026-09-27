import 'package:vsh_mobile/synchronization/sync_api.dart';

/// Serveur de synchronisation simulé, fidèle au contrat de docs/07 : idempotence par `op_id`,
/// fusion champ par champ avec `base`, `depends_on`, journal des changements avec curseur.
class FakeSyncServer implements SyncApi {
  final Map<String, Map<String, Map<String, dynamic>>> entities = {};
  final Map<String, Map<String, dynamic>> _results = {};
  final List<Map<String, dynamic>> journal = [];
  int _seq = 0;
  int _fileNumber = 0;

  int pushCalls = 0;
  int pullCalls = 0;
  final List<String> receivedOpIds = [];

  /// Pannes à simuler au prochain appel.
  Object? failNextPush;

  /// Coupure après traitement : le serveur a appliqué le lot, mais la réponse est perdue.
  bool loseNextPushResponse = false;
  Object? failNextPull;

  /// Refus de validation pour une entité/un champ (ex. téléphone invalide).
  bool Function(Map<String, dynamic> op)? rejectWhen;

  /// Erreur technique temporaire pour une opération.
  bool Function(Map<String, dynamic> op)? errorWhen;

  Map<String, dynamic>? get(String entity, String id) => entities[entity]?[id];

  /// Modification faite par un autre utilisateur, directement sur le serveur.
  void serverEdit(String entity, String id, Map<String, dynamic> fields) {
    final row = entities[entity]![id]!;
    row.addAll(fields);
    row['version'] = (row['version'] as int) + 1;
    _journal(entity, id, 'UPSERT');
  }

  void serverCreate(String entity, String id, Map<String, dynamic> data) {
    entities.putIfAbsent(entity, () => {})[id] = {...data, 'id': id, 'version': 1};
    _journal(entity, id, 'UPSERT');
  }

  void serverDelete(String entity, String id) {
    entities[entity]?.remove(id);
    _journal(entity, id, 'DELETE');
  }

  void _journal(String entity, String id, String operation) {
    // Un élément modifié plusieurs fois n'est transmis qu'une fois, dans son état actuel.
    journal.removeWhere((c) => c['entity'] == entity && c['id'] == id);
    journal.add({'seq': ++_seq, 'entity': entity, 'id': id, 'operation': operation});
  }

  @override
  Future<List<Map<String, dynamic>>> push(List<Map<String, dynamic>> operations) async {
    pushCalls++;
    if (failNextPush != null) {
      final error = failNextPush!;
      failNextPush = null;
      throw error;
    }
    final results = operations.map(_process).toList();
    if (loseNextPushResponse) {
      loseNextPushResponse = false;
      throw SyncNetworkException();
    }
    return results;
  }

  Map<String, dynamic> _process(Map<String, dynamic> op) {
    final opId = op['op_id'] as String;
    receivedOpIds.add(opId);
    final stored = _results[opId];
    if (stored != null) return {...stored, 'duplicate': true};

    final base = {'op_id': opId, 'entity': op['entity'], 'entity_id': op['entity_id'], 'duplicate': false};
    final dependsOn = op['depends_on'] as String?;
    if (dependsOn != null && _results[dependsOn]?['status'] != 'APPLIED') {
      return {...base, 'status': 'DEFERRED', 'error': {'code': 'DEPENDENCY_PENDING', 'message': 'Dépendance en attente.'}};
    }
    if (errorWhen?.call(op) == true) {
      return {...base, 'status': 'ERROR', 'error': {'code': 'SERVER_ERROR', 'message': 'Erreur temporaire.'}};
    }
    if (rejectWhen?.call(op) == true) {
      return _store(opId, {...base, 'status': 'REJECTED', 'error': {'code': 'VALIDATION_ERROR', 'message': 'Données invalides.', 'errors': {}}});
    }

    final entity = op['entity'] as String;
    final id = op['entity_id'] as String;
    final payload = (op['payload'] as Map).cast<String, dynamic>();
    final table = entities.putIfAbsent(entity, () => {});
    switch (op['operation']) {
      case 'CREATE' when (entity == 'vital_sign' || entity == 'consultation_note') && get('consultation', payload['consultation_id'] as String) == null:
        return _store(opId, {...base, 'status': 'REJECTED', 'error': {'code': 'NOT_FOUND', 'message': 'Consultation introuvable.'}});
      case 'ACTION':
        return _store(opId, _action(base, entity, id, op['action'] as String, payload));
      case 'CREATE':
        table.putIfAbsent(id, () => {
              ...payload,
              'id': id,
              'version': 1,
              if (entity == 'patient') 'file_number': 'VSH-2026-${(++_fileNumber).toString().padLeft(6, '0')}',
              if (entity == 'patient') 'status': 'ACTIVE',
            });
        _journal(entity, id, 'UPSERT');
        return _store(opId, {...base, 'status': 'APPLIED', 'data': {...table[id]!}});
      case 'UPDATE':
        final row = table[id]!;
        final baseValues = (op['base'] as Map? ?? {}).cast<String, dynamic>();
        final sameVersion = op['base_version'] == row['version'];
        final conflicts = <String, dynamic>{};
        var changed = false;
        payload.forEach((field, value) {
          if (sameVersion || row[field] == baseValues[field]) {
            if (row[field] != value) {
              row[field] = value;
              changed = true;
            }
          } else if (row[field] != value) {
            conflicts[field] = {'server': row[field], 'client': value};
          }
        });
        if (changed) {
          row['version'] = (row['version'] as int) + 1;
          _journal(entity, id, 'UPSERT');
        }
        return _store(opId, {
          ...base,
          'status': conflicts.isEmpty ? 'APPLIED' : 'CONFLICT',
          'data': {...row},
          'conflict': conflicts.isEmpty ? null : {'id': 'c-$opId', 'fields': conflicts.keys.toList(), 'details': conflicts},
        });
      case 'DELETE':
        table.remove(id);
        _journal(entity, id, 'DELETE');
        return _store(opId, {...base, 'status': 'APPLIED', 'data': null});
    }
    return _store(opId, {...base, 'status': 'REJECTED', 'error': {'code': 'UNSUPPORTED_OPERATION', 'message': 'Opération non prise en charge.'}});
  }

  static const _flow = {
    'accept': (['EN_ATTENTE'], 'PRISE_EN_CHARGE'),
    'release': (['PRISE_EN_CHARGE'], 'EN_ATTENTE'),
    'depart': (['PRISE_EN_CHARGE'], 'EN_ROUTE'),
    'arrive': (['EN_ROUTE'], 'SUR_PLACE'),
    'start': (['SUR_PLACE'], 'EN_COURS'),
    'complete': (['EN_COURS'], 'TERMINEE'),
    'fail': (['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'], 'ECHEC'),
    'cancel': (['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE'], 'ANNULEE'),
  };

  /// Points de trajet reçus, par visite.
  final Map<String, List<Map<String, dynamic>>> tracks = {};

  Map<String, dynamic> _action(Map<String, dynamic> base, String entity, String id, String action, Map<String, dynamic> payload) {
    final row = entities[entity]?[id];
    if (row == null) {
      return {...base, 'status': 'REJECTED', 'error': {'code': 'NOT_FOUND', 'message': 'Introuvable.'}};
    }
    if (action == 'track') {
      if (!['EN_ROUTE', 'SUR_PLACE', 'EN_COURS'].contains(row['status'])) {
        return {...base, 'status': 'REJECTED', 'error': {'code': 'INVALID_TRANSITION', 'message': 'Visite non en cours.'}};
      }
      tracks.putIfAbsent(id, () => []).addAll((payload['points'] as List).cast<Map<String, dynamic>>());
      return {...base, 'status': 'APPLIED', 'data': {...row}};
    }
    final other = switch ((entity, action)) {
      ('treatment', 'perform') => (['PLANIFIE'], {'status': 'REALISE', 'performed_at': payload['performed_at'], 'observations': payload['observations']}),
      ('treatment', 'cancel') => (['PLANIFIE'], {'status': 'ANNULE', 'cancel_reason': payload['reason']}),
      ('appointment', 'check_in') => (['CONFIRME', 'DEPLACE'], {'checked_in_at': '2026-09-27T08:30:00Z'}),
      ('appointment', 'no_show') => (['CONFIRME', 'DEPLACE'], {'status': 'ABSENT'}),
      _ => null,
    };
    if (other != null) {
      if (!other.$1.contains(row['status'])) {
        return {...base, 'status': 'REJECTED', 'error': {'code': 'INVALID_TRANSITION', 'message': 'Action impossible dans l’état actuel.'}};
      }
      row.addAll(other.$2);
      row['version'] = (row['version'] as int) + 1;
      _journal(entity, id, 'UPSERT');
      return {...base, 'status': 'APPLIED', 'data': {...row}};
    }
    final rule = _flow[action];
    if (rule == null || !rule.$1.contains(row['status'])) {
      return {...base, 'status': 'REJECTED', 'error': {'code': 'INVALID_TRANSITION', 'message': 'Action impossible dans l’état actuel de la visite.'}};
    }
    row['status'] = rule.$2;
    row['version'] = (row['version'] as int) + 1;
    row['last_action_at'] = payload['at'];
    if (action == 'start') {
      final consultationId = payload['consultation_id'] as String;
      entities.putIfAbsent('consultation', () => {})[consultationId] = {
        'id': consultationId,
        'patient_id': row['patient_id'],
        'type': 'DOMICILE',
        'status': 'OUVERTE',
        'version': 1,
      };
      row['consultation_id'] = consultationId;
      _journal('consultation', consultationId, 'UPSERT');
    }
    _journal(entity, id, 'UPSERT');
    return {...base, 'status': 'APPLIED', 'data': {...row}};
  }

  Map<String, dynamic> _store(String opId, Map<String, dynamic> result) => _results[opId] = result;

  @override
  Future<PullPage> pull(int cursor, {int limit = 200}) async {
    pullCalls++;
    if (failNextPull != null) {
      final error = failNextPull!;
      failNextPull = null;
      throw error;
    }
    final pending = journal.where((c) => (c['seq'] as int) > cursor).toList()..sort((a, b) => (a['seq'] as int).compareTo(b['seq'] as int));
    final page = pending.take(limit).toList();
    return PullPage(
      changes: page.map((c) {
        final data = c['operation'] == 'DELETE' ? null : get(c['entity'] as String, c['id'] as String);
        return {...c, 'operation': data == null ? 'DELETE' : 'UPSERT', 'data': data == null ? null : {...data}};
      }).toList(),
      nextCursor: page.isEmpty ? cursor : page.last['seq'] as int,
      hasMore: pending.length > page.length,
    );
  }
}
