import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/report_data.dart';
import '../../state/reports_controller.dart';
import '../widgets/common.dart';

/// The Reports tab: pick a report, adjust filters, read the table, share CSV.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key});

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  bool _sharing = false;

  Future<void> _share() async {
    final ctl = context.read<ReportsController>();
    if (ctl.selected == null) return;
    setState(() => _sharing = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final csv = await ctl.downloadCsv();
      if (!mounted) return;
      final file = File(
        '${Directory.systemTemp.path}/${ctl.selected!.key}_report.csv',
      );
      await file.writeAsString(csv);
      if (!mounted) return;
      await SharePlus.instance.share(
        ShareParams(files: [XFile(file.path)], subject: 'landfee-report'),
      );
    } catch (e) {
      if (!mounted) return;
      messenger.showSnackBar(SnackBar(content: Text('$e')));
    } finally {
      if (mounted) setState(() => _sharing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ctl = context.watch<ReportsController>();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 0),
          child: _ReportPicker(controller: ctl),
        ),
        if (ctl.selected != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
            child: _FilterBar(
              controller: ctl,
              onShare: _share,
              sharing: _sharing,
            ),
          ),
        Expanded(child: _buildBody(ctl)),
      ],
    );
  }

  Widget _buildBody(ReportsController ctl) {
    if (ctl.busy && ctl.data == null) {
      return const LoadingView();
    }
    if (ctl.error != null && ctl.data == null) {
      return ErrorView(message: ctl.error!, onRetry: () => ctl.load());
    }
    final data = ctl.data;
    if (data == null) {
      return EmptyState(
        icon: Icons.description_outlined,
        title: Strings.t('noRows'),
        hint: Strings.t('pickReport'),
      );
    }
    if (data.rows.isEmpty) {
      return EmptyState(icon: Icons.search_off, title: Strings.t('noRows'));
    }
    return _ReportTable(data: data);
  }
}

class _ReportPicker extends StatelessWidget {
  const _ReportPicker({required this.controller});

  final ReportsController controller;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<String>(
      initialValue: controller.selected?.key,
      decoration: InputDecoration(
        labelText: Strings.t('report'),
        prefixIcon: const Icon(Icons.description_outlined, size: 18),
      ),
      items: kReportOptions
          .map(
            (o) => DropdownMenuItem<String>(
              value: o.key,
              child: Text(Strings.isLao ? o.labelLo : o.labelEn),
            ),
          )
          .toList(),
      onChanged: (key) {
        final option = kReportOptions.firstWhere(
          (o) => o.key == key,
          orElse: () => kReportOptions.first,
        );
        controller.select(option);
      },
    );
  }
}

class _FilterBar extends StatefulWidget {
  const _FilterBar({
    required this.controller,
    required this.onShare,
    required this.sharing,
  });

  final ReportsController controller;
  final VoidCallback onShare;
  final bool sharing;

  @override
  State<_FilterBar> createState() => _FilterBarState();
}

class _FilterBarState extends State<_FilterBar> {
  late final TextEditingController _from;
  late final TextEditingController _to;

  @override
  void initState() {
    super.initState();
    _from = TextEditingController(text: widget.controller.from);
    _to = TextEditingController(text: widget.controller.to);
  }

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ctl = widget.controller;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SizedBox(
              width: 110,
              child: TextFormField(
                controller: _from,
                decoration: InputDecoration(
                  labelText: Strings.t('from'),
                  isDense: true,
                ),
                onEditingComplete: () {
                  ctl.from = _from.text.trim();
                  ctl.load();
                },
              ),
            ),
            SizedBox(
              width: 110,
              child: TextFormField(
                controller: _to,
                decoration: InputDecoration(
                  labelText: Strings.t('to'),
                  isDense: true,
                ),
                onEditingComplete: () {
                  ctl.to = _to.text.trim();
                  ctl.load();
                },
              ),
            ),
            IconButton(
              tooltip: Strings.t('run'),
              icon: const Icon(Icons.play_arrow),
              onPressed: () {
                ctl.from = _from.text.trim();
                ctl.to = _to.text.trim();
                ctl.load();
              },
            ),
            const Spacer(),
            FilledButton.tonalIcon(
              onPressed: widget.sharing ? null : widget.onShare,
              icon: const Icon(Icons.ios_share),
              label: Text(Strings.t('shareCsv')),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReportTable extends StatelessWidget {
  const _ReportTable({required this.data});

  final ReportData data;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (data.summary.isNotEmpty) ...[
              _SummaryStrip(summary: data.summary),
              const SizedBox(height: 12),
            ],
            Text(
              Strings.t('rows').replaceAll('{n}', '${data.rows.length}'),
              style: const TextStyle(fontSize: 12, color: GovColors.muted),
            ),
            const SizedBox(height: 8),
            Table(
              border: TableBorder.all(color: GovColors.line, width: 0.5),
              columnWidths: {
                for (var i = 0; i < data.columns.length; i++)
                  i: const FlexColumnWidth(),
              },
              defaultVerticalAlignment: TableCellVerticalAlignment.middle,
              children: [
                TableRow(
                  decoration: const BoxDecoration(color: GovColors.gov),
                  children: data.columns
                      .map(
                        (c) => Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 8,
                          ),
                          child: Text(
                            c,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      )
                      .toList(),
                ),
                ...data.rows.map(
                  (row) => TableRow(
                    children: data.columns
                        .map(
                          (c) => Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 6,
                            ),
                            child: Text(
                              cellText(row[c]),
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                        )
                        .toList(),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _SummaryStrip extends StatelessWidget {
  const _SummaryStrip({required this.summary});

  final Map<String, dynamic> summary;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: Strings.t('summary'),
      child: Wrap(
        spacing: 18,
        runSpacing: 6,
        children: summary.entries
            .map(
              (e) => Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    e.key,
                    style: const TextStyle(
                      fontSize: 11,
                      color: GovColors.muted,
                    ),
                  ),
                  Text(
                    cellText(e.value),
                    style: const TextStyle(
                      fontSize: 13.5,
                      fontWeight: FontWeight.w800,
                      color: GovColors.ink,
                    ),
                  ),
                ],
              ),
            )
            .toList(),
      ),
    );
  }
}
