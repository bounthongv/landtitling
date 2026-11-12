import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Banks that ship a logo in `mobile/assets/banks/<code>.png`.
///
/// The file name is the bank code from `sql/migrations/017_banks.sql`, lower
/// cased. Adding a logo is dropping the file in and adding its code here; a
/// bank with no file falls back to its initials so the list stays tidy while
/// the rest are collected.
const Set<String> _banksWithLogo = {
  'acleda',
  'apb',
  'bcel',
  'boc',
  'hsbc',
  'jdb',
  'kbank',
  'krungsri',
  'ldb',
  'mjb',
  'maybank',
  'scb',
};

/// The paying bank's logo, or a monogram tile when none is on file.
class BankLogo extends StatelessWidget {
  const BankLogo({super.key, required this.code, this.size = 26});

  final String code;
  final double size;

  @override
  Widget build(BuildContext context) {
    final key = code.toLowerCase();
    final hasLogo = _banksWithLogo.contains(key);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(size * 0.2),
        border: Border.all(color: GovColors.line),
      ),
      clipBehavior: Clip.antiAlias,
      child: hasLogo
          ? Image.asset(
              'assets/banks/$key.png',
              fit: BoxFit.contain,
              filterQuality: FilterQuality.medium,
            )
          : _Monogram(code: code, size: size),
    );
  }
}

class _Monogram extends StatelessWidget {
  const _Monogram({required this.code, required this.size});

  final String code;
  final double size;

  static const _palette = <Color>[
    GovColors.gov,
    GovColors.blue,
    Color(0xFF8A5A00),
    Color(0xFF6A1B9A),
    Color(0xFF00695C),
    GovColors.danger,
    Color(0xFF37474F),
    Color(0xFF2E7D32),
  ];

  @override
  Widget build(BuildContext context) {
    final clean = code.replaceAll(RegExp('[^A-Za-z0-9]'), '');
    final label =
        (clean.isEmpty ? '?' : clean.substring(0, math.min(2, clean.length)))
            .toUpperCase();
    final color =
        _palette[code.codeUnits.fold<int>(0, (sum, unit) => sum + unit) %
            _palette.length];
    return ColoredBox(
      color: color,
      child: Center(
        child: Text(
          label,
          style: TextStyle(
            color: Colors.white,
            fontSize: size * 0.38,
            fontWeight: FontWeight.w700,
            height: 1,
          ),
        ),
      ),
    );
  }
}
