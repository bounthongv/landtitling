import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/auth_store.dart';
import '../core/strings.dart';
import '../models/session.dart';

enum AuthStatus { unknown, unauthenticated, authenticated }

/// Owns the session — citizen by phone, staff/agent by username + password.
///
/// Citizen sign-in is by **phone number alone**: nearly everyone has a mobile
/// phone, most do not use email, and nothing is sent anywhere. The server
/// creates the account on first use, so signing up and signing in are the same
/// action. Agents are staff accounts flagged `is_agent`.
class AuthController extends ChangeNotifier {
  AuthController({required ApiClient api, required AuthStore store})
    : _api = api,
      _store = store;

  final ApiClient _api;
  final AuthStore _store;

  AuthStatus status = AuthStatus.unknown;
  CitizenSession? session;

  /// The phone number of the signed-in citizen, as shown in the app.
  String? phone;

  /// True when the server created the account on this sign-in.
  bool justCreated = false;

  String? error;
  bool busy = false;

  bool get isAuthenticated =>
      status == AuthStatus.authenticated && session != null;

  bool get isAgent => session?.isAgent ?? false;

  String? get agentVillageName => session?.agentVillageName;

  String? get agentRole => session?.agentRole;

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
      // Refresh from the server so a newly flagged agent account (or a
      // deactivated one) is picked up without signing out and in again.
      final data = await _api.postData('auth.php', {'action': 'me'});
      final user = data['user'];
      final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
      session = CitizenSession.fromJson({'user': userMap}, token: token);
      await _store.saveUser(userMap);
      status = AuthStatus.authenticated;
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await signOut();
        return;
      }
      // The network is down, but the token may still be good. Stay signed in
      // with the cached user and let the screens show their own error.
      final user = await _store.readUser();
      session = CitizenSession.fromJson({
        'user': user ?? const <String, dynamic>{},
      }, token: token);
      status = AuthStatus.authenticated;
    }
    notifyListeners();
  }

  /// Sign in with a phone number, creating the account on first use.
  Future<bool> signIn({required String phone}) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final data = await _api.postData('citizen_auth.php', {
        'action': 'phone/signin',
        'phone': phone.trim(),
      });
      final token = data['token'] as String?;
      if (token == null || token.isEmpty) {
        throw ApiException('ໄດ້ຮັບໂຕເຄັດຫຼາ ກະລຸນາລອງໃໝ່');
      }
      _api.token = token;
      final user = data['user'];
      final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
      session = CitizenSession(
        token: token,
        userId: (userMap['id'] as num?)?.toInt() ?? 0,
        role: (userMap['role'] as String?) ?? 'citizen',
      );
      this.phone = data['phone']?.toString();
      justCreated = data['created'] == true;
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

  /// Staff / agent sign-in with username and password.
  Future<bool> signInAgent({
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
        throw ApiException(Strings.t('retry'));
      }
      final user = data['user'];
      final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
      if (userMap['is_agent'] != true) {
        throw ApiException(Strings.t('notAgent'));
      }
      _api.token = token;
      session = CitizenSession.fromJson({'user': userMap}, token: token);
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

  void clearError() {
    error = null;
    notifyListeners();
  }

  Future<void> signOut() async {
    await _store.clear();
    _api.token = null;
    session = null;
    phone = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
