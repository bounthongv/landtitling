import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/auth_store.dart';
import '../models/session.dart';

enum AuthStatus { unknown, unauthenticated, authenticated }

/// Owns the citizen session.
///
/// Sign-in is by **phone number alone**: nearly everyone has a mobile phone,
/// most do not use email, and nothing is sent anywhere. The server creates the
/// account on first use, so signing up and signing in are the same action.
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
      await _api.getData('citizen_payments.php');
      final user = await _store.readUser();
      session = CitizenSession(
        token: token,
        userId: (user?['id'] as num?)?.toInt() ?? 0,
        role: (user?['role'] as String?) ?? 'citizen',
      );
      status = AuthStatus.authenticated;
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await signOut();
      } else {
        // The network is down, but the token may still be good. Stay signed in
        // and let the screens show their own error.
        status = AuthStatus.authenticated;
        session = CitizenSession(token: token, userId: 0, role: 'citizen');
      }
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

  Future<void> signOut() async {
    await _store.clear();
    _api.token = null;
    session = null;
    phone = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
