import 'parsing.dart';

/// One assessed annual land tax for a parcel, for one year.
///
/// The amounts are the server's snapshots (`fee_obligations`): the app never
/// recomputes the fee, because the province can revise zone rates and the
/// obligation keeps the rate that applied when it was assessed.
class Obligation {
  const Obligation({
    required this.id,
    required this.feeYear,
    required this.amountDue,
    required this.amountPaid,
    required this.penalty,
    required this.outstanding,
    required this.status,
    this.dueDate,
    this.zoneCode,
    this.ratePerSqm,
    this.areaSqm,
  });

  factory Obligation.fromJson(Map<String, dynamic> json) {
    final due = toDouble(json['amount_due']);
    final paid = toDouble(json['amount_paid']);
    final penalty = toDouble(json['penalty']);
    // The server sends `outstanding`; fall back to computing it.
    final outstanding = json.containsKey('outstanding')
        ? toDouble(json['outstanding'])
        : (due + penalty - paid);
    return Obligation(
      id: toInt(json['id']),
      feeYear: toInt(json['fee_year']),
      amountDue: due,
      amountPaid: paid,
      penalty: penalty,
      outstanding: outstanding,
      status: toStr(json['status'], 'UNPAID'),
      dueDate: toStrOrNull(json['due_date']),
      zoneCode: toStrOrNull(json['zone_code']),
      ratePerSqm: json['rate_per_sqm'] == null
          ? null
          : toDouble(json['rate_per_sqm']),
      areaSqm: json['area_sqm'] == null ? null : toDouble(json['area_sqm']),
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
  final String? zoneCode;
  final double? ratePerSqm;
  final double? areaSqm;

  /// Nothing left to pay for this year.
  bool get isSettled =>
      status == 'PAID' ||
      status == 'WAIVED' ||
      status == 'OVERPAID' ||
      status == 'REFUNDED';

  /// Partial payment: something was paid, but the balance remains.
  bool get isPartial => status == 'PARTIAL' && outstanding > 0;

  bool get isOverdue {
    if (isSettled || dueDate == null) return false;
    final due = DateTime.tryParse(dueDate!);
    if (due == null) return false;
    return due.isBefore(DateTime.now());
  }
}
