import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart' show MediaType;

import 'api_exception.dart';
import 'config.dart';

/// Thin client over the Land Fee JSON API (`api/v1/*`).
///
/// The server answers with a fixed envelope:
///   success -> `{"ok": true, "data": {...}}`  (optionally `meta`)
///   failure -> `{"ok": false, "error": {"message": "...", "code": "..."}}`
///
/// [getData] / [postData] unwrap that envelope and throw [ApiException] on
/// failure, so callers only deal with the payload.
class ApiClient {
  ApiClient({http.Client? client, String? baseUrl})
    : _client = client ?? http.Client(),
      _baseUrl = (baseUrl ?? AppConfig.apiBase).replaceAll(RegExp(r'/+$'), '');

  final http.Client _client;
  final String _baseUrl;

  String? _token;

  /// The bearer token used for authenticated calls.
  String? get token => _token;
  set token(String? value) =>
      _token = (value == null || value.isEmpty) ? null : value;

  Uri _uri(String path, [Map<String, String>? query]) {
    final cleaned = <String, String>{};
    query?.forEach((k, v) {
      if (v.isNotEmpty) cleaned[k] = v;
    });
    return Uri.parse(
      '$_baseUrl/${path.replaceAll(RegExp(r'^/+'), '')}',
    ).replace(queryParameters: cleaned.isEmpty ? null : cleaned);
  }

  Map<String, String> _headers({bool json = true}) => <String, String>{
    if (json) 'Content-Type': 'application/json; charset=utf-8',
    'Accept': 'application/json',
    if (_token != null) 'Authorization': 'Bearer $_token',
  };

  /// GET returning the `data` payload.
  Future<Map<String, dynamic>> getData(
    String path, {
    Map<String, String>? query,
  }) async {
    return _unwrap(
      await _send(() => _client.get(_uri(path, query), headers: _headers())),
    );
  }

  /// POST a JSON body, returning the `data` payload.
  ///
  /// Some endpoints answer with a list at the top of `data`; those are wrapped
  /// by the caller. Here we always return a map, and `_unwrap` puts a JSON
  /// array into `{'items': [...]}` so the return type stays honest.
  Future<Map<String, dynamic>> postData(
    String path,
    Map<String, dynamic> body, {
    Map<String, String>? query,
  }) async {
    return _unwrap(
      await _send(
        () => _client.post(
          _uri(path, query),
          headers: _headers(),
          body: jsonEncode(body),
        ),
      ),
    );
  }

  /// POST a multipart body (file upload). The field name must match what the
  /// endpoint expects (`slip` for payment receipts).
  Future<Map<String, dynamic>> postMultipart(
    String path,
    String field,
    String filePath,
    String fileName, {
    String? mimeType,
    Map<String, String>? query,
  }) async {
    final file = await http.MultipartFile.fromPath(
      field,
      filePath,
      filename: fileName,
      contentType: mimeType == null ? null : MediaType.parse(mimeType),
    );
    final request = http.MultipartRequest('POST', _uri(path, query))
      ..files.add(file)
      ..headers.addAll(_headers(json: false));
    final streamed = await request.send();
    return _unwrap(await _toResponse(streamed));
  }

  /// GET a raw text body (used for the printable A4 slip HTML).
  Future<String> getText(String path, {Map<String, String>? query}) async {
    final response = await _client.get(
      _uri(path, query),
      headers: _headers(json: false),
    );
    if (response.statusCode >= 400) {
      throw ApiException(
        'Request failed (HTTP ${response.statusCode})',
        statusCode: response.statusCode,
      );
    }
    return response.body;
  }

  Future<http.Response> _send(Future<http.Response> Function() run) async {
    http.Response response;
    try {
      response = await run().timeout(const Duration(seconds: 30));
    } on SocketException {
      throw ApiException(
        'ບໍ່ສາມາດເຊື່ອມຕໍ່ກັບເຊີຟາດເວີເດີ. ກວດສອບອິນເຕີເນັດຂອງທ່ານ ແລ້ວລອງໃໝ່.',
      );
    } catch (e) {
      throw ApiException(
        'ການເຊື່ອມຕໍ່ລົ້າສົນ. ກວດສອບອິນເຕີເນັດຂອງທ່ານ ແລ້ວລອງໃໝ່.',
      );
    }
    return response;
  }

  Future<http.Response> _toResponse(http.StreamedResponse streamed) async {
    final body = await streamed.stream.bytesToString();
    return http.Response(
      body,
      streamed.statusCode,
      headers: streamed.headers,
      request: streamed.request,
    );
  }

  Map<String, dynamic> _unwrap(http.Response response) {
    Map<String, dynamic> decoded;
    try {
      final raw = jsonDecode(utf8.decode(response.bodyBytes));
      decoded = raw is Map<String, dynamic> ? raw : <String, dynamic>{};
    } catch (_) {
      throw ApiException(
        'ຂໍ້ມູນຈາກເຊີຟາດເວີເດີບໍ່ຖືກຕ້ອງ.',
        statusCode: response.statusCode,
      );
    }

    if (response.statusCode >= 400 || decoded['ok'] != true) {
      final error = decoded['error'];
      if (error is Map<String, dynamic>) {
        throw ApiException(
          (error['message'] as String?) ?? 'Request failed',
          code: error['code'] as String?,
          statusCode: response.statusCode,
        );
      }
      throw ApiException(
        'Request failed (HTTP ${response.statusCode})',
        statusCode: response.statusCode,
      );
    }

    final data = decoded['data'];
    if (data is Map<String, dynamic>) return data;
    if (data is List) return <String, dynamic>{'items': data};
    if (data == null) return <String, dynamic>{};
    return <String, dynamic>{'value': data};
  }
}
