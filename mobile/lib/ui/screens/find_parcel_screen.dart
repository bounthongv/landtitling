import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/api_exception.dart';
import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/geo_option.dart';
import '../../models/quote.dart';
import '../../state/parcels_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import '../widgets/status_chip.dart';
import 'quote_screen.dart';

/// Find a parcel from the title and see what is owed on it.
///
/// The parcel is identified the way the district registry identifies it:
/// province → district → village → map sheet → parcel number. A number (and a
/// sheet) can repeat in another district, so the location is required too. The
/// server works out the rate from the zone code on the parcel, so the citizen
/// never has to know a rate.
///
/// Looking a parcel up does not link it to the account: the citizen adds it to
/// "my parcels" with an explicit claim, which the server audits.
class FindParcelScreen extends StatefulWidget {
  const FindParcelScreen({super.key});

  @override
  State<FindParcelScreen> createState() => _FindParcelScreenState();
}

class _FindParcelScreenState extends State<FindParcelScreen> {
  final _sheet = TextEditingController();
  final _parcelNo = TextEditingController();
  final _sheetFocus = FocusNode();
  final _parcelNoFocus = FocusNode();

  List<GeoOption> _provinces = const <GeoOption>[];
  List<GeoOption> _districts = const <GeoOption>[];
  List<GeoOption> _villages = const <GeoOption>[];
  int? _provinceId;
  int? _districtId;
  int? _villageId;
  bool _loadingProvinces = true;
  bool _loadingDistricts = false;
  bool _loadingVillages = false;
  String? _geoError;

  @override
  void initState() {
    super.initState();
    _loadProvinces();
  }

  @override
  void dispose() {
    _sheet.dispose();
    _parcelNo.dispose();
    _sheetFocus.dispose();
    _parcelNoFocus.dispose();
    super.dispose();
  }

  Future<void> _loadProvinces() async {
    try {
      final data = await context.read<ApiClient>().getData(
        'refdata.php',
        query: {'table': 'provinces'},
      );
      if (!mounted) return;
      setState(() {
        _provinces = _options(data['items']);
        _loadingProvinces = false;
        _geoError = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingProvinces = false;
        _geoError = e.message;
      });
    }
  }

  Future<void> _loadDistricts(int provinceId) async {
    setState(() {
      _districts = const <GeoOption>[];
      _villages = const <GeoOption>[];
      _districtId = null;
      _villageId = null;
      _loadingDistricts = true;
    });
    try {
      final data = await context.read<ApiClient>().getData(
        'districts.php',
        query: {'province_id': '$provinceId'},
      );
      if (!mounted || _provinceId != provinceId) return;
      setState(() {
        _districts = _options(data['items']);
        _loadingDistricts = false;
      });
    } on ApiException catch (e) {
      if (!mounted || _provinceId != provinceId) return;
      setState(() {
        _loadingDistricts = false;
        _geoError = e.message;
      });
    }
  }

  Future<void> _loadVillages(int districtId) async {
    setState(() {
      _villages = const <GeoOption>[];
      _villageId = null;
      _loadingVillages = true;
    });
    try {
      final data = await context.read<ApiClient>().getData(
        'villages.php',
        query: {'district_id': '$districtId'},
      );
      if (!mounted || _districtId != districtId) return;
      setState(() {
        _villages = _options(data['items']);
        _loadingVillages = false;
      });
    } on ApiException catch (e) {
      if (!mounted || _districtId != districtId) return;
      setState(() {
        _loadingVillages = false;
        _geoError = e.message;
      });
    }
  }

  List<GeoOption> _options(dynamic raw) => raw is List
      ? raw
            .whereType<Map<String, dynamic>>()
            .map(GeoOption.fromJson)
            .toList()
      : const <GeoOption>[];

  bool get _ready =>
      _provinceId != null &&
      _districtId != null &&
      _villageId != null &&
      _sheet.text.trim().isNotEmpty &&
      _parcelNo.text.trim().isNotEmpty;

