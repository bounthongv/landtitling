// Smoke test for the citizen app's API contract.
//
// Runs the exact calls the app makes, in order, against a running Land Fee
// API, and checks the answers. It uses the app's own ApiClient, so a change
// in either the app or the API that breaks the contract fails here.
//
// The parcel is identified the way the district registry identifies it:
// province -> district -> village -> map sheet -> parcel number. Looking it up
// does not link it to the account; the citizen adds it with an explicit claim.
// The payment path is ISSUED -> SUBMITTED (receipt uploaded) -> PAID (officer
// verified).
//
//   dart run tool/smoke_test.dart [apiBase]
//
// Needs the fixture applied first (tool/fixtures/seed_test_data.sql), and a
// fresh copy of it before every run: this run claims the fixture parcel and
// settles its obligation.
//
// The citizen API the app needs (citizen_auth.php, parcel_fee.php,
// citizen_claim.php, citizen_payments.php) is specified in
// docs/9-citizen-app-api-contract.md and may not be deployed yet. If the
// server does not have it, the test says so and stops instead of failing in a
// confusing way.

import 'dart:io';

import 'package:landfee_citizen/core/api_client.dart';
import 'package:landfee_citizen/core/api_exception.dart';
import 'package:landfee_citizen/models/payment.dart';
import 'package:landfee_citizen/models/quote.dart';

// The fixture (tool/fixtures/seed_test_data.sql) puts the parcel in district 1,
// village 1, and records the owner's phone as the fixture citizen's number.
const _districtId = 1;
const _villageId = 1;
const _mapRefNo = 'A250-038';
const _parcelNo = '1301.001.001.0001';
const _feeYear = 2026;
const _citizenPhone = '020 999 9999';
const _otherPhone = '020 777 8888';

int _passed = 0;
int _failed = 0;

void check(String label, bool ok, [String? detail]) {
  if (ok) {
    _passed++;
    stdout.writeln('  PASS  $label${detail == null ? '' : '  ($detail)'}');
  } else {
    _failed++;
    stdout.writeln('  FAIL  $label${detail == null ? '' : '  ($detail)'}');
  }
}

