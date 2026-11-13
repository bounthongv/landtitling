/// The reports payload (`reports.php`). Every report returns the same shape:
/// `columns`, `rows`, optional `summary` and optional `group_by`.
class ReportData {
  const ReportData({
    required this.columns,
    required this.rows,
    required this.summary,
  });

  factory ReportData.fromJson(Map<String, dynamic> json) {
    final columns = json['columns'];
    final rows = json['rows'];
    final summary = json['summary'];
    return ReportData(
      columns: columns is List ? columns.map((c) => '$c').toList() : const [],
      rows: rows is List
          ? rows.whereType<Map>().map((r) => r.cast<String, dynamic>()).toList()
          : const <Map<String, dynamic>>[],
      summary: summary is Map<String, dynamic>
          ? summary
          : const <String, dynamic>{},
    );
  }

  final List<String> columns;
  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> summary;

  /// Pull a column's value out of a row, tolerating missing keys.
  static String valueOf(Map<String, dynamic> row, String column) {
    final v = row[column];
    if (v == null) return '';
    if (v is num) {
      return v == v.roundToDouble() ? _formatInt(v.toInt()) : v.toString();
    }
    return '$v';
  }

  static String _formatInt(int v) {
    var s = v.toString();
    final out = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      final fromEnd = s.length - i;
      if (i > 0 && fromEnd % 3 == 0) out.write(',');
      out.write(s[i]);
    }
    return out.toString();
  }
}
