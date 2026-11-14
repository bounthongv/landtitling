import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/agent.dart';
import '../../state/agent_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// Pending cash collections (build a handover) + handover history.
class AgentHandoversScreen extends StatefulWidget {
  const AgentHandoversScreen({super.key});

  @override
  State<AgentHandoversScreen> createState() => _AgentHandoversScreenState();
}

class _AgentHandoversScreenState extends State<AgentHandoversScreen> {
  final Set<int> _selected = {};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ctrl = context.read<AgentController>();
      ctrl.loadPendingCollections();
      ctrl.loadHandovers();
    });
  }

  void _toggle(int id, bool selected) {
    setState(() {
      if (selected) {
        _selected.add(id);
      } else {
        _selected.remove(id);
      }
    });
  }

  void _toggleAll(List<AgentCollection> list) {
    setState(() {
      if (_selected.length == list.length) {
        _selected.clear();
      } else {
        _selected
          ..clear()
          ..addAll(list.map((e) => e.id));
      }
    });
  }

  double get _selectedTotal {
    final ctrl = context.read<AgentController>();
    return ctrl.pendingCollections
        .where((c) => _selected.contains(c.id))
        .fold(0.0, (sum, c) => sum + c.amount);
  }

  Future<void> _createHandover() async {
    if (_selected.isEmpty) return;
    final ctrl = context.read<AgentController>();
    final ids = _selected.toList();
    final total = _selectedTotal;

    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(Strings.t('createHandover')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            InfoRow(label: Strings.t('selected'), value: '${ids.length}'),
            InfoRow(
              label: Strings.t('total'),
              value: formatKip(total),
              bold: true,
            ),
            const SizedBox(height: 8),
            Text(
              Strings.t('collectSuccessHint'),
              style: const TextStyle(fontSize: 12, color: GovColors.muted),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(Strings.t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(Strings.t('createHandover')),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    final handover = await ctrl.createHandover(ids);
    if (!mounted) return;
    if (handover == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(ctrl.error ?? Strings.t('retry'))));
      return;
    }
    setState(_selected.clear);
    ctrl.loadHandovers();
    await showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        icon: const Icon(
          Icons.inventory_2_outlined,
          color: GovColors.ok,
          size: 38,
        ),
        title: Text(Strings.t('handoverCreated')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            InfoRow(
              label: Strings.t('reference'),
              value: handover.batchReference,
            ),
            InfoRow(
              label: Strings.t('selected'),
              value: '${handover.paymentCount}',
            ),
            InfoRow(
              label: Strings.t('total'),
              value: formatKip(handover.amountTotal),
            ),
          ],
        ),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: Text(Strings.t('close')),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = context.watch<AgentController>();

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        backgroundColor: GovColors.bg,
        body: Column(
          children: [
            GovHeader(
              title: Strings.t('handovers'),
              onBack: () => Navigator.of(context).pop(),
            ),
            Material(
              color: GovColors.card,
              child: TabBar(
                labelColor: GovColors.gov,
                unselectedLabelColor: GovColors.muted,
                indicatorColor: GovColors.gold,
                tabs: [
                  Tab(text: Strings.t('pendingCollections')),
                  Tab(text: Strings.t('handovers')),
                ],
              ),
            ),
            Expanded(
              child: TabBarView(
                children: [_buildPending(ctrl), _buildHistory(ctrl)],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildPending(AgentController ctrl) {
    if (ctrl.busy && ctrl.pendingCollections.isEmpty) {
      return const LoadingView();
    }
    final list = ctrl.pendingCollections;
    if (list.isEmpty) {
      return RefreshIndicator(
        onRefresh: ctrl.loadPendingCollections,
        child: ListView(
          children: [
            SizedBox(height: MediaQuery.of(context).size.height * 0.15),
            EmptyState(
              icon: Icons.inbox_outlined,
              title: Strings.t('noPendingCollections'),
              hint: Strings.t('collectSuccessHint'),
            ),
          ],
        ),
      );
    }

    final allSelected = _selected.length == list.length;
    return Column(
      children: [
        Material(
          color: GovColors.card,
          child: InkWell(
            onTap: () => _toggleAll(list),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              child: Row(
                children: [
                  Checkbox(
                    value: allSelected,
                    onChanged: (_) => _toggleAll(list),
                  ),
                  Text(
                    Strings.t('selectAll'),
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    '${list.length} · ${formatKip(ctrl.pendingAmountTotal)}',
                    style: const TextStyle(
                      fontSize: 12,
                      color: GovColors.muted,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: ctrl.loadPendingCollections,
            child: ListView.builder(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
              itemCount: list.length,
              itemBuilder: (context, i) {
                final c = list[i];
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: CheckboxListTile(
                    value: _selected.contains(c.id),
                    onChanged: (v) => _toggle(c.id, v ?? false),
                    controlAffinity: ListTileControlAffinity.leading,
                    title: Text(
                      c.referenceNo,
                      style: const TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        fontFamily: 'monospace',
                      ),
                    ),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const SizedBox(height: 2),
                        Text(
                          '${c.parcelNo} · ${c.payerName}',
                          style: const TextStyle(fontSize: 12),
                        ),
                        Text(
                          c.collectedAt.isEmpty
                              ? ''
                              : formatDate(c.collectedAt),
                          style: const TextStyle(
                            fontSize: 11,
                            color: GovColors.muted,
                          ),
                        ),
                      ],
                    ),
                    secondary: Text(
                      formatKip(c.amount),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                        color: GovColors.gov,
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
        SafeArea(
          top: false,
          child: Container(
            padding: const EdgeInsets.fromLTRB(14, 8, 14, 10),
            decoration: const BoxDecoration(
              color: GovColors.card,
              border: Border(top: BorderSide(color: GovColors.line)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${_selected.length}/${list.length} ${Strings.t('selected')}',
                        style: const TextStyle(
                          fontSize: 11,
                          color: GovColors.muted,
                        ),
                      ),
                      Text(
                        formatKip(_selectedTotal),
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: GovColors.gov,
                        ),
                      ),
                    ],
                  ),
                ),
                FilledButton.icon(
                  onPressed: _selected.isEmpty || ctrl.busy
                      ? null
                      : _createHandover,
                  icon: const Icon(Icons.send_outlined),
                  label: Text(Strings.t('createHandover')),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildHistory(AgentController ctrl) {
    final list = ctrl.handovers;
    if (ctrl.busy && list.isEmpty) return const LoadingView();
    if (list.isEmpty) {
      return RefreshIndicator(
        onRefresh: ctrl.loadHandovers,
        child: ListView(
          children: [
            SizedBox(height: MediaQuery.of(context).size.height * 0.15),
            EmptyState(
              icon: Icons.inventory_2_outlined,
              title: Strings.t('noHandovers'),
              hint: Strings.t('handoverPending'),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: ctrl.loadHandovers,
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 20),
        itemCount: list.length,
        itemBuilder: (context, i) => _HandoverCard(handover: list[i]),
      ),
    );
  }
}

class _HandoverCard extends StatelessWidget {
  const _HandoverCard({required this.handover});

  final AgentHandover handover;

  @override
  Widget build(BuildContext context) {
    final color = switch (handover.status) {
      'verified' => GovColors.ok,
      'rejected' => GovColors.danger,
      _ => GovColors.blue,
    };
    final label = switch (handover.status) {
      'verified' => Strings.t('handoverVerified'),
      'rejected' => Strings.t('handoverRejected'),
      _ => Strings.t('handoverPending'),
    };

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        shape: const Border(),
        collapsedShape: const Border(),
        leading: Icon(Icons.inventory_2_outlined, color: color),
        title: Text(
          handover.batchReference,
          style: const TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.w800,
            fontFamily: 'monospace',
          ),
        ),
        subtitle: Text(
          '${handover.paymentCount} · ${formatDate(handover.createdAt)}',
          style: const TextStyle(fontSize: 11.5, color: GovColors.muted),
        ),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              formatKip(handover.amountTotal),
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: GovColors.gov,
              ),
            ),
            Container(
              margin: const EdgeInsets.only(top: 3),
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                label,
                style: TextStyle(
                  fontSize: 9.5,
                  fontWeight: FontWeight.w700,
                  color: color,
                ),
              ),
            ),
          ],
        ),
        children: [
          if (handover.verifiedAt != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: InfoRow(
                label: Strings.t('handoverVerified'),
                value: formatDateTime(handover.verifiedAt),
              ),
            ),
          ...handover.payments.map(
            (p) => Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 2),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      p.referenceNo,
                      style: const TextStyle(
                        fontSize: 11.5,
                        fontFamily: 'monospace',
                      ),
                    ),
                  ),
                  Text(
                    formatKip(p.amount),
                    style: const TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
