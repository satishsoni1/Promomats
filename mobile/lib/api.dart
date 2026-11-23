import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Server address baked in at build time:
///   flutter build apk --dart-define=API_BASE_URL=https://vodo.himalayawellness.com
/// It can also be changed on the sign-in screen (kept on the device).
const String defaultBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'https://vodo-vault.globalspace.in');

typedef Json = Map<String, dynamic>;

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode});
  final String message;
  final int? statusCode;

  bool get isUnauthenticated => statusCode == 401;

  @override
  String toString() => message;
}

/// A file chosen on the phone, sent as multipart.
class UploadFile {
  UploadFile({required this.path, required this.name});
  final String path;
  final String name;
}

/// Thin client for the VODO mobile API (routes/api.php on the server). Every action
/// runs the same web controller the browser uses, so the rules match the web.
class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  final _storage = const FlutterSecureStorage();
  String _baseUrl = defaultBaseUrl;
  String? _token;
  Json? user;

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

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('$_baseUrl/api/v1$path').replace(queryParameters: (query == null || query.isEmpty) ? null : query);

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<Json> _send(Future<http.Response> Function() call, {Duration timeout = const Duration(seconds: 30)}) async {
    http.Response res;
    try {
      res = await call().timeout(timeout);
    } catch (_) {
      throw ApiException('Could not reach the server. Check your connection and the server address.');
    }

    Json body;
    try {
      body = res.body.isEmpty ? <String, dynamic>{} : (jsonDecode(res.body) as Json);
    } catch (_) {
      body = {'message': 'Unexpected response from the server (${res.statusCode}).'};
    }
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

  Future<Json> _get(String path, [Map<String, String>? query]) => _send(() => http.get(_uri(path, query), headers: _headers));

  Future<Json> _post(String path, [Json? body]) =>
      _send(() => http.post(_uri(path), headers: _headers, body: jsonEncode(body ?? const {})));

  Future<Json> _patch(String path, Json body) => _send(() => http.patch(_uri(path), headers: _headers, body: jsonEncode(body)));

  /// Multipart POST: [fields] may hold nested maps/lists (sent PHP-style, e.g.
  /// stage_approvers[12][]=5); [files] maps a field name to one or more files.
  Future<Json> _multipart(String path, Json fields, Map<String, List<UploadFile>> files) {
    return _send(() async {
      final req = http.MultipartRequest('POST', _uri(path));
      req.headers.addAll({'Accept': 'application/json', if (_token != null) 'Authorization': 'Bearer $_token'});
      _flatten(fields, '', req.fields);
      for (final entry in files.entries) {
        for (final f in entry.value) {
          req.files.add(await http.MultipartFile.fromPath(entry.key, f.path, filename: f.name));
        }
      }
      return http.Response.fromStream(await req.send());
    }, timeout: const Duration(minutes: 10));
  }

  static void _flatten(dynamic value, String prefix, Map<String, String> out) {
    if (value == null) return;
    if (value is Map) {
      value.forEach((k, v) => _flatten(v, prefix.isEmpty ? '$k' : '$prefix[$k]', out));
    } else if (value is List) {
      // Lists of scalars need distinct keys in a Map, so index them explicitly.
      for (var i = 0; i < value.length; i++) {
        _flatten(value[i], '$prefix[$i]', out);
      }
    } else if (value is bool) {
      out[prefix] = value ? '1' : '0';
    } else {
      out[prefix] = '$value';
    }
  }

  // ---- session ----

  Future<Json> login(String email, String password) async {
    final body = await _post('/login', {'email': email, 'password': password, 'device_name': 'VODO mobile'});
    _token = body['token'] as String;
    await _storage.write(key: 'token', value: _token);
    user = body['user'] as Json;
    return user!;
  }

  Future<void> logout() async {
    try {
      await _post('/logout');
    } catch (_) {
      // Signing out locally is what matters.
    }
    await _clearToken();
  }

  Future<void> _clearToken() async {
    _token = null;
    user = null;
    await _storage.delete(key: 'token');
  }

  Future<Json> me() async {
    final body = await _get('/me');
    user = body['user'] as Json;
    return user!;
  }

  // ---- lists ----

  Future<Json> tasks({int? brandManagerId}) => _get('/tasks', {if (brandManagerId != null) 'bm': '$brandManagerId'});
  Future<Json> library({String stage = 'all', String? query, int page = 1}) =>
      _get('/library', {'stage': stage, if (query != null && query.isNotEmpty) 'q': query, 'page': '$page'});
  Future<Json> notifications({int page = 1}) => _get('/notifications', {'page': '$page'});
  Future<Json> readNotification(String id) => _post('/notifications/$id/read');
  Future<Json> readAllNotifications() => _post('/notifications/read-all');
  Future<Json> helpdesk() => _get('/helpdesk');
  Future<Json> raiseTicket(Json fields) => _post('/helpdesk', fields);
  Future<Json> createOptions() => _get('/create-options');

  // ---- a job ----

  Future<Json> document(int id) => _get('/documents/$id');

  Future<Json> createJob(Json fields, {UploadFile? file, List<UploadFile> references = const []}) =>
      _multipart('/documents', fields, {if (file != null) 'file': [file], if (references.isNotEmpty) 'reference_files[]': references});

  Future<Json> uploadVersion(int id, UploadFile file, {String? notes}) =>
      _multipart('/documents/$id/versions', {'change_notes': notes}, {'file': [file]});

  /// Submit for review / resubmit / start review after the draft is aligned.
  Future<Json> submit(int id, {UploadFile? file, String? notes}) =>
      _multipart('/documents/$id/submit', {'change_notes': notes}, {if (file != null) 'file': [file]});

  Future<Json> decide(int id, String decision, String comments, {UploadFile? signedCopy}) => _multipart(
        '/documents/$id/decision',
        {'decision': decision, 'comments': comments},
        {if (signedCopy != null) 'signed_copy': [signedCopy]},
      );

  Future<Json> comment(int id, String body, {int? parentId, int? page, double? x, double? y}) => _post('/documents/$id/comments', {
        'body': body,
        'parent_id': parentId,
        if (page != null) ...{'page_number': page, 'x_position': x, 'y_position': y},
      });

  Future<Json> editComment(int id, int commentId, String body) => _patch('/documents/$id/comments/$commentId', {'body': body});

  Future<Json> resolveComment(int id, int commentId, bool resolved) =>
      _patch('/documents/$id/comments/$commentId/resolve', {'resolved': resolved});

  Future<Json> addReference(int id, UploadFile file, {String? title}) =>
      _multipart('/documents/$id/references', {'title': title}, {'file': [file]});

  Future<Json> setStatus(int id, String status) => _post('/documents/$id/status', {'status': status});

  Future<Json> reassign(int id, int taskId, int userId, String reason) =>
      _post('/documents/$id/tasks/$taskId/reassign', {'user_id': userId, 'reason': reason});

  Future<Json> setDue(int id, int taskId, DateTime dueAt) =>
      _post('/documents/$id/tasks/$taskId/due', {'due_at': dueAt.toIso8601String()});

  // ---- Content Agency / Design Team work ----

  Future<Json> requestWork(int id, {required String team, String? instructions, DateTime? due, int? assignTo, bool resubmit = false}) =>
      _post('/documents/$id/work-tasks', {
        'team': team,
        'instructions': instructions,
        if (due != null) 'due_date': '${due.year}-${due.month.toString().padLeft(2, '0')}-${due.day.toString().padLeft(2, '0')}',
        'assign_to': assignTo,
        'resubmit_on_upload': resubmit,
      });

  Future<Json> assignPlaceholder(int id, String team, String? instructions) =>
      _post('/documents/$id/placeholder-assign', {'team': team, 'instructions': instructions});

  Future<Json> takeWork(int taskId) => _post('/work-tasks/$taskId/take');
  Future<Json> assignWork(int taskId, int userId) => _post('/work-tasks/$taskId/assign', {'user_id': userId});
  Future<Json> completeWork(int taskId, UploadFile file, {String? notes}) =>
      _multipart('/work-tasks/$taskId/complete', {'notes': notes}, {'file': [file]});
  Future<Json> cancelWork(int taskId) => _send(() => http.delete(_uri('/work-tasks/$taskId'), headers: _headers));
}
