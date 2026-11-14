import 'parsing.dart';

/// The signed-in user — a citizen, or a staff/agent account.
class CitizenSession {
  const CitizenSession({
    required this.token,
    required this.userId,
    required this.role,
    this.username,
    this.isAgent = false,
    this.agentRole,
    this.agentVillageId,
    this.agentVillageName,
    this.districtName,
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
      username: toStrOrNull(userMap['username']),
      isAgent: userMap['is_agent'] == true,
      agentRole: toStrOrNull(userMap['agent_role']),
      agentVillageId: userMap['agent_village_id'] == null
          ? null
          : toInt(userMap['agent_village_id']),
      agentVillageName: toStrOrNull(
        userMap['agent_village_name'] ?? userMap['agent_village_name_lo'],
      ),
      districtName: toStrOrNull(
        userMap['district_name_lo'] ?? userMap['district_name'],
      ),
    );
  }

  final String token;
  final int userId;
  final String role;
  final String? username;
  final bool isAgent;
  final String? agentRole;
  final int? agentVillageId;
  final String? agentVillageName;
  final String? districtName;
}
