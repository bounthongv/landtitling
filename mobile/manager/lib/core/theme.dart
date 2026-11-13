import 'package:flutter/material.dart';

/// Design tokens shared with the back-office and the citizen app.
class GovColors {
  const GovColors._();

  static const gov = Color(0xFF0B3D2E);
  static const gov2 = Color(0xFF14644A);
  static const gold = Color(0xFFC9A227);
  static const bg = Color(0xFFF0F3F0);
  static const card = Color(0xFFFFFFFF);
  static const ink = Color(0xFF17231D);
  static const muted = Color(0xFF5C6B62);
  static const line = Color(0xFFDCE4DC);
  static const danger = Color(0xFFB3261E);
  static const ok = Color(0xFF1D7A4F);
  static const blue = Color(0xFF1A5FB4);
}

/// Lao first, then the Latin fallbacks. `Phetsarath OT` is the government face
/// and is present on most Lao handsets; `Noto Sans Lao` is the Android system
/// Lao face.
const List<String> kLaoFontFallback = <String>[
  'Phetsarath OT',
  'Noto Sans Lao',
  'Tahoma',
  'sans-serif',
];

ThemeData buildGovTheme() {
  final base = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: GovColors.gov,
      primary: GovColors.gov,
      secondary: GovColors.gold,
      surface: GovColors.card,
      error: GovColors.danger,
    ),
    scaffoldBackgroundColor: GovColors.bg,
  );

  return base.copyWith(
    textTheme: base.textTheme.apply(fontFamilyFallback: kLaoFontFallback),
    appBarTheme: const AppBarTheme(
      backgroundColor: GovColors.gov,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
    ),
    cardTheme: CardThemeData(
      color: GovColors.card,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: GovColors.line),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: GovColors.card,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: GovColors.line),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: GovColors.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: GovColors.gov, width: 1.6),
      ),
    ),
  );
}
