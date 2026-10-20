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

/// Area in square metres: `1,250.5 ມາຄ` (or `m²` in English).
String formatArea(num? sqm, {String unit = 'ມາຄ'}) {
  final v = (sqm ?? 0).toDouble();
  final text = v == v.roundToDouble()
      ? NumberFormat.decimalPattern('en_US').format(v.toInt())
      : NumberFormat('#,##0.##', 'en_US').format(v);
  return '$text $unit';
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

/// Days remaining until [dueDate]; negative when overdue.
int? daysUntil(String? dueDate) {
  if (dueDate == null || dueDate.isEmpty) return null;
  final parsed = DateTime.tryParse(dueDate);
  if (parsed == null) return null;
  final today = DateTime.now();
  final due = DateTime(parsed.year, parsed.month, parsed.day);
  final now = DateTime(today.year, today.month, today.day);
  return due.difference(now).inDays;
}
