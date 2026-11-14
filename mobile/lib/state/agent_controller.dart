import 'package:flutter/foundation.dart';

import '../core/agent_api.dart';
import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/agent.dart';

/// Agent mode: obligations to collect, cash collections, receipts and handovers.
class AgentController extends ChangeNotifier {
  AgentController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  bool busy = false;
  String? error;

  List<AgentObligation> obligations = [];
  int _page = 1;
  int _total = 0;
  int _limit = 50;

  List<AgentCollection> pendingCollections = [];
  double pendingAmountTotal = 0;

  List<AgentHandover> handovers = [];

  AgentCollectResult? lastCollectResult;
  AgentHandover? lastHandover;

  int? agentVillageId;
  int? agentDistrictId;
  String? agentRole;

  bool get hasMore => obligations.length < _total;

  Future<void> loadObligations({int? feeYear, String status = ''}) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final resp = await _api.getAgentObligations(
        feeYear: feeYear,
        status: status,
        page: 1,
        limit: _limit,
      );
      obligations = resp.obligations;
      _page = resp.page;
      _total = resp.total;
      _limit = resp.limit;
      agentVillageId = (resp.agentInfo['village_id'] as num?)?.toInt();
      agentDistrictId = (resp.agentInfo['district_id'] as num?)?.toInt();
      agentRole = resp.agentInfo['role'] as String?;
    } on ApiException catch (e) {
      error = e.message;
      obligations = [];
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> loadMoreObligations({int? feeYear, String status = ''}) async {
    if (busy || !hasMore) return;
    busy = true;
    notifyListeners();
    try {
      final resp = await _api.getAgentObligations(
        feeYear: feeYear,
        status: status,
        page: _page + 1,
        limit: _limit,
      );
      obligations = [...obligations, ...resp.obligations];
      _page = resp.page;
      _total = resp.total;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<AgentCollectResult?> collectCash({
    required int obligationId,
    required String payerName,
    required String payerPhone,
    required String payerIdCard,
    double? amount,
  }) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final result = await _api.collectCash(
        obligationId: obligationId,
        payerName: payerName,
        payerPhone: payerPhone,
        payerIdCard: payerIdCard,
        amount: amount,
      );
      lastCollectResult = result;
      // Drop the collected parcel from the list without a full reload.
      obligations = obligations.where((o) => o.id != obligationId).toList();
      return result;
    } on ApiException catch (e) {
      error = e.message;
      return null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<AgentReceiptDetail?> fetchReceipt(int paymentId) async {
    try {
      return await _api.getReceipt(paymentId);
    } on ApiException catch (e) {
      error = e.message;
      notifyListeners();
      return null;
    }
  }

  Future<void> loadPendingCollections() async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final resp = await _api.getPendingCollections();
      pendingCollections = resp.collections;
      pendingAmountTotal = resp.amountTotal;
    } on ApiException catch (e) {
      error = e.message;
      pendingCollections = [];
      pendingAmountTotal = 0;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> loadHandovers({String? status}) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final resp = await _api.getAgentHandovers(status: status);
      handovers = resp.handovers;
    } on ApiException catch (e) {
      error = e.message;
      handovers = [];
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<AgentHandover?> createHandover(List<int> paymentIds) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final handover = await _api.createHandover(paymentIds);
      lastHandover = handover;
      await loadPendingCollections();
      return handover;
    } on ApiException catch (e) {
      error = e.message;
      return null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  void clearError() {
    error = null;
    notifyListeners();
  }
}
