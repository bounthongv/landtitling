import 'parsing.dart';

/// An obligation available for cash collection by an agent.
class AgentObligation {
  const AgentObligation({
    required this.id,
    required this.feeYear,
    required this.amountDue,
    required this.amountPaid,
    required this.penalty,
    required this.outstanding,
    required this.status,
    required this.dueDate,
    required this.parcelId,
    required this.parcelNo,
    this.subParcelNo,
    this.areaSqm,
    this.zoneCode,
    this.roadCategory,
    this.titleNo,
    this.mapRefNo,
    required this.districtName,
    required this.villageName,
    required this.ownerName,
    this.ownerIdNumber,
    this.ownerPhone,
    this.daysOverdue,
  });

  factory AgentObligation.fromJson(Map<String, dynamic> json) {
    final due = toDouble(json['amount_due']);
    final paid = toDouble(json['amount_paid']);
    final penalty = toDouble(json['penalty']);
    final outstanding = json.containsKey('outstanding')
        ? toDouble(json['outstanding'])
        : (due + penalty - paid);
    return AgentObligation(
      id: toInt(json['id']),
      feeYear: toInt(json['fee_year']),
      amountDue: due,
      amountPaid: paid,
      penalty: penalty,
      outstanding: outstanding,
      status: toStr(json['status'], 'UNPAID'),
      dueDate: toStrOrNull(json['due_date']),
      parcelId: toInt(json['parcel_id']),
      parcelNo: toStr(json['parcel_no']),
      subParcelNo: toStrOrNull(json['sub_parcel_no']),
      areaSqm: json['area_sqm'] == null ? null : toDouble(json['area_sqm']),
      zoneCode: toStrOrNull(json['zone_code']),
      roadCategory: toStrOrNull(json['road_category']),
      titleNo: toStrOrNull(json['title_no']),
      mapRefNo: toStrOrNull(json['map_ref_no']),
      districtName: toStr(json['district_name']),
      villageName: toStr(json['village_name']),
      ownerName: toStr(json['owner_name']),
      ownerIdNumber: toStrOrNull(json['owner_id_number']),
      ownerPhone: toStrOrNull(json['owner_phone']),
      daysOverdue: json['days_overdue'] == null
          ? null
          : toInt(json['days_overdue']),
    );
  }

  final int id;
  final int feeYear;
  final double amountDue;
  final double amountPaid;
  final double penalty;
  final double outstanding;
  final String status;
  final String? dueDate;
  final int parcelId;
  final String parcelNo;
  final String? subParcelNo;
  final double? areaSqm;
  final String? zoneCode;
  final String? roadCategory;
  final String? titleNo;
  final String? mapRefNo;
  final String districtName;
  final String villageName;
  final String ownerName;
  final String? ownerIdNumber;
  final String? ownerPhone;
  final int? daysOverdue;

  bool get isOverdue {
    if (isSettled || dueDate == null) return false;
    final due = DateTime.tryParse(dueDate!);
    if (due == null) return false;
    return due.isBefore(DateTime.now());
  }

  bool get isSettled =>
      status == 'PAID' ||
      status == 'WAIVED' ||
      status == 'OVERPAID' ||
      status == 'REFUNDED';
}

/// Agent cash payment receipt response.
class AgentReceipt {
  const AgentReceipt({
    required this.paymentId,
    required this.referenceNo,
    required this.receiptHtml,
    required this.amount,
    required this.status,
    required this.collectedAt,
  });

  factory AgentReceipt.fromJson(Map<String, dynamic> json) => AgentReceipt(
    paymentId: toInt(json['payment_id']),
    referenceNo: toStr(json['reference_no']),
    receiptHtml: toStr(json['receipt_html']),
    amount: toDouble(json['amount']),
    status: toStr(json['status']),
    collectedAt: toStr(json['collected_at']),
  );

  final int paymentId;
  final String referenceNo;
  final String receiptHtml;
  final double amount;
  final String status;
  final String collectedAt;
}

