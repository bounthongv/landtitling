import '../core/api_client.dart';
import '../models/agent.dart';

/// Agent-specific API calls layered on the shared [ApiClient].
///
/// Endpoints (see docs/19-agent-cash-payment.md):
///   GET  agent/obligations.php   payable obligations in the agent's village
///   GET  agent/collections.php   collected but not yet handed over
///   POST agent/collect.php       record a cash collection
///   GET  agent/receipt.php       receipt for one cash payment
///   GET  agent/handovers.php     handover batches
///   POST agent/handovers.php     create a handover batch
extension AgentApi on ApiClient {
  Future<AgentObligationsResponse> getAgentObligations({
    int? feeYear,
    String status = '',
    int page = 1,
    int limit = 50,
  }) async {
    final data = await getData(
      'agent/obligations.php',
      query: {
        'fee_year': ?feeYear?.toString(),
        if (status.isNotEmpty) 'status': status,
        'page': '$page',
        'limit': '$limit',
      },
    );
    return AgentObligationsResponse.fromJson(data);
  }

  Future<AgentCollectionsResponse> getPendingCollections() async {
    final data = await getData('agent/collections.php');
    return AgentCollectionsResponse.fromJson(data);
  }

  Future<AgentCollectResult> collectCash({
    required int obligationId,
    required String payerName,
    required String payerPhone,
    required String payerIdCard,
    double? amount,
    String? cashPhotoBase64,
    String? idPhotoBase64,
  }) async {
    final data = await postData('agent/collect.php', {
      'obligation_id': obligationId,
      'payer_name': payerName,
      'payer_phone': payerPhone,
      'payer_id_card': payerIdCard,
      'amount': ?amount,
      'cash_photo_base64': ?cashPhotoBase64,
      'id_photo_base64': ?idPhotoBase64,
    });
    return AgentCollectResult.fromJson(data);
  }

  Future<AgentReceiptDetail> getReceipt(int paymentId) async {
    final data = await getData(
      'agent/receipt.php',
      query: {'payment_id': '$paymentId'},
    );
    return AgentReceiptDetail.fromJson(data);
  }

  Future<AgentHandoversResponse> getAgentHandovers({
    String? status,
    int page = 1,
    int limit = 20,
  }) async {
    final data = await getData(
      'agent/handovers.php',
      query: {
        if (status != null && status.isNotEmpty) 'status': status,
        'page': '$page',
        'limit': '$limit',
      },
    );
    return AgentHandoversResponse.fromJson(data);
  }

  Future<AgentHandover> createHandover(List<int> paymentIds) async {
    final data = await postData('agent/handovers.php', {
      'payment_ids': paymentIds,
    });
    return AgentHandover.fromJson(data);
  }
}

class AgentObligationsResponse {
  const AgentObligationsResponse({
    required this.obligations,
    required this.count,
    required this.total,
    required this.page,
    required this.limit,
    required this.agentInfo,
  });

  factory AgentObligationsResponse.fromJson(Map<String, dynamic> json) {
    final list = (json['obligations'] as List? ?? [])
        .map((e) => AgentObligation.fromJson(e as Map<String, dynamic>))
        .toList();
    return AgentObligationsResponse(
      obligations: list,
      count: (json['count'] as num?)?.toInt() ?? list.length,
      total: (json['total'] as num?)?.toInt() ?? list.length,
      page: (json['page'] as num?)?.toInt() ?? 1,
      limit: (json['limit'] as num?)?.toInt() ?? 50,
      agentInfo: (json['agent'] as Map<String, dynamic>?) ?? const {},
    );
  }

  final List<AgentObligation> obligations;
  final int count;
  final int total;
  final int page;
  final int limit;
  final Map<String, dynamic> agentInfo;
}

class AgentCollectionsResponse {
  const AgentCollectionsResponse({
    required this.collections,
    required this.count,
    required this.amountTotal,
  });

  factory AgentCollectionsResponse.fromJson(Map<String, dynamic> json) {
    final list = (json['collections'] as List? ?? [])
        .map((e) => AgentCollection.fromJson(e as Map<String, dynamic>))
        .toList();
    return AgentCollectionsResponse(
      collections: list,
      count: (json['count'] as num?)?.toInt() ?? list.length,
      amountTotal: (json['amount_total'] as num?)?.toDouble() ?? 0,
    );
  }

  final List<AgentCollection> collections;
  final int count;
  final double amountTotal;
}

class AgentHandoversResponse {
  const AgentHandoversResponse({
    required this.handovers,
    required this.count,
    required this.total,
    required this.page,
    required this.limit,
  });

  factory AgentHandoversResponse.fromJson(Map<String, dynamic> json) {
    final list = (json['handovers'] as List? ?? [])
        .map((e) => AgentHandover.fromJson(e as Map<String, dynamic>))
        .toList();
    return AgentHandoversResponse(
      handovers: list,
      count: (json['count'] as num?)?.toInt() ?? list.length,
      total: (json['total'] as num?)?.toInt() ?? list.length,
      page: (json['page'] as num?)?.toInt() ?? 1,
      limit: (json['limit'] as num?)?.toInt() ?? 20,
    );
  }

  final List<AgentHandover> handovers;
  final int count;
  final int total;
  final int page;
  final int limit;
}
