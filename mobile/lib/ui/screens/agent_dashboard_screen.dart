import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/agent.dart';
import '../../state/agent_controller.dart';
import '../../state/auth_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import 'agent_collect_screen.dart';
import 'agent_handovers_screen.dart';

/// Agent dashboard — the unpaid obligations in the agent's village.
class AgentDashboardScreen extends StatefulWidget {
  const AgentDashboardScreen({super.key});

  @override
  State<AgentDashboardScreen> createState() => _AgentDashboardScreenState();
}

class _AgentDashboardScreenState extends State<AgentDashboardScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<AgentController>().loadObligations();
    });
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = context.watch<AgentController>();
    final auth = context.watch<AuthController>();

    return Scaffold(
      backgroundColor: GovColors.bg,
      body: Column(
        children: [
          GovHeader(
            title: Strings.t('agentDashboard'),
            subtitle: auth.agentVillageName ?? Strings.t('agentMode'),
            actions: [
              IconButton(
                tooltip: Strings.t('viewHandovers'),
                onPressed: () => _openHandovers(context),
                icon: const Icon(
                  Icons.inventory_2_outlined,
                  color: Colors.white,
                ),
              ),
              IconButton(
                tooltip: Strings.t('signOut'),
                onPressed: () => auth.signOut(),
                icon: const Icon(Icons.logout, color: Colors.white),
              ),
            ],
          ),
          _SummaryBar(ctrl: ctrl),
          Expanded(child: _buildBody(context, ctrl)),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openHandovers(context),
        icon: const Icon(Icons.inventory_2_outlined),
        label: Text(Strings.t('viewHandovers')),
        backgroundColor: GovColors.gold,
        foregroundColor: Colors.black,
      ),
    );
  }

  void _openHandovers(BuildContext context) {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const AgentHandoversScreen()),
    );
  }

  Widget _buildBody(BuildContext context, AgentController ctrl) {
    if (ctrl.busy && ctrl.obligations.isEmpty) {
      return const LoadingView();
    }
    if (ctrl.error != null && ctrl.obligations.isEmpty) {
      return ErrorView(
        message: ctrl.error!,
        onRetry: () => ctrl.loadObligations(),
      );
    }
    if (ctrl.obligations.isEmpty) {
      return EmptyState(
        icon: Icons.account_balance_wallet_outlined,
        title: Strings.t('noObligationsForCollection'),
        hint: Strings.t('noObligationsHint'),
        action: OutlinedButton.icon(
          onPressed: () => ctrl.loadObligations(),
          icon: const Icon(Icons.refresh),
          label: Text(Strings.t('refresh')),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () => ctrl.loadObligations(),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 96),
        itemCount: ctrl.obligations.length,
        itemBuilder: (context, index) {
          final ob = ctrl.obligations[index];
          return _ObligationCard(obligation: ob);
        },
      ),
    );
  }
}

class _SummaryBar extends StatelessWidget {
  const _SummaryBar({required this.ctrl});

  final AgentController ctrl;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: GovColors.card,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      child: Row(
        children: [
          const Icon(Icons.landscape_outlined, size: 16, color: GovColors.gov),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              '${ctrl.obligations.length} ${Strings.t('statusUnpaid')}',
              style: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: GovColors.ink,
              ),
            ),
          ),
          Text(
            DateTime.now().year.toString(),
            style: const TextStyle(fontSize: 12.5, color: GovColors.muted),
          ),
        ],
      ),
    );
  }
}

class _ObligationCard extends StatelessWidget {
  const _ObligationCard({required this.obligation});

  final AgentObligation obligation;

  @override
  Widget build(BuildContext context) {
    final days = obligation.daysOverdue;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    obligation.subParcelNo == null ||
                            obligation.subParcelNo!.isEmpty
                        ? obligation.parcelNo
                        : '${obligation.parcelNo} / ${obligation.subParcelNo}',
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                      color: GovColors.ink,
                    ),
                  ),
                ),
                _StatusChip(status: obligation.status),
              ],
            ),
            const SizedBox(height: 6),
            _iconLine(Icons.person_outline, obligation.ownerName),
            if (obligation.ownerPhone != null &&
                obligation.ownerPhone!.isNotEmpty)
              _iconLine(Icons.phone_outlined, obligation.ownerPhone!),
            _iconLine(
              Icons.location_on_outlined,
              '${obligation.villageName}, ${obligation.districtName}',
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(11),
              decoration: BoxDecoration(
                color: GovColors.gov.withValues(alpha: 0.05),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: GovColors.line),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: _moneyColumn(
                      Strings.t('outstanding'),
                      formatKip(obligation.outstanding),
                      GovColors.gov,
                      big: true,
                    ),
                  ),
                  if (obligation.penalty > 0)
                    Expanded(
                      child: _moneyColumn(
                        Strings.t('penalty'),
                        '+${formatKip(obligation.penalty)}',
                        GovColors.danger,
                      ),
                    ),
                ],
              ),
            ),
            if (days != null && days > 0) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  const Icon(
                    Icons.warning_amber_rounded,
                    size: 15,
                    color: GovColors.danger,
                  ),
                  const SizedBox(width: 4),
                  Text(
                    '${Strings.t('overdue')} $days ${Strings.t('days')}',
                    style: const TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      color: GovColors.danger,
                    ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => AgentCollectScreen(obligation: obligation),
                  ),
                ),
                icon: const Icon(Icons.payments_outlined),
                label: Text(Strings.t('collectCash')),
                style: FilledButton.styleFrom(
                  backgroundColor: GovColors.gold,
                  foregroundColor: Colors.black,
                  minimumSize: const Size.fromHeight(46),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _iconLine(IconData icon, String text) => Padding(
    padding: const EdgeInsets.only(top: 2),
    child: Row(
      children: [
        Icon(icon, size: 15, color: GovColors.muted),
        const SizedBox(width: 5),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(fontSize: 12.5, color: GovColors.muted),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    ),
  );

  Widget _moneyColumn(
    String label,
    String value,
    Color color, {
    bool big = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 10.5,
            fontWeight: FontWeight.w600,
            color: color.withValues(alpha: 0.85),
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: TextStyle(
            fontSize: big ? 17 : 13.5,
            fontWeight: FontWeight.w800,
            color: color,
          ),
        ),
      ],
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final color = switch (status) {
      'UNPAID' => GovColors.danger,
      'PARTIAL' => GovColors.blue,
      'PAID' || 'OVERPAID' => GovColors.ok,
      'WAIVED' => GovColors.muted,
      'REFUNDED' => GovColors.blue,
      'DISPUTED' => GovColors.danger,
      _ => GovColors.muted,
    };
    final label = switch (status) {
      'UNPAID' => Strings.t('statusUnpaid'),
      'PARTIAL' => Strings.t('statusPartial'),
      'PAID' => Strings.t('statusPaid'),
      'OVERPAID' => Strings.t('statusOverpaid'),
      'WAIVED' => Strings.t('statusWaived'),
      'REFUNDED' => Strings.t('statusRefunded'),
      'DISPUTED' => Strings.t('statusDisputed'),
      _ => status,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withValues(alpha: 0.5)),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 10.5,
          fontWeight: FontWeight.w700,
          color: color,
        ),
      ),
    );
  }
}
