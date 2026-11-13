/// The signed-in manager.
class ManagerSession {
  const ManagerSession({
    required this.token,
    required this.userId,
    required this.role,
    required this.level,
    this.username,
    this.districtId,
    this.districtName,
    this.provinceName,
  });

  factory ManagerSession.fromJson(
    Map<String, dynamic> json, {
    required String token,
  }) {
    final user = json['user'];
    final userMap = user is Map<String, dynamic> ? user : <String, dynamic>{};
    return ManagerSession(
      token: token,
      userId: _toInt(userMap['id']),
      role: _toStr(userMap['role'], 'manager'),
      level: _toStr(userMap['level'], 'district'),
      username: userMap['username'] as String?,
      districtId: userMap['district_id'] == null
          ? null
          : _toInt(userMap['district_id']),
      districtName: _toStrNullable(userMap['district_name']),
      provinceName: _toStrNullable(userMap['province_name']),
    );
  }

  final String token;
  final int userId;
  final String role;
  final String level;
  final String? username;
  final int? districtId;
  final String? districtName;
  final String? provinceName;

  /// National managers see every province; there is neither a district nor a
  /// province bound to the account.
  bool get isNational => level == 'national';

  /// A human label of what this account can see.
  String get scopeLabel => isNational
      ? 'ທົ່ວປະເທດ'
      : (provinceName ?? '') + (districtName == null ? '' : ' · $districtName');
}

int _toInt(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;
String _toStr(Object? v, String fallback) =>
    v?.toString().isNotEmpty == true ? v.toString() : fallback;
String? _toStrNullable(Object? v) => v?.toString();
