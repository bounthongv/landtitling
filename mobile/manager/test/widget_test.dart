import 'package:flutter_test/flutter_test.dart';

import 'package:landfee_manager/core/money.dart';
import 'package:landfee_manager/core/strings.dart';
import 'package:landfee_manager/models/report_data.dart';

void main() {
  test('money formatting', () {
    expect(formatKip(1234567), '1,234,567 ກີບ');
    expect(formatKipPlain(0), '0');
    expect(formatPercent(42.5), '42.5%');
  });

  test('report cell rendering', () {
    expect(ReportData.valueOf({'district': 'A'}, 'district'), 'A');
    expect(
      ReportData.valueOf({'collected_kip': 1234.0}, 'collected_kip'),
      '1,234',
    );
    expect(ReportData.valueOf({'x': null}, 'x'), '');
  });

  test('strings default to Lao', () {
    Strings.setLanguage(AppLang.lo);
    expect(Strings.t('overview'), 'ພາບລວມ');
  });
}
