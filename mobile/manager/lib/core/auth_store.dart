import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists the manager session between app restarts.
///
/// The JWT goes into the platform keystore (Android) / keychain (iOS) rather
/// than plain preferences — the token grants read access to the province's
/// collection data.
class AuthStore {
  AuthStore({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _kToken = 'manager_token';
  static const _kUser = 'manager_user';

  Future<void> saveToken(String token) =>
      _storage.write(key: _kToken, value: token);

  Future<String?> readToken() => _storage.read(key: _kToken);

  Future<void> saveUser(Map<String, dynamic> user) =>
      _storage.write(key: _kUser, value: jsonEncode(user));

  Future<Map<String, dynamic>?> readUser() async {
    final raw = await _storage.read(key: _kUser);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> clear() async {
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kUser);
  }
}
