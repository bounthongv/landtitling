import 'parsing.dart';

/// The signed-in citizen.
class CitizenSession {
  const CitizenSession({
    required this.token,
    required this.userId,
    required this.role,
  });

  factory CitizenSession.fromJson(
    Map<String, dynamic> json, {
    required String token,
  }) {
    final user = json['user'];
    final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
    return CitizenSession(
      token: token,
      userId: toInt(userMap['id']),
      role: toStr(userMap['role'], 'citizen'),
    );
  }

  final String token;
  final int userId;
  final String role;
}
