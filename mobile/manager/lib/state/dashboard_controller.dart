import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/dashboard.dart';

/// Loads and holds the overview dashboard.
class DashboardController extends ChangeNotifier {
  DashboardController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  DashboardData? data;
  String? error;
  bool busy = false;

  int? selectedYear;
  bool get hasCustomYear => selectedYear != null;

  Future<void> load({int? year, bool resetYear = false}) async {
    if (resetYear) selectedYear = null;
    if (year != null) selectedYear = year;
    busy = true;
    error = null;
    notifyListeners();
    try {
      final query = <String, String>{};
      if (selectedYear != null) query['fee_year'] = '$selectedYear';
      final json = await _api.getData('dashboard.php', query: query);
      data = DashboardData.fromJson(json);
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  /// Current dashboard year list, for the year picker.
  List<int> get years => data?.years ?? const [];
}
