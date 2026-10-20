import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/payment.dart';

/// The citizen's payments: the list, issuing a new slip, uploading the receipt.
class PaymentsController extends ChangeNotifier {
  PaymentsController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  List<Payment> payments = const <Payment>[];
  bool loading = false;
  String? error;

  /// The slip that was just created, so the UI can show it immediately.
  IssuedSlip? lastIssuedSlip;

  bool issuing = false;
  bool uploading = false;

  Future<void> load({bool silent = false}) async {
    if (!silent) {
      loading = true;
      error = null;
      notifyListeners();
    }
    try {
      final data = await _api.getData('payments.php');
      final raw = data['payments'];
      payments = raw is List
          ? raw.whereType<Map<String, dynamic>>().map(Payment.fromJson).toList()
          : const <Payment>[];
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Create the payment slip for an obligation.
  ///
  /// The server caps the amount at the outstanding balance and defaults to
  /// paying it in full.
  Future<IssuedSlip> issueSlip({
    required int obligationId,
    required String method,
    double? amount,
  }) async {
    issuing = true;
    error = null;
    notifyListeners();
    try {
      final body = <String, dynamic>{
        'obligation_id': obligationId,
        'method': method,
      };
      if (amount != null) body['amount'] = amount;
      final data = await _api.postData('payments.php', body);
      final slip = IssuedSlip.fromJson(data);
      lastIssuedSlip = slip;
      issuing = false;
      notifyListeners();
      return slip;
    } on ApiException catch (e) {
      issuing = false;
      error = e.message;
      notifyListeners();
      rethrow;
    }
  }

  /// Upload the photo of the paid bank slip.
  Future<void> uploadReceipt({
    required int paymentId,
    required String filePath,
    required String fileName,
    String? mimeType,
  }) async {
    uploading = true;
    error = null;
    notifyListeners();
    try {
      await _api.postMultipart(
        'payments.php',
        'slip',
        filePath,
        fileName,
        mimeType: mimeType,
        query: {'id': '$paymentId', 'receipt': '1'},
      );
      uploading = false;
      notifyListeners();
      await load(silent: true);
    } on ApiException catch (e) {
      uploading = false;
      error = e.message;
      notifyListeners();
      rethrow;
    }
  }
}