  Future<void> _search() async {
    if (!_ready) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(Strings.t('enterParcelIdentity')),
          backgroundColor: GovColors.danger,
        ),
      );
      return;
    }
    final controller = context.read<ParcelsController>();
    FocusScope.of(context).unfocus();
    final quote = await controller.lookup(
      _parcelNo.text,
      mapRefNo: _sheet.text,
      provinceId: _provinceId!,
      districtId: _districtId!,
      villageId: _villageId!,
    );
    if (!mounted) return;
    if (quote == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(controller.lookupError ?? Strings.t('noData')),
          backgroundColor: GovColors.danger,
        ),
      );
    }
  }

  Future<void> _claim(ParcelsController controller) async {
    final ok = await controller.claim();
    if (!mounted || ok) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(controller.claimError ?? Strings.t('noData')),
        backgroundColor: GovColors.danger,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<ParcelsController>();
    final quote = controller.quote;

    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: 'ຄົ້ນຫາທີ່ດິນ',
            subtitle: 'Find your parcel',
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 26),
              children: [
                SectionCard(
                  title: 'ຂໍ້ມູນຕອນດິນ',
                  icon: Icons.map_outlined,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      DropdownButtonFormField<int>(
                        initialValue: _provinceId,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText: Strings.t('province'),
                          prefixIcon: const Icon(Icons.account_balance_outlined),
                          hintText: _loadingProvinces
                              ? Strings.t('loading')
                              : null,
                        ),
                        items: _provinces
                            .map(
                              (p) => DropdownMenuItem<int>(
                                value: p.id,
                                child: Text(p.name),
                              ),
                            )
                            .toList(),
                        onChanged: _loadingProvinces
                            ? null
                            : (value) {
                                setState(() => _provinceId = value);
                                if (value != null) _loadDistricts(value);
                              },
                      ),
                      const SizedBox(height: 10),
                      DropdownButtonFormField<int>(
                        initialValue: _districtId,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText: Strings.t('district'),
                          prefixIcon: const Icon(Icons.location_city_outlined),
                          hintText: _loadingDistricts
                              ? Strings.t('loading')
                              : null,
                        ),
                        items: _districts
                            .map(
                              (d) => DropdownMenuItem<int>(
                                value: d.id,
                                child: Text(d.name),
                              ),
                            )
                            .toList(),
                        onChanged: _provinceId == null || _loadingDistricts
                            ? null
                            : (value) {
                                setState(() => _districtId = value);
                                if (value != null) _loadVillages(value);
                              },
                      ),
                      const SizedBox(height: 10),
                      DropdownButtonFormField<int>(
                        initialValue: _villageId,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText: Strings.t('village'),
                          prefixIcon: const Icon(Icons.holiday_village_outlined),
                          hintText: _loadingVillages
                              ? Strings.t('loading')
                              : null,
                        ),
                        items: _villages
                            .map(
                              (v) => DropdownMenuItem<int>(
                                value: v.id,
                                child: Text(v.name),
                              ),
                            )
                            .toList(),
                        onChanged: _districtId == null || _loadingVillages
                            ? null
                            : (value) => setState(() => _villageId = value),
                      ),
                      if (_geoError != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          _geoError!,
                          style: const TextStyle(
                            fontSize: 12,
                            color: GovColors.danger,
                          ),
                        ),
                      ],
                      const SizedBox(height: 10),
                      TextField(
                        controller: _sheet,
                        focusNode: _sheetFocus,
                        autocorrect: false,
                        textCapitalization: TextCapitalization.characters,
                        textInputAction: TextInputAction.next,
                        onSubmitted: (_) => _parcelNoFocus.requestFocus(),
                        onChanged: (_) => setState(() {}),
                        decoration: InputDecoration(
                          labelText: Strings.t('mapSheet'),
                          hintText: 'A250-038',
                          prefixIcon: const Icon(Icons.map_outlined),
                        ),
                      ),
                      const SizedBox(height: 10),
                      TextField(
                        controller: _parcelNo,
                        focusNode: _parcelNoFocus,
                        autocorrect: false,
                        textInputAction: TextInputAction.search,
                        onSubmitted: (_) => _search(),
                        onChanged: (_) => setState(() {}),
                        decoration: InputDecoration(
                          labelText: Strings.t('parcelNo'),
                          hintText: '1301.001.001.0001',
                          prefixIcon: const Icon(Icons.tag),
                        ),
                      ),
                      const SizedBox(height: 12),
                      FilledButton.icon(
                        onPressed: controller.lookingUp || !_ready
                            ? null
                            : _search,
                        icon: controller.lookingUp
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Icon(Icons.search),
                        label: Text(Strings.t('viewFee')),
                      ),
                    ],
                  ),
                ),
                if (quote != null) ...[
                  const SizedBox(height: 12),
                  _quoteCard(quote, controller),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _quoteCard(ParcelQuote quote, ParcelsController controller) {
    final parcel = quote.parcel;
    final settled = quote.isSettled;
    final obligation = quote.obligation;

    return SectionCard(
      title: parcel.displayNo,
      icon: Icons.landscape_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InfoRow(
            label: Strings.t('mapSheet'),
            value: parcel.mapRefNo ?? '-',
          ),
          InfoRow(
            label: Strings.t('district'),
            value: parcel.districtName ?? '-',
          ),
          InfoRow(
            label: Strings.t('village'),
            value: parcel.villageName ?? '-',
          ),
          InfoRow(label: Strings.t('area'), value: formatArea(parcel.areaSqm)),
          InfoRow(label: Strings.t('zone'), value: parcel.zoneCode ?? '-'),
          if (quote.owners.isNotEmpty)
            InfoRow(
              label: Strings.t('owner'),
              value: quote.owners.map((o) => o.fullName).join(', '),
            ),
          const Divider(height: 20),
          if (!quote.rateFound)
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: GovColors.danger.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Text(
                'ບໍ່ພົບອັດຕາຂອງເຂດນີ້ ກະລຸນາຕິດຕໍ່ຫຼັງການດິນ',
                style: TextStyle(fontSize: 12.5, color: GovColors.danger),
              ),
            ),
          InfoRow(
            label: 'ຄ່າຕ້ອງເມົາ',
            value:
                '${formatKipPlain(quote.ratePerSqm)} × ${formatArea(parcel.areaSqm)}',
          ),
          InfoRow(
            label: Strings.t('amountDue'),
            value: formatKip(quote.amountDue),
            valueColor: GovColors.gov,
            bold: true,
          ),
          if (obligation != null && obligation.penalty > 0)
            InfoRow(
              label: Strings.t('penalty'),
              value: formatKip(obligation.penalty),
              valueColor: GovColors.danger,
            ),
          if (obligation != null)
            InfoRow(
              label: Strings.t('outstanding'),
              value: formatKip(quote.outstanding),
              valueColor: settled ? GovColors.ok : GovColors.danger,
              bold: true,
            ),
          if (obligation != null)
            InfoRow(
              label: Strings.t('dueDate'),
              value: formatDate(obligation.dueDate),
            ),
          const SizedBox(height: 12),
          if (settled)
            Row(
              children: [
                StatusChip(status: obligation!.status),
                const SizedBox(width: 8),
                const Text(
                  'ຊຳລະແລ້ວ',
                  style: TextStyle(
                    fontSize: 13,
                    color: GovColors.ok,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            )
          else
            FilledButton.icon(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => QuoteScreen(quote: quote),
                ),
              ),
              icon: const Icon(Icons.payments_outlined),
              label: Text(Strings.t('payNow')),
            ),
          const SizedBox(height: 8),
          if (quote.claimed)
            Row(
              children: [
                const Icon(Icons.verified_outlined, size: 18, color: GovColors.ok),
                const SizedBox(width: 6),
                Text(
                  Strings.t('inMyParcels'),
                  style: const TextStyle(
                    fontSize: 13,
                    color: GovColors.ok,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            )
          else
            OutlinedButton.icon(
              onPressed: controller.claiming ? null : () => _claim(controller),
              icon: controller.claiming
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.playlist_add_check_outlined),
              label: Text(Strings.t('addToMyParcels')),
            ),
          if (controller.claimError != null) ...[
            const SizedBox(height: 6),
            Text(
              controller.claimError!,
              style: const TextStyle(fontSize: 12, color: GovColors.danger),
            ),
          ],
        ],
      ),
    );
  }
}
