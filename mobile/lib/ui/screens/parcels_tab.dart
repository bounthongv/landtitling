import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/obligation.dart';
import '../../models/parcel.dart';
import '../../state/parcels_controller.dart';
import '../widgets/common.dart';
import '../widgets/status_chip.dart';
import 'parcel_detail_screen.dart';

/// Home: the citizen's parcels, with the total still owed for the selected
/// year.
class ParcelsTab extends StatefulWidget {
  const ParcelsTab({super.key});

  @override
  State<ParcelsTab> createState() => _ParcelsTabState();
}

class _ParcelsTabState extends State<ParcelsTab> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final controller = context.read<ParcelsController>();
      if (controller.parcels.isEmpty) controller.load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<ParcelsController>();

    if (controller.loading && controller.parcels.isEmpty) {
      return const LoadingView();
    }
    if (controller.error != null && controller.parcels.isEmpty) {
      return ErrorView(
        message: controller.error!,
        onRetry: () => controller.load(),
      );
    }
    if (controller.parcels.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => controller.load(silent: true),
        child: ListView(
          children: [
            SizedBox(
              height: MediaQuery.of(context).size.height * 0.6,
              child: EmptyState(
                icon: Icons.landscape_outlined,
                title: Strings.t('noParcels'),
                hint: Strings.t('noParcelsHint'),
              ),
            ),
          ],
        ),
      );
    }

    final parcels = controller.parcelsForYear;

    return RefreshIndicator(
      onRefresh: () => controller.load(silent: true),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 24),
        children: [
          _summary(controller),
          const SizedBox(height: 12),
          if (controller.years.length > 1) _yearPicker(controller),
          if (controller.years.length > 1) const SizedBox(height: 12),
          if (parcels.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 40),
              child: EmptyState(
                icon: Icons.event_available,
                title: Strings.t('noData'),
                hint: Strings.t('noParcelsHint'),
              ),
            )
          else
            ...parcels.map(
              (p) => Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _parcelCard(p, controller),
              ),
            ),
        ],
      ),
    );
  }

  Widget _summary(ParcelsController controller) {
    final total = controller.totalOutstanding;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [GovColors.gov, GovColors.gov2],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            Strings.t('totalOutstanding'),
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.85),
              fontSize: 12.5,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            formatKip(total),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 26,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            '${controller.parcels.length} ${Strings.t('myParcels').toLowerCase()}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.8),
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }

  Widget _yearPicker(ParcelsController controller) {
    return SizedBox(
      height: 36,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: controller.years.map((year) {
          final selected = controller.selectedYear == year;
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              label: Text('$year'),
              selected: selected,
              showCheckmark: false,
              selectedColor: GovColors.gov,
              labelStyle: TextStyle(
                color: selected ? Colors.white : GovColors.gov,
                fontWeight: FontWeight.w700,
                fontSize: 12.5,
              ),
              side: const BorderSide(color: GovColors.gov),
              onSelected: (_) => controller.selectYear(year),
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _parcelCard(Parcel parcel, ParcelsController controller) {
    final year = controller.selectedYear;
    final matches = year == null
        ? const <Obligation>[]
        : parcel.obligations.where((o) => o.feeYear == year).toList();
    final obligation = matches.isEmpty ? null : matches.first;
    final outstanding = obligation == null || obligation.isSettled
        ? 0.0
        : obligation.outstanding;

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => ParcelDetailScreen(parcelId: parcel.id),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      parcel.displayNo,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  if (obligation != null)
                    StatusChip(
                      status: obligation.isSettled
                          ? obligation.status
                          : obligation.status,
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                [
                  parcel.districtName,
                  parcel.villageName,
                ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                style: const TextStyle(fontSize: 12.5, color: GovColors.muted),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: _miniStat(
                      Strings.t('area'),
                      formatArea(parcel.areaSqm),
                    ),
                  ),
                  if (obligation != null)
                    Expanded(
                      child: _miniStat(
                        Strings.t('outstanding'),
                        formatKip(outstanding),
                        color: outstanding > 0
                            ? GovColors.danger
                            : GovColors.ok,
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _miniStat(String label, String value, {Color? color}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 11, color: GovColors.muted),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color: color ?? GovColors.ink,
          ),
        ),
      ],
    );
  }
}