/// Handover batch created by agent.
class AgentHandover {
  const AgentHandover({
    required this.id,
    required this.batchReference,
    required this.amountTotal,
    required this.paymentCount,
    required this.status,
    required this.createdAt,
    this.handedOverAt,
    this.verifiedAt,
    this.villageName,
    this.payments = const [],
  });

  factory AgentHandover.fromJson(Map<String, dynamic> json) {
    final payments = (json['payments'] as List? ?? [])
        .map((e) => HandoverPayment.fromJson(e as Map<String, dynamic>))
        .toList();
    return AgentHandover(
      id: toInt(json['id']),
      batchReference: toStr(json['batch_reference']),
      amountTotal: toDouble(json['amount_total']),
      paymentCount: toInt(json['payment_count']),
      status: toStr(json['status']),
      createdAt: toStr(json['created_at']),
      handedOverAt: toStrOrNull(json['handed_over_at']),
      verifiedAt: toStrOrNull(json['verified_at']),
      villageName: toStrOrNull(json['village_name']),
      payments: payments,
    );
  }

  final int id;
  final String batchReference;
  final double amountTotal;
  final int paymentCount;
  final String status; // pending, verified, rejected
  final String createdAt;
  final String? handedOverAt;
  final String? verifiedAt;
  final String? villageName;
  final List<HandoverPayment> payments;
}

/// Payment within a handover batch.
class HandoverPayment {
  const HandoverPayment({
    required this.id,
    required this.referenceNo,
    required this.amount,
    required this.parcelId,
    required this.parcelNo,
    this.status,
  });

  factory HandoverPayment.fromJson(Map<String, dynamic> json) =>
      HandoverPayment(
        id: toInt(json['id']),
        referenceNo: toStr(json['reference_no']),
        amount: toDouble(json['amount']),
        parcelId: toInt(json['parcel_id']),
        parcelNo: toStr(json['parcel_no']),
        status: toStrOrNull(json['payment_status'] ?? json['status']),
      );

  final int id;
  final String referenceNo;
  final double amount;
  final int parcelId;
  final String parcelNo;
  final String? status;
}

/// A cash collection taken by the agent but not yet handed over.
class AgentCollection {
  const AgentCollection({
    required this.id,
    required this.referenceNo,
    required this.amount,
    required this.status,
    required this.parcelId,
    required this.parcelNo,
    required this.payerName,
    required this.collectedAt,
    this.payerPhone,
    this.feeYear,
    this.subParcelNo,
    this.mapRefNo,
    this.districtName,
    this.villageName,
  });

  factory AgentCollection.fromJson(Map<String, dynamic> json) =>
      AgentCollection(
        id: toInt(json['id']),
        referenceNo: toStr(json['reference_no']),
        amount: toDouble(json['amount']),
        status: toStr(json['status']),
        parcelId: toInt(json['parcel_id']),
        parcelNo: toStr(json['parcel_no']),
        payerName: toStr(json['payer_name']),
        collectedAt: toStr(json['collected_at']),
        payerPhone: toStrOrNull(json['payer_phone']),
        feeYear: json['fee_year'] == null ? null : toInt(json['fee_year']),
        subParcelNo: toStrOrNull(json['sub_parcel_no']),
        mapRefNo: toStrOrNull(json['map_ref_no']),
        districtName: toStrOrNull(json['district_name']),
        villageName: toStrOrNull(json['village_name']),
      );

  final int id;
  final String referenceNo;
  final double amount;
  final String status;
  final int parcelId;
  final String parcelNo;
  final String payerName;
  final String collectedAt;
  final String? payerPhone;
  final int? feeYear;
  final String? subParcelNo;
  final String? mapRefNo;
  final String? districtName;
  final String? villageName;
}

