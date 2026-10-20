import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/obligation.dart';
import '../../models/parcel.dart';
import '../../state/parcels_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import '../widgets/status_chip.dart';
import 'quote_screen.dart';

/// Everything the land office holds about one of the citizen's parcels, and
/// what is owed for it, year by year.
class ParcelDetailScreen extends StatelessWidget {
  const ParcelDetailScreen({super.key, required this.parcelId});

  final int parcelId;

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<ParcelsController>();
    Parcel? parcel;
    for (final p in controller.parcels) {
      if (p.id == parcelId) {
        parcel = p;
        break;
      }
    }

    if (parcel == null) {
      return Scaffold(
        appBar: AppBar(title: Text(Strings.t('myParcels'))),
        body: const ErrorView(message: 'ບໍ່ພົບຂໍ້ມູນຕອນດິນນີ້'),
      );
    }
    final p = parcel;

    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: p.displayNo,
            subtitle: [
              p.districtName,
              p.villageName,
            ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 26),
              children: [
                SectionCard(
                  title: 'ຂໍ້ມູນຕອນດິນ',
                  icon: Icons.info_outline,
                  child: Column(
                    children: [
                      InfoRow(label: Strings.t('parcelNo'), value: p.displayNo),
                      InfoRow(
                        label: Strings.t('titleNo'),
                        value: p.titleNo ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('district'),
                        value: p.districtName ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('village'),
                        value: p.villageName ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('area'),
                        value: formatArea(p.areaSqm),
                      ),
                      if (p.zoneCode != null)
                        InfoRow(label: Strings.t('zone'), value: p.zoneCode!),
                      if (p.ownerName != null)
                        InfoRow(label: Strings.t('owner'), value: p.ownerName!),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'ຄ່າຊຳລະລາຍປີ',
                  icon: Icons.receipt_long_outlined,
                  child: p.obligationsByYear.isEmpty
                      ? Text(
                          Strings.t('noData'),
                          style: const TextStyle(
                            fontSize: 13,
                            color: GovColors.muted,
                          ),
                        )
                      : Column(
                          children: p.obligationsByYear
                              .map((o) => _obligationRow(context, p, o))
                              .toList(),
                        ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Look the parcel up again (for the current server-side price) and open the
  /// quote screen.
  Future<void> _openQuote(
    BuildContext context,
    Parcel parcel,
    Obligation obligation,
  ) async {
    final controller = context.read<ParcelsController>();
    final messenger = ScaffoldMessenger.of(context);
    final quote = await controller.lookup(
      parcel.parcelNo,
      feeYear: obligation.feeYear,
    );
    if (!context.mounted) return;
    if (quote == null) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(controller.lookupError ?? 'ບໍ່ພົບຂໍ້ມູນຕອນດິນ'),
          backgroundColor: GovColors.danger,
        ),
      );
      return;
    }
    Navigator.of(
      context,
    ).push(MaterialPageRoute<void>(builder: (_) => QuoteScreen(quote: quote)));
  }

  Widget _obligationRow(BuildContext context, Parcel parcel, Obligation o) {
    final days = daysUntil(o.dueDate);
    return InkWell(
      onTap: o.isSettled ? null : () => _openQuote(context, parcel, o),
      borderRadius: BorderRadius.circular(10),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: o.isSettled
              ? GovColors.bg
              : GovColors.gold.withValues(alpha: 0.07),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: o.isSettled
                ? GovColors.line
                : GovColors.gold.withValues(alpha: 0.45),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(
                  '${o.feeYear}',
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 8),
                StatusChip(status: o.status),
                const Spacer(),
                if (!o.isSettled)
                  const Icon(
                    Icons.chevron_right,
                    color: GovColors.muted,
                    size: 20,
                  ),
              ],
            ),
            const SizedBox(height: 8),
            InfoRow(
              label: Strings.t('amountDue'),
              value: formatKip(o.amountDue),
            ),
            if (o.penalty > 0)
              InfoRow(
                label: Strings.t('penalty'),
                value: formatKip(o.penalty),
                valueColor: GovColors.danger,
              ),
            InfoRow(
              label: Strings.t('outstanding'),
              value: formatKip(o.outstanding),
              valueColor: o.isSettled ? GovColors.ok : GovColors.danger,
              bold: true,
            ),
            InfoRow(
              label: Strings.t('dueDate'),
              value: o.dueDate == null
                  ? '-'
                  : '${formatDate(o.dueDate)}${days != null && days < 0 ? '  (ຫຼາຍເກີນ)' : ''}',
              valueColor: o.isOverdue ? GovColors.danger : null,
            ),
          ],
        ),
      ),
    );
  }
}
