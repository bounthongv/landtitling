import 'obligation.dart';
import 'parcel.dart';
import 'parsing.dart';

/// A person recorded as an owner of a parcel.
class Owner {
  const Owner({
    required this.id,
    required this.fullName,
    this.idNumber,
    this.role,
    this.sharePct,
  });

  factory Owner.fromJson(Map<String, dynamic> json) => Owner(
    id: toInt(json['id']),
    fullName: toStr(json['full_name']),
    idNumber: toStrOrNull(json['id_number']),
    role: toStrOrNull(json['owner_role'] ?? json['role']),
    sharePct: json['share_pct'] == null ? null : toDouble(json['share_pct']),
  );

  final int id;
  final String fullName;
  final String? idNumber;
  final String? role;
  final double? sharePct;
}

/// The priced view of one parcel: the parcel, the rate from its zone code,
/// and the amount due.
class ParcelQuote {
  const ParcelQuote({
    required this.parcel,
    required this.feeYear,
    required this.ratePerSqm,
    required this.rateFound,
    required this.amountDue,
    this.owners = const <Owner>[],
    this.obligation,
    this.history = const <Obligation>[],
  });

  factory ParcelQuote.fromJson(Map<String, dynamic> json) {
    final rawOwners = json['owners'];
    final rawHistory = json['history'];
    final rawObligation = json['obligation'];
    return ParcelQuote(
      parcel: Parcel.fromJson(
        (json['parcel'] as Map<String, dynamic>?) ?? <String, dynamic>{},
      ),
      feeYear: toInt(json['fee_year']),
      ratePerSqm: toDouble(json['rate_per_sqm']),
      rateFound: toBool(json['rate_found']),
      amountDue: toDouble(json['amount_due']),
      owners: rawOwners is List
          ? rawOwners
                .whereType<Map<String, dynamic>>()
                .map(Owner.fromJson)
                .toList()
          : const <Owner>[],
      obligation: rawObligation is Map<String, dynamic>
          ? Obligation.fromJson(rawObligation)
          : null,
      history: rawHistory is List
          ? rawHistory
                .whereType<Map<String, dynamic>>()
                .map(Obligation.fromJson)
                .toList()
          : const <Obligation>[],
    );
  }

  final Parcel parcel;
  final int feeYear;
  final double ratePerSqm;
  final bool rateFound;

  /// The amount the server assessed for this year.
  final double amountDue;

  final List<Owner> owners;
  final Obligation? obligation;
  final List<Obligation> history;

  /// Nothing left to pay for this year.
  bool get isSettled => obligation != null && obligation!.isSettled;

  /// What is still owed, from the obligation when it exists.
  double get outstanding {
    final ob = obligation;
    if (ob == null) return amountDue;
    return ob.outstanding;
  }
}
