import 'package:intl/intl.dart';

/// Kip amounts are whole numbers in practice, so we never show decimals.
String formatKip(num? value) {
  final amount = (value ?? 0).round();
  final digits = NumberFormat.decimalPattern('en_US').format(amount);
  return '$digits ກີບ';
}

/// Kip without the currency word, for use inside a labelled row.
String formatKipPlain(num? value) =>
    NumberFormat.decimalPattern('en_US').format((value ?? 0).round());

/// A percentage with one decimal.
String formatPercent(num? value) {
  final v = (value ?? 0).toDouble();
  return '${NumberFormat('#,##0.#', 'en_US').format(v)}%';
}

/// `2026-03-31` -> `31/03/2026`. Passes through anything unparseable.
String formatDate(String? isoDate) {
  if (isoDate == null || isoDate.isEmpty) return '-';
  final parsed = DateTime.tryParse(isoDate);
  if (parsed == null) return isoDate;
  return '${parsed.day.toString().padLeft(2, '0')}/${parsed.month.toString().padLeft(2, '0')}/${parsed.year}';
}

/// `2026-03-31 14:05:00` -> `31/03/2026 14:05`.
String formatDateTime(String? isoDate) {
  if (isoDate == null || isoDate.isEmpty) return '-';
  final parsed = DateTime.tryParse(isoDate);
  if (parsed == null) return isoDate;
  final hh = parsed.hour.toString().padLeft(2, '0');
  final mm = parsed.minute.toString().padLeft(2, '0');
  return '${formatDate(isoDate)} $hh:$mm';
}

/// True when the cell value looks like a kip amount.
bool looksLikeKip(dynamic value) {
  if (value is num) {
    final v = value.toDouble();
    return v.abs() >= 1000 && (v % 1 == 0);
  }
  return false;
}

/// Report cells come back typed (numbers stay numbers). Render nicely.
String cellText(dynamic value) {
  if (value == null) return '';
  if (value is num) {
    final v = value.toDouble();
    if (v == v.roundToDouble()) {
      return NumberFormat.decimalPattern('en_US').format(v.toInt());
    }
    return NumberFormat('#,##0.##', 'en_US').format(v);
  }
  return value.toString();
}
