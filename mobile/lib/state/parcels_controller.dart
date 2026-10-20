import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../models/quote.dart';
import '../models/parcel.dart';

/// The citizen's own parcels (those an officer has linked to their account)
/// and the lookup of any parcel by its number.
class ParcelsController extends ChangeNotifier {
  ParcelsController({required ApiClient api}) : _api = api;

  final ApiClient _api;

  List<Parcel> parcels = const <Parcel>[];
  bool loading = false;
  String? error;

  /// The year the app is showing. Defaults to the newest year on file.
  int? selectedYear;

  /// The result of the last parcel lookup.
  ParcelQuote? quote;
  bool lookingUp = false;
  String? lookupError;

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

  /// Price one parcel by its number.
  ///
  /// The server reads the area and the zone code from the parcel and works
  /// out the rate, so the amount is never computed on the phone.
  Future<ParcelQuote?> lookup(String parcelNo, {int? feeYear}) async {
    lookingUp = true;
    lookupError = null;
    notifyListeners();
    try {
      final query = <String, String>{'parcel_no': parcelNo.trim()};
      if (feeYear != null) query['fee_year'] = '$feeYear';
      final data = await _api.getData('parcel_fee.php', query: query);
      final result = ParcelQuote.fromJson(data);
      quote = result;
      lookingUp = false;
      notifyListeners();
      return result;
    } on ApiException catch (e) {
      lookingUp = false;
      lookupError = e.message;
      quote = null;
      notifyListeners();
      return null;
    }
  }

  void clearQuote() {
    quote = null;
    lookupError = null;
    notifyListeners();
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
