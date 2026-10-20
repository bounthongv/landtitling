import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/parcel.dart';

/// The signed-in citizen's parcels and their annual obligations.
class ParcelsController extends ChangeNotifier {
  ParcelsController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  List<Parcel> parcels = const <Parcel>[];
  bool loading = false;
  String? error;

  /// The year the app is showing. Defaults to the newest year on file.
  int? selectedYear;

  Future<void> load({bool silent = false}) async {
    if (!silent) {
      loading = true;
      error = null;
      notifyListeners();
    }
    try {
      final data = await _api.getData('my_parcels.php');
      final raw = data['parcels'];
      parcels = raw is List
          ? raw.whereType<Map<String, dynamic>>().map(Parcel.fromJson).toList()
          : const <Parcel>[];
      if (selectedYear == null || !years.contains(selectedYear)) {
        selectedYear = years.isEmpty ? null : years.first;
      }
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Every year that appears on any of the citizen's parcels, newest first.
  List<int> get years {
    final set = <int>{};
    for (final p in parcels) {
      for (final o in p.obligations) {
        set.add(o.feeYear);
      }
    }
    final list = set.toList()..sort((a, b) => b.compareTo(a));
    return list;
  }

  /// Parcels that have something to pay in the selected year.
  List<Parcel> get parcelsForYear {
    final year = selectedYear;
    if (year == null) return parcels;
    return parcels
        .where((p) => p.obligations.any((o) => o.feeYear == year))
        .toList();
  }

  /// Everything the citizen owes in the selected year.
  double get totalOutstanding {
    final year = selectedYear;
    if (year == null) return 0;
    var sum = 0.0;
    for (final p in parcels) {
      for (final o in p.obligations) {
        if (o.feeYear == year && !o.isSettled) sum += o.outstanding;
      }
    }
    return sum;
  }

  void selectYear(int? year) {
    selectedYear = year;
    notifyListeners();
  }
}
