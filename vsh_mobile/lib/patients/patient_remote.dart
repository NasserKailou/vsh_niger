import '../network/api_client.dart';

/// Recherche en ligne de dossiers hors du périmètre du téléphone, et épinglage : le dossier épinglé
/// (et toutes ses données autorisées) arrive à la synchronisation suivante, pour le travail hors ligne.
class PatientRemote {
  PatientRemote(this.api);

  final ApiClient api;

  /// Au moins 2 caractères ; la recherche passe par le serveur, avec ses droits et son audit.
  Future<List<Map<String, dynamic>>> search(String term) async {
    final q = term.trim();
    if (q.length < 2) return const [];
    // POST : le nom recherché ne passe pas dans l'URL (ni dans les journaux du serveur web).
    final data = await api.post('/patients/search?per_page=20', {'q': q});
    return (data as List).map((e) => (e as Map).cast<String, dynamic>()).toList();
  }

  Future<void> pin(String patientId) => api.post('/sync/patients/$patientId/pin');

  Future<void> unpin(String patientId) => api.delete('/sync/patients/$patientId/pin');

  /// Dossiers épinglés par l'utilisateur.
  Future<Set<String>> pinned() async {
    final data = await api.get('/sync/patients');
    return (data as List).map((e) => '${(e as Map)['id']}').toSet();
  }
}