/// Full receipt detail for a cash collection.
class AgentReceiptDetail {
  const AgentReceiptDetail({
    required this.paymentId,
    required this.referenceNo,
    required this.amount,
    required this.status,
    required this.payerName,
    this.payerPhone,
    this.payerIdCard,
    this.agentUsername,
    this.agentRole,
    this.collectedAt,
    this.handedOverAt,
    this.parcelNo,
    this.subParcelNo,
    this.mapRefNo,
    this.titleNo,
    this.areaSqm,
    this.districtName,
    this.villageName,
    this.feeYear,
    this.receiptHtml,
  });

  factory AgentReceiptDetail.fromJson(Map<String, dynamic> json) =>
      AgentReceiptDetail(
        paymentId: toInt(json['payment_id']),
        referenceNo: toStr(json['reference_no']),
        amount: toDouble(json['amount']),
        status: toStr(json['status']),
        payerName: toStr(json['payer_name']),
        payerPhone: toStrOrNull(json['payer_phone']),
        payerIdCard: toStrOrNull(json['payer_id_card']),
        agentUsername: toStrOrNull(json['agent_username']),
        agentRole: toStrOrNull(json['agent_role']),
        collectedAt: toStrOrNull(json['collected_at']),
        handedOverAt: toStrOrNull(json['handed_over_at']),
        parcelNo: toStrOrNull(json['parcel_no']),
        subParcelNo: toStrOrNull(json['sub_parcel_no']),
        mapRefNo: toStrOrNull(json['map_ref_no']),
        titleNo: toStrOrNull(json['title_no']),
        areaSqm: json['area_sqm'] == null ? null : toDouble(json['area_sqm']),
        districtName: toStrOrNull(json['district_name']),
        villageName: toStrOrNull(json['village_name']),
        feeYear: json['fee_year'] == null ? null : toInt(json['fee_year']),
        receiptHtml: toStrOrNull(json['receipt_html']),
      );

  final int paymentId;
  final String referenceNo;
  final double amount;
  final String status;
  final String payerName;
  final String? payerPhone;
  final String? payerIdCard;
  final String? agentUsername;
  final String? agentRole;
  final String? collectedAt;
  final String? handedOverAt;
  final String? parcelNo;
  final String? subParcelNo;
  final String? mapRefNo;
  final String? titleNo;
  final double? areaSqm;
  final String? districtName;
  final String? villageName;
  final int? feeYear;
  final String? receiptHtml;
}

/// Result of cash collection.
class AgentCollectResult {
  const AgentCollectResult({
    required this.paymentId,
    required this.referenceNo,
    required this.amount,
    required this.status,
    required this.collectedAt,
    required this.receiptUrl,
    required this.obligation,
  });

  factory AgentCollectResult.fromJson(Map<String, dynamic> json) {
    final obligation = json['obligation'] as Map<String, dynamic>?;
    return AgentCollectResult(
      paymentId: toInt(json['payment_id']),
      referenceNo: toStr(json['reference_no']),
      amount: toDouble(json['amount']),
      status: toStr(json['status']),
      collectedAt: toStr(json['collected_at']),
      receiptUrl: toStr(json['receipt_url']),
      obligation: obligation != null
          ? AgentObligationSummary.fromJson(obligation)
          : null,
    );
  }

  final int paymentId;
  final String referenceNo;
  final double amount;
  final String status;
  final String collectedAt;
  final String receiptUrl;
  final AgentObligationSummary? obligation;
}

class AgentObligationSummary {
  const AgentObligationSummary({
    required this.id,
    required this.feeYear,
    required this.parcelNo,
    required this.ownerName,
    required this.outstandingBefore,
    required this.outstandingAfter,
  });

  factory AgentObligationSummary.fromJson(Map<String, dynamic> json) =>
      AgentObligationSummary(
        id: toInt(json['id']),
        feeYear: toInt(json['fee_year']),
        parcelNo: toStr(json['parcel_no']),
        ownerName: toStr(json['owner_name']),
        outstandingBefore: toDouble(json['outstanding_before']),
        outstandingAfter: toDouble(json['outstanding_after']),
      );

  final int id;
  final int feeYear;
  final String parcelNo;
  final String ownerName;
  final double outstandingBefore;
  final double outstandingAfter;
}
