import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/auth_store.dart';
import '../models/manager_session.dart';

enum AuthStatus { unknown, unauthenticated, authenticated }

/// Owns the manager session.
///
/// Sign-in is username + password against `auth.php action=login`, the same
/// endpoint the web back-office uses.
class AuthController extends ChangeNotifier {
  AuthController({required ApiClient api, required AuthStore store})
    : _api = api,
      _store = store;

  final ApiClient _api;
  final AuthStore _store;

  AuthStatus status = AuthStatus.unknown;
  ManagerSession? session;

  String? error;
  bool busy = false;

  bool get isAuthenticated =>
      status == AuthStatus.authenticated && session != null;

  /// Restore a session saved on a previous run.
  Future<void> restore() async {
    final token = await _store.readToken();
    if (token == null || token.isEmpty) {
      status = AuthStatus.unauthenticated;
      notifyListeners();
      return;
    }
    _api.token = token;
    try {
      // A cheap authenticated call proves the token is still good.
      await _api.getData('dashboard.php');
      final user = await _store.readUser();
      session = ManagerSession.fromJson(user ?? const {}, token: token);
      status = AuthStatus.authenticated;
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await signOut();
      } else {
        status = AuthStatus.authenticated;
        session = ManagerSession.fromJson(const {}, token: token);
      }
    }
    notifyListeners();
  }

  /// Sign in with a username and password.
  Future<bool> signIn({
    required String username,
    required String password,
  }) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final data = await _api.postData('auth.php', {
        'action': 'login',
        'username': username.trim(),
        'password': password,
      });
      final token = data['token'] as String?;
      if (token == null || token.isEmpty) {
        throw ApiException('Did not receive a token; please try again.');
      }
      _api.token = token;
      final user = data['user'];
      final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
      session = ManagerSession.fromJson({'user': userMap}, token: token);
      await _store.saveToken(token);
      await _store.saveUser(userMap);
      busy = false;
      status = AuthStatus.authenticated;
      notifyListeners();
      return true;
    } on ApiException catch (e) {
      busy = false;
      error = e.message;
      notifyListeners();
      return false;
    }
  }

  Future<void> signOut() async {
    await _store.clear();
    _api.token = null;
    session = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
