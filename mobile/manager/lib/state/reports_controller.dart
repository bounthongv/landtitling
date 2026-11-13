import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/report_data.dart';

/// One of the reports the manager app offers (Collection / MOF / arrears).
class ReportOption {
  const ReportOption(this.key, this.labelLo, this.labelEn);

  final String key;
  final String labelLo;
  final String labelEn;
}

const List<ReportOption> kReportOptions = [
  ReportOption('collection', 'ລາຍຮັບທີ່ເກັບໄດ້', 'Collection'),
  ReportOption('mof', 'ລາຍງານກະຊວງການເງິນ', 'MOF export'),
  ReportOption('arrears', 'ໜີ້ຄ້າງ ແລະ ອາຍຸໜີ້', 'Arrears & aging'),
];

/// Loads and holds the selected report.
class ReportsController extends ChangeNotifier {
  ReportsController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  ReportOption? selected;
  ReportData? data;
  String? error;
  bool busy = false;

  // Filters.
  int? provinceId;
  int? districtId;
  int? feeYear;
  String roadCategory = '';
  String from = '';
  String to = '';

  Future<void> select(ReportOption option) async {
    selected = option;
    data = null;
    error = null;
    await load();
  }

  Future<void> load() async {
    if (selected == null) return;
    busy = true;
    error = null;
    notifyListeners();
    try {
      final query = <String, String>{
        'report': selected!.key,
        'format': 'json',
        if (provinceId != null) 'province_id': '$provinceId',
        if (districtId != null) 'district_id': '$districtId',
        if (feeYear != null) 'fee_year': '$feeYear',
        if (roadCategory.isNotEmpty) 'road_category': roadCategory,
        if (from.isNotEmpty) 'from': from,
        if (to.isNotEmpty) 'to': to,
      };
      final json = await _api.getData('reports.php', query: query);
      data = ReportData.fromJson(json);
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<String> downloadCsv() async {
    if (selected == null) return '';
    final query = <String, String>{
      'report': selected!.key,
      'format': 'csv',
      if (provinceId != null) 'province_id': '$provinceId',
      if (districtId != null) 'district_id': '$districtId',
      if (feeYear != null) 'fee_year': '$feeYear',
      if (roadCategory.isNotEmpty) 'road_category': roadCategory,
      if (from.isNotEmpty) 'from': from,
      if (to.isNotEmpty) 'to': to,
    };
    return _api.getText('reports.php', query: query);
  }
}
