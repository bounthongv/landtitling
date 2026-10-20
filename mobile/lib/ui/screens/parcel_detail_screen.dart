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
import 'pay_screen.dart';

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

    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: parcel.displayNo,
            subtitle: [
              parcel.districtName,
              parcel.villageName,
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
                      InfoRow(
                        label: Strings.t('parcelNo'),
                        value: parcel.displayNo,
                      ),
                      InfoRow(
                        label: Strings.t('titleNo'),
                        value: parcel.titleNo ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('district'),
                        value: parcel.districtName ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('village'),
                        value: parcel.villageName ?? '-',
                      ),
                      InfoRow(
                        label: Strings.t('area'),
                        value: formatArea(parcel.areaSqm),
                      ),
                      if (parcel.zoneCode != null)
                        InfoRow(
                          label: Strings.t('zone'),
                          value: parcel.zoneCode!,
                        ),
                      if (parcel.ownerName != null)
                        InfoRow(
                          label: Strings.t('owner'),
                          value: parcel.ownerName!,
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'ຄ່າຊຳລະລາຍປີ',
                  icon: Icons.receipt_long_outlined,
                  child: parcel.obligationsByYear.isEmpty
                      ? Text(
                          Strings.t('noData'),
                          style: const TextStyle(
                            fontSize: 13,
                            color: GovColors.muted,
                          ),
                        )
                      : Column(
                          children: parcel.obligationsByYear
                              .map((o) => _obligationRow(context, o))
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

  Widget _obligationRow(BuildContext context, Obligation o) {
    final days = daysUntil(o.dueDate);
    return InkWell(
      onTap: o.isSettled
          ? null
          : () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => PayScreen(obligation: o)),
            ),
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
