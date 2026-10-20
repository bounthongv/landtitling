import 'parsing.dart';

/// A payment (settlement) against an annual obligation.
class Payment {
  const Payment({
    required this.id,
    required this.referenceNo,
    required this.amount,
    required this.status,
    required this.method,
    required this.feeYear,
    this.parcelNo,
    this.districtName,
    this.villageName,
    this.createdAt,
    this.paidAt,
    this.slipPath,
    this.verifyNote,
    this.obligationStatus,
  });

  factory Payment.fromJson(Map<String, dynamic> json) => Payment(
    id: toInt(json['id']),
    referenceNo: toStr(json['reference_no']),
    amount: toDouble(json['amount']),
    status: toStr(json['status'], 'ISSUED'),
    method: toStr(json['method'], 'counter'),
    feeYear: toInt(json['fee_year']),
    parcelNo: toStrOrNull(json['parcel_no']),
    districtName: toStrOrNull(json['district_name']),
    villageName: toStrOrNull(json['village_name']),
    createdAt: toStrOrNull(json['created_at']),
    paidAt: toStrOrNull(json['paid_at']),
    slipPath: toStrOrNull(json['slip_path']),
    verifyNote: toStrOrNull(json['verify_note']),
    obligationStatus: toStrOrNull(json['obligation_status']),
  );

  final int id;
  final String referenceNo;
  final double amount;
  final String status;
  final String method;
  final int feeYear;
  final String? parcelNo;
  final String? districtName;
  final String? villageName;
  final String? createdAt;
  final String? paidAt;
  final String? slipPath;
  final String? verifyNote;
  final String? obligationStatus;

  /// The receipt photo has been uploaded and is waiting for the officer.
  bool get hasReceipt => slipPath != null && slipPath!.isNotEmpty;

  bool get isPaid => status == 'PAID';
  bool get isRejected => status == 'REJECTED';
  bool get isIssued => status == 'ISSUED';

  /// Issued and a receipt uploaded: the officer has not decided yet.
  bool get isPendingReview => isIssued && hasReceipt;
}

/// The result of creating a payment: the slip the citizen must pay with.
class IssuedSlip {
  const IssuedSlip({
    required this.id,
    required this.referenceNo,
    required this.amount,
    required this.slipQr,
    required this.status,
    this.dueDate,
  });

  factory IssuedSlip.fromJson(Map<String, dynamic> json) => IssuedSlip(
    id: toInt(json['id']),
    referenceNo: toStr(json['reference_no']),
    amount: toDouble(json['amount']),
    slipQr: toStr(json['slip_qr']),
    status: toStr(json['status'], 'ISSUED'),
    dueDate: toStrOrNull(json['due_date']),
  );

  final int id;
  final String referenceNo;
  final double amount;
  final String slipQr;
  final String status;
  final String? dueDate;
}
