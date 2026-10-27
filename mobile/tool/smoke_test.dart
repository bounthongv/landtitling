// Smoke test for the citizen app's API contract.
//
// Runs the exact calls the app makes, in order, against a running Land Fee
// API, and checks the answers. It uses the app's own ApiClient, so a change
// in either the app or the API that breaks the contract fails here.
//
//   dart run tool/smoke_test.dart [apiBase]
//
// The citizen API the app needs (citizen_auth.php, parcel_fee.php,
// citizen_payments.php) is specified in docs/9-citizen-app-api-contract.md
// and may not be deployed yet. If the server does not have it, the test says
// so and stops instead of failing in a confusing way.

import 'dart:io';

import 'package:landfee_citizen/core/api_client.dart';
import 'package:landfee_citizen/core/api_exception.dart';
import 'package:landfee_citizen/models/payment.dart';
import 'package:landfee_citizen/models/quote.dart';

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
      '        (citizen_auth.php, parcel_fee.php, citizen_payments.php',
    );
    stdout.writeln(
      '         are specified in docs/9-citizen-app-api-contract.md)',
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

  // ---- 2. Price a parcel by its number -----------------------------------
  const parcelNo = '1301.001.001.0001';
  final quoteData = await app.getData(
    'parcel_fee.php',
    query: {'parcel_no': parcelNo, 'fee_year': '2026'},
  );
  final quote = ParcelQuote.fromJson(quoteData);
  check(
    'the parcel is found by its number',
    quote.parcel.parcelNo == parcelNo,
    quote.parcel.parcelNo,
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

  // ---- 3. Issue a payment slip -------------------------------------------
  final issued = await app.postData('citizen_payments.php', {
    'parcel_no': parcelNo,
    'fee_year': 2026,
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

  // ---- 4. Upload the receipt ---------------------------------------------
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
  }

  // ---- 5. The payment shows up in the citizen's history -------------------
  final paymentsData = await app.getData('citizen_payments.php');
  final rawPayments = paymentsData['payments'] as List? ?? const [];
  final payments = rawPayments
      .whereType<Map<String, dynamic>>()
      .map(Payment.fromJson)
      .toList();
  final mine = payments.where((p) => p.id == slipId).firstOrNull;
  check(
    'the payment is in the history',
    mine != null,
    '${payments.length} payment(s)',
  );
  check('the payment shows the receipt', mine != null && mine.hasReceipt);
  check('the payment shows the reference', mine?.referenceNo == reference);

  // ---- 6. Another citizen must not see it --------------------------------
  final other = ApiClient(baseUrl: base);
  final otherSignedIn = await other.postData('citizen_auth.php', {
    'action': 'phone/signin',
    'phone': '020 777 8888',
  });
  other.token = otherSignedIn['token'].toString();
  final otherPayments = await other.getData('citizen_payments.php');
  final otherList = (otherPayments['payments'] as List?) ?? const [];
  check(
    'a different citizen sees none of these payments',
    !otherList.whereType<Map<String, dynamic>>().any(
      (p) => p['reference_no'] == reference,
    ),
    '${otherList.length} payment(s)',
  );

  // ---- 7. Close the loop: the officer verifies the payment ---------------
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

  final after = await app.getData('citizen_payments.php');
  final afterPayments = ((after['payments'] as List?) ?? const [])
      .whereType<Map<String, dynamic>>()
      .map(Payment.fromJson)
      .toList();
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
