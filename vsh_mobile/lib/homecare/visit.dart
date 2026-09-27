/// Visite à domicile telle que reçue du serveur (`HomecareService::present`), lue depuis `records`.
class Visit {
  Visit(this.data);

  final Map<String, dynamic> data;

  String get id => data['id'] as String;
  String get status => data['status'] as String? ?? '';
  int get version => (data['version'] as num?)?.toInt() ?? 0;

  /// `false` : visite prise par une autre équipe (statut seul, à retirer de la file).
  bool get available => data['available'] != false;

  String? get patientId => data['patient_id'] as String?;
  Map<String, dynamic>? get patient => (data['patient'] as Map?)?.cast<String, dynamic>();
  String get patientName => patient?['name'] as String? ?? 'Patient';
  String? get fileNumber => patient?['file_number'] as String?;
  String get reason => data['reason'] as String? ?? '';
  bool get urgent => data['urgency'] == 'URGENTE';
  String? get addressText => data['address_text'] as String?;
  String? get landmark => data['landmark'] as String?;
  String? get contactPhone => data['contact_phone'] as String?;
  String? get teamLabel => (data['team'] as Map?)?['label'] as String?;
  String? get consultationId => data['consultation_id'] as String?;
  String? get failureReason => data['failure_reason'] as String?;

  double? get latitude => (data['location'] as Map?)?['latitude'] as double?;
  double? get longitude => (data['location'] as Map?)?['longitude'] as double?;
  double? get accuracyM => ((data['location'] as Map?)?['accuracy_m'] as num?)?.toDouble();
  bool get hasLocation => latitude != null && longitude != null;

  DateTime? at(String key) => DateTime.tryParse(data[key] as String? ?? '');

  List<Map<String, dynamic>> get history => ((data['history'] as List?) ?? const []).map((e) => (e as Map).cast<String, dynamic>()).toList();

  bool get isOpen => VisitFlow.open.contains(status);
  bool get isActive => VisitFlow.active.contains(status);
}

/// Machine à états du serveur (`HomecareService::FIELD_ACTIONS`), reprise pour proposer les bons
/// boutons hors ligne. Le serveur reste seul juge : une action devenue impossible est refusée.
abstract final class VisitFlow {
  static const open = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];
  static const active = ['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

  /// Positions collectées seulement pendant le déplacement et la visite (vie privée du personnel).
  static const tracked = ['EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

  static const transitions = <String, (List<String>, String)>{
    'accept': (['EN_ATTENTE'], 'PRISE_EN_CHARGE'),
    'release': (['PRISE_EN_CHARGE'], 'EN_ATTENTE'),
    'depart': (['PRISE_EN_CHARGE'], 'EN_ROUTE'),
    'arrive': (['EN_ROUTE'], 'SUR_PLACE'),
    'start': (['SUR_PLACE'], 'EN_COURS'),
    'complete': (['EN_COURS'], 'TERMINEE'),
    'fail': (['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'], 'ECHEC'),
  };

  /// Horodatage renseigné localement par chaque action (même nom que dans la fiche serveur).
  static const stamps = {
    'accept': 'accepted_at',
    'depart': 'departed_at',
    'arrive': 'arrived_at',
    'start': 'started_at',
    'complete': 'completed_at',
    'fail': 'closed_at',
  };

  /// Actions qui exigent un motif.
  static const needReason = {'release', 'fail'};

  static bool allowed(String action, String status) => transitions[action]?.$1.contains(status) ?? false;

  static const labels = {
    'NOUVELLE': 'Nouvelle',
    'EN_ATTENTE': 'En attente',
    'PRISE_EN_CHARGE': 'Prise en charge',
    'EN_ROUTE': 'En route',
    'SUR_PLACE': 'Sur place',
    'EN_COURS': 'Soins en cours',
    'TERMINEE': 'Terminée',
    'FACTUREE': 'Facturée',
    'ECHEC': 'Échec',
    'ANNULEE': 'Annulée',
  };
}
