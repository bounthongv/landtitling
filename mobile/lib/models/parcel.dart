import 'obligation.dart';
import 'parsing.dart';

/// A parcel of the signed-in citizen, with its annual obligations.
class Parcel {
  const Parcel({
    required this.id,
    required this.parcelNo,
    required this.areaSqm,
    required this.status,
    required this.obligations,
    this.subParcelNo,
    this.roadCategory,
    this.zoneCode,
    this.titleNo,
    this.titleType,
    this.issuedDate,
    this.districtName,
    this.villageName,
    this.ownerName,
    this.ownerRole,
    this.sharePct,
  });

  factory Parcel.fromJson(Map<String, dynamic> json) {
    final rawObligations = json['obligations'];
    return Parcel(
      id: toInt(json['parcel_id'] ?? json['id']),
      parcelNo: toStr(json['parcel_no']),
      subParcelNo: toStrOrNull(json['sub_parcel_no']),
      areaSqm: toDouble(json['area_sqm']),
      status: toStr(json['status'], 'draft'),
      roadCategory: toStrOrNull(json['road_category']),
      zoneCode: toStrOrNull(json['zone_code']),
      titleNo: toStrOrNull(json['title_no']),
      titleType: toStrOrNull(json['title_type']),
      issuedDate: toStrOrNull(json['issued_date']),
      districtName: toStrOrNull(json['district_name']),
      villageName: toStrOrNull(json['village_name']),
      ownerName: toStrOrNull(json['owner_name']),
      ownerRole: toStrOrNull(json['owner_role']),
      sharePct: json['share_pct'] == null ? null : toDouble(json['share_pct']),
      obligations: rawObligations is List
          ? rawObligations
                .whereType<Map<String, dynamic>>()
                .map(Obligation.fromJson)
                .toList()
          : const <Obligation>[],
    );
  }

  final int id;
  final String parcelNo;
  final String? subParcelNo;
  final double areaSqm;
  final String status;
  final String? roadCategory;
  final String? zoneCode;
  final String? titleNo;
  final String? titleType;
  final String? issuedDate;
  final String? districtName;
  final String? villageName;
  final String? ownerName;
  final String? ownerRole;
  final double? sharePct;
  final List<Obligation> obligations;

  /// The parcel number as the citizen knows it, with the sub-parcel suffix.
  String get displayNo => (subParcelNo == null || subParcelNo!.isEmpty)
      ? parcelNo
      : '$parcelNo/$subParcelNo';

  /// Obligations ordered newest year first.
  List<Obligation> get obligationsByYear =>
      [...obligations]..sort((a, b) => b.feeYear.compareTo(a.feeYear));

  /// The newest year that still has something to pay.
  Obligation? get currentObligation {
    for (final o in obligationsByYear) {
      if (!o.isSettled) return o;
    }
    return obligationsByYear.isEmpty ? null : obligationsByYear.first;
  }

  /// What the citizen still owes across all years.
  double get totalOutstanding => obligations.fold(
    0.0,
    (sum, o) => sum + (o.isSettled ? 0 : o.outstanding),
  );
}
