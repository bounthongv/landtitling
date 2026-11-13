/// The dashboard payload (`dashboard.php`).
class DashboardData {
  const DashboardData({
    required this.asOf,
    required this.feeYear,
    required this.years,
    required this.kpis,
    required this.allYears,
    required this.receipts,
    required this.breakdownRows,
    required this.breakdownGroup,
    required this.aging,
    required this.recent,
    required this.scopeName,
  });

  factory DashboardData.fromJson(Map<String, dynamic> json) {
    final scope = json['scope'];
    final scopeMap = scope is Map<String, dynamic>
        ? scope
        : <String, dynamic>{};
    final kpis = json['kpis'];
    final kpiMap = kpis is Map<String, dynamic> ? kpis : <String, dynamic>{};
    final allYears = json['all_years'];
    final ayMap = allYears is Map<String, dynamic>
        ? allYears
        : <String, dynamic>{};
    final receipts = json['receipts'];
    final receiptList = receipts is List ? receipts : <dynamic>[];
    final breakdown = json['breakdown'];
    final bdMap = breakdown is Map<String, dynamic>
        ? breakdown
        : <String, dynamic>{};
    final rows = bdMap['rows'];
    final aging = json['aging'];
    final agingList = aging is List ? aging : <dynamic>[];
    final recent = json['recent'];
    final recentList = recent is List ? recent : <dynamic>[];

    return DashboardData(
      asOf: _str(json['as_of']),
      feeYear: json['fee_year'] == null ? null : _int(json['fee_year']),
      years: json['years'] is List
          ? (json['years'] as List).map((y) => _int(y)).toList()
          : const <int>[],
      kpis: Kpis.fromJson(kpiMap),
      allYears: AllYearsData.fromJson(ayMap),
      receipts: receiptList
          .whereType<Map>()
          .map((r) => r.cast<String, dynamic>())
          .toList(),
      breakdownRows: rows is List
          ? rows.whereType<Map>().map((r) => r.cast<String, dynamic>()).toList()
          : const <Map<String, dynamic>>[],
      breakdownGroup: _str(bdMap['group']),
      aging: agingList
          .whereType<Map>()
          .map((r) => r.cast<String, dynamic>())
          .toList(),
      recent: recentList
          .whereType<Map>()
          .map((r) => r.cast<String, dynamic>())
          .toList(),
      scopeName: _str(scopeMap['province_name']),
    );
  }

  final String asOf;
  final int? feeYear;
  final List<int> years;
  final Kpis kpis;
  final AllYearsData allYears;
  final List<Map<String, dynamic>> receipts;
  final List<Map<String, dynamic>> breakdownRows;
  final String breakdownGroup;
  final List<Map<String, dynamic>> aging;
  final List<Map<String, dynamic>> recent;
  final String scopeName;
}

class Kpis {
  const Kpis({
    required this.obligations,
    required this.paidObligations,
    required this.openObligations,
    required this.overdueObligations,
    required this.assessedKip,
    required this.collectedKip,
    required this.taxCollectedKip,
    required this.outstandingKip,
    required this.overdueKip,
    required this.collectionRate,
  });

  factory Kpis.fromJson(Map<String, dynamic> json) => Kpis(
    obligations: _int(json['obligations']),
    paidObligations: _int(json['paid_obligations']),
    openObligations: _int(json['open_obligations']),
    overdueObligations: _int(json['overdue_obligations']),
    assessedKip: _num(json['assessed_kip']),
    collectedKip: _num(json['collected_kip']),
    taxCollectedKip: _num(json['tax_collected_kip']),
    outstandingKip: _num(json['outstanding_kip']),
    overdueKip: _num(json['overdue_kip']),
    collectionRate: _num(json['collection_rate']),
  );

  final int obligations;
  final int paidObligations;
  final int openObligations;
  final int overdueObligations;
  final double assessedKip;
  final double collectedKip;
  final double taxCollectedKip;
  final double outstandingKip;
  final double overdueKip;
  final double collectionRate;
}

class AllYearsData {
  const AllYearsData({
    required this.openObligations,
    required this.overdueObligations,
    required this.outstandingKip,
    required this.overdueKip,
  });

  factory AllYearsData.fromJson(Map<String, dynamic> json) => AllYearsData(
    openObligations: _int(json['open_obligations']),
    overdueObligations: _int(json['overdue_obligations']),
    outstandingKip: _num(json['outstanding_kip']),
    overdueKip: _num(json['overdue_kip']),
  );

  final int openObligations;
  final int overdueObligations;
  final double outstandingKip;
  final double overdueKip;
}

int _int(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;
double _num(Object? v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
String _str(Object? v) => v?.toString() ?? '';
