import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/auth_store.dart';
import '../models/session.dart';

enum AuthStatus { unknown, unauthenticated, waitingForCode, authenticated }

/// Owns the citizen session: requesting the OTP, verifying it, restoring a
/// saved session on app start, and signing out.
class AuthController extends ChangeNotifier {
  AuthController({required ApiClient api, required AuthStore store})
    : _api = api,
      _store = store;

  final ApiClient _api;
  final AuthStore _store;

  AuthStatus status = AuthStatus.unknown;
  CitizenSession? session;

  /// The email a code was requested for.
  String? pendingEmail;

  /// The signed OTP token the server handed back; it must be echoed with the code.
  String? otpToken;

  /// When the code stops being valid.
  DateTime? otpExpiresAt;

  /// Only ever set when the server runs with OTP_DEBUG on (development builds).
  String? debugCode;

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
      await _api.getData('my_parcels.php');
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
        // The network is down, but the token may still be fine. Stay signed in
        // and let the screens show their own error.
        status = AuthStatus.authenticated;
        session = CitizenSession(token: token, userId: 0, role: 'citizen');
      }
    }
    notifyListeners();
  }

  /// Step 1 of the login: ask the server to email a code.
  Future<bool> requestCode(String email) async {
    final trimmed = email.trim();
    if (trimmed.isEmpty) {
      error = 'ກະລຸນາປ້ອນອີເມວຂອງທ່ານ';
      notifyListeners();
      return false;
    }
    busy = true;
    error = null;
    debugCode = null;
    notifyListeners();
    try {
      final data = await _api.postData('auth.php', {
        'action': 'otp/request',
        'email': trimmed,
      });
      otpToken = data['otp_token'] as String?;
      pendingEmail = trimmed;
      final expires = data['expires_at'];
      otpExpiresAt = expires == null
          ? null
          : DateTime.tryParse(expires.toString());
      final debug = data['debug_code'];
      if (debug != null) debugCode = debug.toString();
      busy = false;
      status = AuthStatus.waitingForCode;
      notifyListeners();
      return true;
    } on ApiException catch (e) {
      busy = false;
      error = e.message;
      notifyListeners();
      return false;
    }
  }

  /// Step 2: exchange the code for a JWT.
  Future<bool> verifyCode(String code) async {
    if (pendingEmail == null || otpToken == null) {
      error = 'ກະລຸນາຂໍລະຫັດໃໝ່ກ່ອນ';
      notifyListeners();
      return false;
    }
    busy = true;
    error = null;
    notifyListeners();
    try {
      final data = await _api.postData('auth.php', {
        'action': 'otp/verify',
        'email': pendingEmail,
        'code': code.trim(),
        'otp_token': otpToken,
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

  /// Back to the email step (the code is still valid for a few minutes).
  void changeEmail() {
    otpToken = null;
    pendingEmail = null;
    otpExpiresAt = null;
    debugCode = null;
    error = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }

  Future<void> signOut() async {
    await _store.clear();
    _api.token = null;
    session = null;
    otpToken = null;
    pendingEmail = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
