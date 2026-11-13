import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/dashboard.dart';
import '../../state/dashboard_controller.dart';
import '../widgets/common.dart';

/// The Overview tab: KPIs, receipts waiting, arrears and the year picker.
class OverviewScreen extends StatelessWidget {
  const OverviewScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final ctl = context.watch<DashboardController>();

    return RefreshIndicator(
      onRefresh: () => ctl.load(),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(14),
        children: [
          _YearPicker(
            controller: ctl,
            years: ctl.years,
            selected: ctl.selectedYear,
          ),
          const SizedBox(height: 12),
          if (ctl.busy && ctl.data == null)
            const Padding(padding: EdgeInsets.all(28), child: LoadingView())
          else if (ctl.error != null && ctl.data == null)
            ErrorView(message: ctl.error!, onRetry: () => ctl.load())
          else if (ctl.data != null)
            ..._buildBody(context, ctl.data!),
        ],
      ),
    );
  }

  List<Widget> _buildBody(BuildContext context, DashboardData d) {
    return [
      Text(
        '${Strings.t('asOf')} ${formatDateTime(d.asOf)}',
        style: const TextStyle(fontSize: 12, color: GovColors.muted),
      ),
      const SizedBox(height: 10),
      _KpiGrid(kpis: d.kpis),
      const SizedBox(height: 14),
      if (d.receipts.isNotEmpty) _ReceiptsCard(receipts: d.receipts),
      if (d.receipts.isNotEmpty) const SizedBox(height: 14),
      _ArrearsCard(d: d),
      const SizedBox(height: 14),
      _BreakdownCard(d: d),
      const SizedBox(height: 14),
      if (d.recent.isNotEmpty) _RecentCard(recent: d.recent),
      const SizedBox(height: 20),
    ];
  }
}

class _YearPicker extends StatelessWidget {
  const _YearPicker({
    required this.controller,
    required this.years,
    required this.selected,
  });

  final DashboardController controller;
  final List<int> years;
  final int? selected;

  @override
  Widget build(BuildContext context) {
    if (years.isEmpty) return const SizedBox.shrink();
    return Row(
      children: [
        Expanded(
          child: DropdownButtonFormField<int>(
            initialValue: selected,
            decoration: InputDecoration(
              labelText: Strings.t('feeYear'),
              prefixIcon: const Icon(Icons.calendar_today_outlined, size: 18),
            ),
            items: [
              DropdownMenuItem<int>(
                value: null,
                child: Text(Strings.t('allYears')),
              ),
              ...years.map(
                (y) => DropdownMenuItem<int>(value: y, child: Text('$y')),
              ),
            ],
            onChanged: (v) => controller.load(year: v),
          ),
        ),
      ],
    );
  }
}

class _KpiGrid extends StatelessWidget {
  const _KpiGrid({required this.kpis});

  final Kpis kpis;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, c) {
        final two = (c.maxWidth - 10) / 2;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            _kpi(
              two,
              Strings.t('collectionRate'),
              formatPercent(kpis.collectionRate),
              GovColors.ok,
            ),
            _kpi(
              two,
              Strings.t('collected'),
              formatKip(kpis.collectedKip),
              GovColors.ink,
            ),
            _kpi(
              two,
              Strings.t('outstanding'),
              formatKip(kpis.outstandingKip),
              GovColors.danger,
            ),
            _kpi(
              two,
              Strings.t('overdue'),
              formatKip(kpis.overdueKip),
              GovColors.danger,
            ),
          ],
        );
      },
    );
  }

  Widget _kpi(double width, String label, String value, Color color) {
    return SizedBox(
      width: width,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(fontSize: 12.5, color: GovColors.muted),
              ),
              const SizedBox(height: 6),
              Text(
                value,
                style: TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w800,
                  color: color,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ReceiptsCard extends StatelessWidget {
  const _ReceiptsCard({required this.receipts});

  final List<Map<String, dynamic>> receipts;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: Strings.t('waitingVerify'),
      icon: Icons.receipt_long_outlined,
      child: Column(
        children: receipts
            .map(
              (r) => InfoRow(
                label: '${r['status'] ?? '-'}',
                value:
                    '${r['count'] ?? 0} · ${r['amount_kip'] is num ? formatKip(r['amount_kip']) : ''}',
                bold: true,
              ),
            )
            .toList(),
      ),
    );
  }
}

class _ArrearsCard extends StatelessWidget {
  const _ArrearsCard({required this.d});

  final DashboardData d;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: Strings.t('arrears'),
      icon: Icons.warning_amber_outlined,
      child: Column(
        children: [
          InfoRow(
            label: Strings.t('open'),
            value: '${d.allYears.openObligations}',
          ),
          InfoRow(
            label: Strings.t('overdue'),
            value: '${d.allYears.overdueObligations}',
          ),
          InfoRow(
            label: Strings.t('outstanding'),
            value: formatKip(d.allYears.outstandingKip),
            valueColor: GovColors.danger,
          ),
          InfoRow(
            label: Strings.t('overdue'),
            value: formatKip(d.allYears.overdueKip),
            valueColor: GovColors.danger,
          ),
        ],
      ),
    );
  }
}

class _BreakdownCard extends StatelessWidget {
  const _BreakdownCard({required this.d});

  final DashboardData d;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: '${Strings.t('collectionRate')} · ${Strings.t('breakdownLabel')}',
      icon: Icons.pie_chart_outline,
      child: Column(
        children: [
          if (d.breakdownRows.isEmpty)
            Padding(
              padding: const EdgeInsets.all(8),
              child: Text(
                Strings.t('noRows'),
                style: const TextStyle(color: GovColors.muted),
              ),
            )
          else
            ...d.breakdownRows
                .take(8)
                .map(
                  (r) => InfoRow(
                    label: '${r['name'] ?? '-'}',
                    value:
                        '${r['collected_kip'] is num ? formatKipPlain(r['collected_kip']) : '-'} · ${r['rate'] is num ? formatPercent(r['rate']) : '-'}',
                    bold: true,
                  ),
                ),
        ],
      ),
    );
  }
}

class _RecentCard extends StatelessWidget {
  const _RecentCard({required this.recent});

  final List<Map<String, dynamic>> recent;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: Strings.t('recent'),
      icon: Icons.history,
      child: Column(
        children: recent
            .map(
              (r) => InfoRow(
                label: '${r['payer'] ?? '-'}',
                value: formatKipPlain(
                  r['amount_kip'] is num ? r['amount_kip'] : 0,
                ),
              ),
            )
            .toList(),
      ),
    );
  }
}
