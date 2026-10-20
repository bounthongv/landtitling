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

/// Find a parcel by its number and see what is owed on it.
///
/// The parcel number is on the land title. The server works out the rate from
/// the zone code on the parcel, so the citizen never has to know a rate.
class FindParcelScreen extends StatefulWidget {
  const FindParcelScreen({super.key, this.initialParcelNo});

  final String? initialParcelNo;

  @override
  State<FindParcelScreen> createState() => _FindParcelScreenState();
}

class _FindParcelScreenState extends State<FindParcelScreen> {
  late final TextEditingController _controller;
  final _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialParcelNo ?? '');
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if ((widget.initialParcelNo ?? '').isNotEmpty) {
        _search();
      } else {
        _focus.requestFocus();
      }
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final controller = context.read<ParcelsController>();
    FocusScope.of(context).unfocus();
    final quote = await controller.lookup(_controller.text);
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
                  title: 'ເລກຕອນດິນ',
                  icon: Icons.search,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      TextField(
                        controller: _controller,
                        focusNode: _focus,
                        autocorrect: false,
                        textInputAction: TextInputAction.search,
                        onSubmitted: (_) => _search(),
                        decoration: const InputDecoration(
                          hintText: '1301.001.001.0001',
                          prefixIcon: Icon(Icons.map_outlined),
                        ),
                      ),
                      const SizedBox(height: 12),
                      FilledButton.icon(
                        onPressed: controller.lookingUp ? null : _search,
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

    return SectionCard(
      title: parcel.displayNo,
      icon: Icons.landscape_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
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
          if (quote.obligation != null)
            InfoRow(
              label: Strings.t('dueDate'),
              value: formatDate(quote.obligation!.dueDate),
            ),
          const SizedBox(height: 12),
          if (settled)
            Row(
              children: [
                StatusChip(status: quote.obligation!.status),
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
