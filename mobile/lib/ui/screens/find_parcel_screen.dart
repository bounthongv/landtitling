import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/quote.dart';
import '../../state/parcels_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import '../widgets/status_chip.dart';
import 'quote_screen.dart';

/// Find a parcel on the land title and see what is owed on it.
///
/// The identity on paper is two things: the map sheet and the parcel number on
/// that sheet. A number can repeat on another sheet, so both are required. The
/// server works out the rate from the zone code on the parcel, so the citizen
/// never has to know a rate.
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

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _sheetFocus.requestFocus();
    });
  }

  @override
  void dispose() {
    _sheet.dispose();
    _parcelNo.dispose();
    _sheetFocus.dispose();
    _parcelNoFocus.dispose();
    super.dispose();
  }

  bool get _ready =>
      _sheet.text.trim().isNotEmpty && _parcelNo.text.trim().isNotEmpty;

  Future<void> _search() async {
    if (!_ready) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(Strings.t('enterSheetAndParcel')),
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
    );
    if (!mounted) return;
    if (quote == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(controller.lookupError ?? 'ບໍ່ພົບຕອນດິນ'),
          backgroundColor: GovColors.danger,
        ),
      );
    }
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
                  _quoteCard(quote),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _quoteCard(ParcelQuote quote) {
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
        ],
      ),
    );
  }
}
