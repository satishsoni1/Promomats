import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Server address baked in at build time:
///   flutter build apk --dart-define=API_BASE_URL=https://vodo.himalayawellness.com
/// It can also be changed on the sign-in screen (kept on the device).
const String defaultBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000');

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode});
  final String message;
  final int? statusCode;

  bool get isUnauthenticated => statusCode == 401;

  @override
  String toString() => message;
}

/// Thin client for the VODO mobile API (routes/api.php on the server).
class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  final _storage = const FlutterSecureStorage();
  String _baseUrl = defaultBaseUrl;
  String? _token;

  String get baseUrl => _baseUrl;
  bool get isSignedIn => _token != null;

  Future<void> load() async {
    _baseUrl = await _storage.read(key: 'base_url') ?? defaultBaseUrl;
    _token = await _storage.read(key: 'token');
  }

  Future<void> setBaseUrl(String url) async {
    _baseUrl = url.trim().replaceAll(RegExp(r'/+$'), '');
    await _storage.write(key: 'base_url', value: _baseUrl);
  }

  Uri _uri(String path) => Uri.parse('$_baseUrl/api/v1$path');

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<Map<String, dynamic>> _send(Future<http.Response> Function() call) async {
    http.Response res;
    try {
      res = await call().timeout(const Duration(seconds: 30));
    } catch (_) {
      throw ApiException('Could not reach the server. Check your connection and the server address.');
    }

    final body = res.body.isEmpty ? <String, dynamic>{} : (jsonDecode(res.body) as Map<String, dynamic>);
    if (res.statusCode >= 200 && res.statusCode < 300) return body;

    if (res.statusCode == 401) {
      await _clearToken();
      throw ApiException('Your session has ended. Please sign in again.', statusCode: 401);
    }

    // Laravel validation errors: {"message": "...", "errors": {"field": ["..."]}}
    final errors = body['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      throw ApiException(first is List && first.isNotEmpty ? '${first.first}' : '${body['message']}', statusCode: res.statusCode);
    }
    throw ApiException('${body['message'] ?? 'Something went wrong (${res.statusCode}).'}', statusCode: res.statusCode);
  }

  Future<Map<String, dynamic>> login(String email, String password) async {
    final body = await _send(() => http.post(
          _uri('/login'),
          headers: _headers,
          body: jsonEncode({'email': email, 'password': password, 'device_name': 'VODO mobile'}),
        ));
    _token = body['token'] as String;
    await _storage.write(key: 'token', value: _token);
    return body['user'] as Map<String, dynamic>;
  }

  Future<void> logout() async {
    try {
      await _send(() => http.post(_uri('/logout'), headers: _headers));
    } catch (_) {
      // Signing out locally is what matters.
    }
    await _clearToken();
  }

  Future<void> _clearToken() async {
    _token = null;
    await _storage.delete(key: 'token');
  }

  Future<Map<String, dynamic>> me() => _send(() => http.get(_uri('/me'), headers: _headers));

  Future<Map<String, dynamic>> tasks() => _send(() => http.get(_uri('/tasks'), headers: _headers));

  Future<Map<String, dynamic>> document(int id) => _send(() => http.get(_uri('/documents/$id'), headers: _headers));

  Future<Map<String, dynamic>> decide(int documentId, String decision, String comments) => _send(() => http.post(
        _uri('/documents/$documentId/decision'),
        headers: _headers,
        body: jsonEncode({'decision': decision, 'comments': comments}),
      ));

  Future<Map<String, dynamic>> comment(int documentId, String body, {int? parentId}) => _send(() => http.post(
        _uri('/documents/$documentId/comments'),
        headers: _headers,
        body: jsonEncode({'body': body, 'parent_id': parentId}),
      ));
}
