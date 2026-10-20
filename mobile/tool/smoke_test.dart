// Smoke test for the citizen app's API contract.
//
// Runs the exact calls the app makes, in order, against a running Land Fee
// API, and checks the answers. It uses the app's own ApiClient, so a change
// in either the app or the API that breaks the contract fails here.
//
//   dart run tool/smoke_test.dart [apiBase]
//
// The server must run with OTP_DEBUG=1 so the code comes back in the
// response (the smoke test cannot read anyone's inbox).

import 'dart:io';

import 'package:landfee_citizen/core/api_client.dart';
import 'package:landfee_citizen/core/api_exception.dart';
import 'package:landfee_citizen/models/obligation.dart';
import 'package:landfee_citizen/models/parcel.dart';
import 'package:landfee_citizen/models/payment.dart';

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

  // ---- 1. Request the OTP -------------------------------------------------
  Map<String, dynamic> otpRequest;
  try {
    otpRequest = await app.postData('auth.php', {
      'action': 'otp/request',
      'email': 'citizen@test.la',
    });
  } on ApiException catch (e) {
    stdout.writeln('  FAIL  otp/request — ${e.message}');
    exit(1);
  }
  check('otp/request returns an otp_token', otpRequest['otp_token'] != null);
  final code = otpRequest['debug_code'];
  check(
    'otp/request returns a debug code (OTP_DEBUG must be on)',
    code != null,
    code?.toString(),
  );

  // ---- 2. Verify the OTP --------------------------------------------------
  final verified = await app.postData('auth.php', {
    'action': 'otp/verify',
    'email': 'citizen@test.la',
    'code': code.toString(),
    'otp_token': otpRequest['otp_token'].toString(),
  });
  final token = verified['token'];
  check('otp/verify returns a JWT', token != null);
  app.token = token.toString();

  // ---- 3. The citizen's parcels ------------------------------------------
  final parcelsData = await app.getData('my_parcels.php');
  final rawParcels = parcelsData['parcels'] as List? ?? const [];
  final parcels = rawParcels
      .whereType<Map<String, dynamic>>()
      .map(Parcel.fromJson)
      .toList();
  check(
    'my_parcels returns the linked parcel',
    parcels.length == 1,
    '${parcels.length} parcel(s)',
  );
  if (parcels.isEmpty) {
    stdout.writeln('\n$_failed failed, $_passed passed');
    exit(1);
  }
  final parcel = parcels.first;
  check(
    'parcel carries the parcel number',
    parcel.parcelNo.isNotEmpty,
    parcel.parcelNo,
  );
  check(
    'parcel carries the area',
    parcel.areaSqm == 1250.50,
    '${parcel.areaSqm}',
  );
  check(
    'parcel carries the owner',
    (parcel.ownerName ?? '').isNotEmpty,
    parcel.ownerName,
  );
  check(
    'parcel has obligations for two years',
    parcel.obligations.length == 2,
    '${parcel.obligations.length}',
  );

  final obligation2026 = parcel.obligations
      .where((o) => o.feeYear == 2026)
      .cast<Obligation?>()
      .firstWhere((o) => o != null, orElse: () => null);
  check('the 2026 obligation is present', obligation2026 != null);
  check(
    'the 2026 amount due is 175,070,000',
    obligation2026 != null && obligation2026.amountDue == 175070000.0,
    obligation2026 == null ? 'missing' : '${obligation2026.amountDue}',
  );
  check(
    'the 2026 obligation is unpaid',
    obligation2026 != null && !obligation2026.isSettled,
  );
  check(
    'the 2025 obligation is settled',
    parcel.obligations.any((o) => o.feeYear == 2025 && o.isSettled),
  );

  // ---- 4. Issue a payment slip -------------------------------------------
  final issued = await app.postData('payments.php', {
    'obligation_id': obligation2026!.id,
    'method': 'bank_transfer',
  });
  final slip = IssuedSlip.fromJson(issued);
  check(
    'a slip reference is issued',
    slip.referenceNo.isNotEmpty,
    slip.referenceNo,
  );
  check(
    'the reference follows LFE-{year}-{seq6}',
    RegExp(r'^LFE-\d{4}-\d{6}$').hasMatch(slip.referenceNo),
    slip.referenceNo,
  );
  check(
    'the slip amount is the outstanding balance',
    slip.amount == 175070000.0,
    '${slip.amount}',
  );
  check('the slip carries a QR payload', slip.slipQr.isNotEmpty);
  check(
    'the slip is in the ISSUED state',
    slip.status == 'ISSUED',
    slip.status,
  );

  // ---- 5. Upload the receipt ---------------------------------------------
  final receipt = File('tool/fixtures/receipt.png');
  if (!receipt.existsSync()) {
    stdout.writeln(
      '  SKIP  receipt upload (tool/fixtures/receipt.png is missing)',
    );
  } else {
    final uploaded = await app.postMultipart(
      'payments.php',
      'slip',
      receipt.path,
      'receipt.png',
      mimeType: 'image/png',
      query: {'id': slip.id.toString(), 'receipt': '1'},
    );
    check(
      'the receipt upload returns the slip path',
      (uploaded['slip_path'] as String? ?? '').isNotEmpty,
      uploaded['slip_path'],
    );
  }

  // ---- 6. The payment shows up in the history ----------------------------
  final paymentsData = await app.getData('payments.php');
  final rawPayments = paymentsData['payments'] as List? ?? const [];
  final payments = rawPayments
      .whereType<Map<String, dynamic>>()
      .map(Payment.fromJson)
      .toList();
  check(
    'the payment is in the history',
    payments.any((p) => p.id == slip.id),
    '${payments.length} payment(s)',
  );
  final mine = payments.where((p) => p.id == slip.id).firstOrNull;
  check('the payment shows the receipt', mine != null && mine.hasReceipt);

  // ---- 7. Another citizen must not see it --------------------------------
  final other = ApiClient(baseUrl: base);
  final otherOtp = await other.postData('auth.php', {
    'action': 'otp/request',
    'email': 'stranger@test.la',
  });
  final otherVerified = await other.postData('auth.php', {
    'action': 'otp/verify',
    'email': 'stranger@test.la',
    'code': otherOtp['debug_code'].toString(),
    'otp_token': otherOtp['otp_token'].toString(),
  });
  other.token = otherVerified['token'].toString();
  final otherParcels = await other.getData('my_parcels.php');
  check(
    'a different citizen sees no parcels',
    ((otherParcels['parcels'] as List?) ?? const []).isEmpty,
  );
  // The list is scoped, not refused: a stranger gets their own (empty) list.
  final otherPayments = await other.getData('payments.php');
  final otherList = (otherPayments['payments'] as List?) ?? const [];
  check(
    'a different citizen sees none of these payments',
    !otherList.whereType<Map<String, dynamic>>().any(
      (p) => p['reference_no'] == slip.referenceNo,
    ),
    '${otherList.length} payment(s)',
  );
  var forbidden = false;
  try {
    await other.getData(
      'payments.php',
      query: {'id': slip.id.toString(), 'slip': '1'},
    );
  } on ApiException catch (e) {
    forbidden = e.statusCode == 403;
  }
  check('a different citizen cannot open the slip', forbidden);

  // ---- 8. Close the loop: the officer verifies the payment ---------------
  final officerLogin = await officer.postData('auth.php', {
    'action': 'login',
    'username': 'sav_officer',
    'password': 'demo1234',
  });
  officer.token = officerLogin['token'].toString();
  final verifiedPayment = await officer.postData(
    'payments.php',
    {'note': 'smoke test'},
    query: {'id': slip.id.toString(), 'verify': '1'},
  );
  check(
    'the officer verifies the payment',
    (verifiedPayment['status'] as String?) == 'PAID',
    verifiedPayment['status']?.toString(),
  );

  final after = await app.getData('my_parcels.php');
  final afterParcels = ((after['parcels'] as List?) ?? const [])
      .whereType<Map<String, dynamic>>()
      .map(Parcel.fromJson)
      .toList();
  final settled = afterParcels.isEmpty
      ? false
      : afterParcels.first.obligations.any(
          (o) => o.feeYear == 2026 && o.isSettled,
        );
  check('the citizen now sees the 2026 fee as settled', settled);

  stdout.writeln('\n$_passed passed, $_failed failed');
  exit(_failed == 0 ? 0 : 1);
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
