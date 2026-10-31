import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/strings.dart';
import '../models/quote.dart';
import '../models/parcel.dart';

/// The citizen's own parcels (those linked to their account) and the lookup of
/// any parcel by its province → district → village → map sheet → parcel number.
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

  /// The explicit "add to my parcels" step after a lookup.
  bool claiming = false;
  String? claimError;

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

  /// Price one parcel using the five identity fields printed on the title.
  ///
  /// The server reads the area and the zone code from the parcel and works
  /// out the rate, so the amount is never computed on the phone.
  Future<ParcelQuote?> lookup(
    String parcelNo, {
    required int provinceId,
    required int districtId,
    required int villageId,
    required String mapRefNo,
    int? feeYear,
  }) {
    final query = <String, String>{
      'parcel_no': parcelNo.trim(),
      'province_id': '$provinceId',
      'district_id': '$districtId',
      'village_id': '$villageId',
      'map_ref_no': mapRefNo.trim(),
    };
    return _fetchQuote(query, feeYear);
  }

  /// Price a parcel the citizen already owns, by the id the server gave us.
  Future<ParcelQuote?> lookupById(int parcelId, {int? feeYear}) =>
      _fetchQuote(<String, String>{'parcel_id': '$parcelId'}, feeYear);

  Future<ParcelQuote?> _fetchQuote(
    Map<String, String> query,
    int? feeYear,
  ) async {
    lookingUp = true;
    lookupError = null;
    notifyListeners();
    try {
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
    claimError = null;
    notifyListeners();
  }

  /// Link the looked-up parcel to the signed-in account ("add to my parcels").
  ///
  /// Looking a parcel up never links it; this is the explicit step. The server
  /// refuses a parcel linked to another account or whose owner phone is not the
  /// account phone, and audits every attempt.
  Future<bool> claim() async {
    final current = quote;
    if (current == null || current.claimed) return false;
    claiming = true;
    claimError = null;
    notifyListeners();
    try {
      await _api.postData('citizen_claim.php', {
        'parcel_id': current.parcel.id,
      });
      quote = current.copyWith(claimed: true);
      await load(silent: true);
      return true;
    } on ApiException catch (e) {
      claimError = switch (e.code) {
        'phone_mismatch' => Strings.t('claimPhoneMismatch'),
        'claimed_by_other' => Strings.t('claimOwnedByOther'),
        'no_owner' => Strings.t('claimNoOwner'),
        _ => e.message,
      };
      return false;
    } finally {
      claiming = false;
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
