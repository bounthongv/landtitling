import 'parsing.dart';

/// A Lao bank the citizen may have paid through.
class Bank {
  const Bank({
    required this.code,
    required this.nameLo,
    required this.nameEn,
  });

  final String code;
  final String nameLo;
  final String nameEn;

  factory Bank.fromJson(Map<String, dynamic> json) => Bank(
    code: toStr(json['code']),
    nameLo: toStr(json['name_lo']),
    nameEn: toStr(json['name_en']),
  );
}