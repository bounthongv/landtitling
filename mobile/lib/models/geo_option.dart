import 'parsing.dart';

/// One entry in a province / district / village dropdown.
class GeoOption {
  const GeoOption({required this.id, required this.name});

  factory GeoOption.fromJson(Map<String, dynamic> json) => GeoOption(
    id: toInt(json['id']),
    name: toStr(json['name_lo'] ?? json['name_en'] ?? json['code'], '-'),
  );

  final int id;
  final String name;
}