/// The rows of a list response (`{"items": [...]}` after envelope unwrapping).
List<Map<String, dynamic>> _rows(Map<String, dynamic> data) =>
    (data['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

/// The five identity fields printed on the title, as the app sends them.
Map<String, String> _identity(int provinceId) => <String, String>{
  'province_id': '$provinceId',
  'district_id': '$_districtId',
  'village_id': '$_villageId',
  'map_ref_no': _mapRefNo,
  'parcel_no': _parcelNo,
};

Future<void> main(List<String> args) async {
  final base = args.isNotEmpty ? args[0] : 'http://localhost:8091/api/v1';
  final app = ApiClient(baseUrl: base);
  final officer = ApiClient(baseUrl: base);

  stdout.writeln('Land Fee citizen app — API smoke test');
  stdout.writeln('API: $base\n');

  // ---- 0. Is the citizen API deployed? ------------------------------------
  // Probe with a dedicated number: this call creates the account it names, so
  // it must not be the account the sign-in assertions below are about.
  try {
    await app.postData('citizen_auth.php', {
      'action': 'phone/signin',
      'phone': '020 999 0000',
    });
  } on ApiException catch (e) {
    stdout.writeln(
      '  SKIP  the citizen API is not on this server yet: ${e.message}',
    );
    stdout.writeln(
      '        (citizen_auth.php, parcel_fee.php, citizen_claim.php,',
    );
    stdout.writeln(
      '         citizen_payments.php — see docs/9-citizen-app-api-contract.md)',
    );
    exit(2);
  }

  // ---- 1. Sign in with a phone number ------------------------------------
  // A number that is new on every run, so "created on first use" is testable
  // and the test can be re-run without re-seeding.
  final stamp = DateTime.now().millisecondsSinceEpoch % 10000000;
  final testPhone = '020 ${stamp.toString().padLeft(7, '0')}';

  final signedIn = await app.postData('citizen_auth.php', {
    'action': 'phone/signin',
    'phone': testPhone,
  });
  final token = signedIn['token'];
  check('phone/signin returns a JWT', token != null);
  check(
    'the account is created on first use',
    signedIn['created'] == true,
    signedIn['created']?.toString(),
  );
  app.token = token.toString();

  // The same number, written differently, signs in to the same account.
  final again = await app.postData('citizen_auth.php', {
    'action': 'phone/signin',
    'phone': '+856 ${testPhone.substring(1)}',
  });
  check('the same number signs in again', again['token'] != null);
  check('the account is not created twice', again['created'] == false);

  var badPhone = false;
  try {
    await app.postData('citizen_auth.php', {
      'action': 'phone/signin',
      'phone': '123',
    });
  } on ApiException catch (e) {
    badPhone = e.code == 'invalid_phone';
  }
  check('an unusable phone number is refused', badPhone);

  // ---- 2. The location cascade the app shows ------------------------------
  final provinces = _rows(
    await app.getData('refdata.php', query: {'table': 'provinces'}),
  );
  check(
    'the provinces load for the location picker',
    provinces.isNotEmpty,
    '${provinces.length} province(s)',
  );

  final fixtureDistrict = _rows(await app.getData('districts.php'))
      .where((d) => (d['id'] as num?)?.toInt() == _districtId)
      .firstOrNull;
  check(
    'the fixture district is in the district list',
    fixtureDistrict != null,
    fixtureDistrict?['name_lo']?.toString(),
  );
  final provinceId = (fixtureDistrict?['province_id'] as num?)?.toInt() ?? 0;
  check(
    'the district belongs to a province in the picker',
    provinces.any((p) => (p['id'] as num?)?.toInt() == provinceId),
  );
  check(
    'choosing a province lists its districts',
    _rows(
      await app.getData('districts.php', query: {'province_id': '$provinceId'}),
    ).any((d) => (d['id'] as num?)?.toInt() == _districtId),
  );
  final villages = _rows(
    await app.getData(
      'villages.php',
      query: {'district_id': '$_districtId'},
    ),
  );
  check(
    'choosing a district lists its villages',
    villages.any((v) => (v['id'] as num?)?.toInt() == _villageId),
    '${villages.length} village(s)',
  );

  // ---- 3. Price the parcel by its five identity fields --------------------
  // The location is required: a number can repeat in another district/village,
  // and the lookup must never resolve a different parcel.
  var locationRequired = false;
  try {
    await app.getData(
      'parcel_fee.php',
      query: {'map_ref_no': _mapRefNo, 'parcel_no': _parcelNo},
    );
  } on ApiException catch (e) {
    locationRequired = e.code == 'missing_location';
  }
  check('a lookup without the location is refused', locationRequired);

  var sheetRequiredForQuote = false;
  try {
    await app.getData(
      'parcel_fee.php',
      query: {
        'province_id': '$provinceId',
        'district_id': '$_districtId',
        'village_id': '$_villageId',
        'parcel_no': _parcelNo,
      },
    );
  } on ApiException catch (e) {
    sheetRequiredForQuote = e.code == 'missing_map_sheet';
  }
  check('a lookup without the map sheet is refused', sheetRequiredForQuote);

  final quoteData = await app.getData(
    'parcel_fee.php',
    query: {..._identity(provinceId), 'fee_year': '$_feeYear'},
  );
  final quote = ParcelQuote.fromJson(quoteData);
  check(
    'the parcel is found by its number',
    quote.parcel.parcelNo == _parcelNo,
    quote.parcel.parcelNo,
  );
  check(
    'the parcel is identified by its map sheet',
    quote.parcel.mapRefNo == _mapRefNo,
    quote.parcel.mapRefNo,
  );
  check(
    'the parcel carries its zone code',
    (quote.parcel.zoneCode ?? '').isNotEmpty,
    quote.parcel.zoneCode,
  );
  check('the rate comes from the zone', quote.rateFound, '${quote.ratePerSqm}');
  check(
    'the amount is rate x area',
    quote.amountDue == quote.ratePerSqm * quote.parcel.areaSqm,
    '${quote.amountDue}',
  );

  // ---- 4. Claim: the lookup alone links nothing ---------------------------
  // The fixture citizen is the owner's phone number. The owner starts unlinked;
  // the claim is the explicit "add to my parcels" step.
  final claimant = ApiClient(baseUrl: base);
  final claimantIn = await claimant.postData('citizen_auth.php', {
    'action': 'phone/signin',
    'phone': _citizenPhone,
  });
  claimant.token = claimantIn['token'].toString();
  check('the fixture citizen signs in', claimant.token != null);
  check(
    'the fixture account already exists',
    claimantIn['created'] == false,
    claimantIn['user']?.toString(),
  );

  final beforeClaim = await claimant.getData(
    'parcel_fee.php',
    query: {..._identity(provinceId), 'fee_year': '$_feeYear'},
  );
  check('looking the parcel up does not link it', beforeClaim['claimed'] == false);

  final claim = await claimant.postData('citizen_claim.php', {
    'parcel_id': quote.parcel.id,
  });
  check(
    'the claim links the parcel to the citizen',
    claim['claimed'] == true,
    claim['owner_id']?.toString(),
  );

  final afterClaim = await claimant.getData(
    'parcel_fee.php',
    query: {..._identity(provinceId), 'fee_year': '$_feeYear'},
  );
  check('the parcel now reads as linked', afterClaim['claimed'] == true);

  final myParcels = (await claimant.getData('my_parcels.php'))['parcels'];
  final myParcelRows = (myParcels as List? ?? const [])
      .whereType<Map<String, dynamic>>();
  check(
    'the claimed parcel appears in my parcels',
    myParcelRows.any(
      (p) => (p['parcel_id'] as num?)?.toInt() == quote.parcel.id,
    ),
    '${myParcelRows.length} parcel(s)',
  );

  final other = ApiClient(baseUrl: base);
  final otherSignedIn = await other.postData('citizen_auth.php', {
    'action': 'phone/signin',
    'phone': _otherPhone,
  });
  other.token = otherSignedIn['token'].toString();

  var takenByOther = false;
  try {
    await other.postData('citizen_claim.php', {'parcel_id': quote.parcel.id});
  } on ApiException catch (e) {
    takenByOther = e.code == 'claimed_by_other';
  }
  check('another citizen cannot claim a linked parcel', takenByOther);

  // ---- 5. Issue a payment slip -------------------------------------------
  // The app issues by the id the lookup returned: a number that repeats on
  // another sheet can then never settle the wrong parcel. Anyone may pay, so
  // the payer here is the signed-in test account, not the owner.
  var sheetRequired = false;
  try {
    await app.postData('citizen_payments.php', {
      'parcel_no': _parcelNo,
      'fee_year': _feeYear,
      'method': 'bank_transfer',
    });
  } on ApiException catch (e) {
    sheetRequired = e.code == 'missing_map_sheet';
  }
  check('a payment without the map sheet is refused', sheetRequired);

  final issued = await app.postData('citizen_payments.php', {
    'parcel_id': quote.parcel.id,
    'fee_year': _feeYear,
    'method': 'bank_transfer',
  });
  final slipId = (issued['id'] as num?)?.toInt() ?? 0;
  final reference = issued['reference_no']?.toString() ?? '';
  check('a slip reference is issued', reference.isNotEmpty, reference);
  check(
    'the reference follows LFE-{year}-{seq6}',
    RegExp(r'^LFE-\d{4}-\d{6}$').hasMatch(reference),
    reference,
  );
  check(
    'the slip carries a QR payload',
    (issued['slip_qr']?.toString() ?? '').isNotEmpty,
  );

  // ---- 6. Upload the receipt ---------------------------------------------
  final receipt = File('tool/fixtures/receipt.png');
  if (!receipt.existsSync()) {
    stdout.writeln(
      '  SKIP  receipt upload (tool/fixtures/receipt.png is missing)',
    );
  } else {
    final uploaded = await app.postMultipart(
      'citizen_payments.php',
      'slip',
      receipt.path,
      'receipt.png',
      mimeType: 'image/png',
      query: {'id': '$slipId', 'receipt': '1'},
    );
    check(
      'the receipt upload returns the slip path',
      (uploaded['slip_path'] as String? ?? '').isNotEmpty,
      uploaded['slip_path'],
    );
    check(
      'the receipt upload marks the payment SUBMITTED',
      uploaded['status'] == 'SUBMITTED',
      uploaded['status']?.toString(),
    );
  }

  // ---- 7. The payment shows up in the histories ---------------------------
  List<Payment> historyOf(Map<String, dynamic> data) =>
      ((data['payments'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(Payment.fromJson)
          .toList();

  final payments = historyOf(await app.getData('citizen_payments.php'));
  final mine = payments.where((p) => p.id == slipId).firstOrNull;
  check(
    'the payment is in the payer history',
    mine != null,
    '${payments.length} payment(s)',
  );
  check('the payment shows the receipt', mine != null && mine.hasReceipt);
  check(
    'the payment is waiting for the district',
    mine?.isSubmitted == true,
    mine?.status,
  );
  check('the payment shows the reference', mine?.referenceNo == reference);

  final ownerPayments = historyOf(
    await claimant.getData('citizen_payments.php'),
  );
  check(
    'the owner sees the payment made on their land',
    ownerPayments.any((p) => p.referenceNo == reference),
  );

  final otherPayments = historyOf(await other.getData('citizen_payments.php'));
  check(
    'a different citizen sees none of these payments',
    !otherPayments.any((p) => p.referenceNo == reference),
    '${otherPayments.length} payment(s)',
  );

  // ---- 8. Close the loop: the officer verifies the SUBMITTED payment -----
  final officerLogin = await officer.postData('auth.php', {
    'action': 'login',
    'username': 'sav_officer',
    'password': 'demo1234',
  });
  officer.token = officerLogin['token'].toString();
  final verifiedPayment = await officer.postData(
    'payments.php',
    {'note': 'smoke test'},
    query: {'id': '$slipId', 'verify': '1'},
  );
  check(
    'the officer verifies the payment',
    (verifiedPayment['status'] as String?) == 'PAID',
    verifiedPayment['status']?.toString(),
  );

  final afterPayments = historyOf(await app.getData('citizen_payments.php'));
  check(
    'the citizen sees the payment as paid',
    afterPayments.any((p) => p.id == slipId && p.isPaid),
  );

  stdout.writeln('\n$_passed passed, $_failed failed');
  exit(_failed == 0 ? 0 : 1);
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
