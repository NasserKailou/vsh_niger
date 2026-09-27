import 'dart:async';

import 'package:dio/dio.dart';

import '../authentication/token_store.dart';

/// Erreur d'API présentée à l'utilisateur : message du serveur (déjà rédigé pour lui), code stable
/// pour le programme, erreurs par champ. Jamais de détail technique.
class ApiException implements Exception {
  ApiException(this.message, {this.code, this.status, this.errors = const {}});

  final String message;
  final String? code;
  final int? status;
  final Map<String, List<String>> errors;

  bool get isNetwork => status == null;
  bool get deviceRevoked => code == 'DEVICE_REVOKED';

  /// Session impossible à renouveler : l'utilisateur doit se reconnecter.
  bool get sessionLost => status == 401 && code != 'INVALID_CREDENTIALS';

  @override
  String toString() => message;

  static ApiException from(DioException e) {
    final response = e.response;
    if (response == null) {
      return ApiException(
        e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout
            ? 'Le serveur ne répond pas. Vérifiez la connexion.'
            : 'Pas de connexion au serveur.',
      );
    }
    final body = response.data;
    if (body is Map) {
      final errors = <String, List<String>>{};
      final raw = body['errors'];
      if (raw is Map) {
        raw.forEach((key, value) => errors['$key'] = value is List ? value.map((v) => '$v').toList() : ['$value']);
      }
      return ApiException((body['message'] ?? 'Erreur du serveur.') as String,
          code: body['code'] as String?, status: response.statusCode, errors: errors);
    }
    return ApiException(
      (response.statusCode ?? 500) >= 500 ? 'Le serveur a rencontré un problème. Réessayez plus tard.' : 'Requête refusée.',
      status: response.statusCode,
    );
  }
}

/// Client HTTP de l'API : jeton dans l'en-tête, renouvellement automatique (une seule fois pour
/// toutes les requêtes en parallèle), enveloppe `{success, message, data}` déballée.
class ApiClient {
  ApiClient({required String baseUrl, required this.tokens, this.onSessionLost, Dio? dio})
      : _dio = dio ?? Dio(),
        _refreshDio = Dio() {
    for (final client in [_dio, _refreshDio]) {
      client.options
        ..baseUrl = baseUrl.endsWith('/') ? baseUrl : '$baseUrl/'
        ..connectTimeout = const Duration(seconds: 15)
        ..receiveTimeout = const Duration(seconds: 45)
        ..sendTimeout = const Duration(seconds: 45)
        ..headers = {'Accept': 'application/json'};
    }
    _dio.interceptors.add(InterceptorsWrapper(onRequest: _authorize, onError: _refreshOnExpiry));
  }

  final Dio _dio;
  final Dio _refreshDio;
  final TokenStore tokens;

  /// Session perdue (renouvellement refusé) ou appareil révoqué.
  final Future<void> Function({required bool deviceRevoked})? onSessionLost;

  Future<bool>? _refreshing;

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => _send(() => _dio.get(_path(path), queryParameters: query));

  Future<dynamic> post(String path, [Object? body]) => _send(() => _dio.post(_path(path), data: body ?? const {}));

  Future<dynamic> delete(String path) => _send(() => _dio.delete(_path(path)));

  Future<dynamic> put(String path, [Object? body]) => _send(() => _dio.put(_path(path), data: body ?? const {}));

  /// Fichier binaire (PDF d'une ordonnance ou d'une facture), avec la même gestion de session.
  Future<List<int>> bytes(String path) async {
    final data = await _send(() => _dio.get(_path(path), options: Options(responseType: ResponseType.bytes)));
    return (data as List).cast<int>();
  }

  /// Appel sans jeton (connexion).
  Future<dynamic> postPublic(String path, Object body) => _send(() => _refreshDio.post(_path(path), data: body));

  static String _path(String path) => path.startsWith('/') ? path.substring(1) : path;

  Future<dynamic> _send(Future<Response<dynamic>> Function() request) async {
    try {
      final response = await request();
      final body = response.data;
      return body is Map ? body['data'] : body;
    } on DioException catch (e) {
      throw e.error is ApiException ? e.error! : ApiException.from(e);
    }
  }

  Future<void> _authorize(RequestOptions options, RequestInterceptorHandler handler) async {
    if (tokens.accessToken == null && options.extra['retried'] != true) {
      try {
        await refresh();
      } on ApiException catch (e) {
        return handler.reject(DioException(requestOptions: options, error: e));
      }
    }
    final token = tokens.accessToken;
    if (token != null) options.headers['Authorization'] = 'Bearer $token';
    handler.next(options);
  }

  Future<void> _refreshOnExpiry(DioException error, ErrorInterceptorHandler handler) async {
    final response = error.response;
    final code = response?.data is Map ? (response!.data as Map)['code'] : null;
    if (code == 'DEVICE_REVOKED') {
      await onSessionLost?.call(deviceRevoked: true);
      return handler.next(error);
    }
    if (response?.statusCode != 401 || error.requestOptions.extra['retried'] == true) {
      return handler.next(error);
    }
    // Jeton expiré : renouvellement, puis une seule nouvelle tentative. La file hors ligne n'est
    // jamais vidée à cause d'une session expirée.
    try {
      if (!await refresh()) return handler.next(error);
    } on ApiException catch (e) {
      return handler.next(DioException(requestOptions: error.requestOptions, error: e));
    }
    final options = error.requestOptions
      ..extra['retried'] = true
      ..headers['Authorization'] = 'Bearer ${tokens.accessToken}';
    try {
      handler.resolve(await _dio.fetch(options));
    } on DioException catch (e) {
      handler.next(e);
    }
  }

  /// Renouvelle le jeton d'accès. Plusieurs requêtes en parallèle partagent le même renouvellement
  /// (le jeton de renouvellement change à chaque usage : un double envoi fermerait la session).
  Future<bool> refresh() => _refreshing ??= _doRefresh().whenComplete(() => _refreshing = null);

  Future<bool> _doRefresh() async {
    final refreshToken = await tokens.readRefreshToken();
    if (refreshToken == null) return false;
    try {
      final response = await _refreshDio.post('auth/refresh', data: {'refresh_token': refreshToken});
      final issued = (((response.data as Map)['data'] as Map)['tokens'] as Map).cast<String, dynamic>();
      await tokens.saveTokens(issued);
      return true;
    } on DioException catch (e) {
      final failure = ApiException.from(e);
      if (failure.isNetwork || (failure.status ?? 500) >= 500) {
        // Hors ligne : la session reste valable, on réessaiera.
        throw failure;
      }
      await tokens.clearSession();
      await onSessionLost?.call(deviceRevoked: failure.deviceRevoked);
      return false;
    }
  }
}
