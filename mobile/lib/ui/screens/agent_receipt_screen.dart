import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/agent.dart';
import '../../state/agent_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// Native receipt for one cash collection.
class AgentReceiptScreen extends StatefulWidget {
  const AgentReceiptScreen({super.key, required this.paymentId});

  final int paymentId;

  @override
  State<AgentReceiptScreen> createState() => _AgentReceiptScreenState();
}

class _AgentReceiptScreenState extends State<AgentReceiptScreen> {
  AgentReceiptDetail? receipt;
  String? error;
  bool loading = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() {
      loading = true;
      error = null;
    });
    final r = await context.read<AgentController>().fetchReceipt(
      widget.paymentId,
    );
    if (!mounted) return;
    setState(() {
      receipt = r;
      error = r == null ? Strings.t('retry') : null;
      loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GovColors.bg,
      body: Column(
        children: [
          GovHeader(
            title: Strings.t('receipt'),
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(child: _buildBody()),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (loading) return const LoadingView();
    if (receipt == null) {
      return ErrorView(message: error ?? Strings.t('retry'), onRetry: _load);
    }

    final r = receipt!;
    return ListView(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 28),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                Container(
                  width: 54,
                  height: 54,
                  decoration: BoxDecoration(
                    color: GovColors.ok.withValues(alpha: 0.10),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.verified_outlined,
                    color: GovColors.ok,
                    size: 30,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  Strings.t('receipt'),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: GovColors.ink,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  r.agentUsername ?? '',
                  style: const TextStyle(fontSize: 12, color: GovColors.muted),
                ),
                const SizedBox(height: 14),
                Text(
                  formatKip(r.amount),
                  style: const TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w800,
                    color: GovColors.gov,
                  ),
                ),
                const SizedBox(height: 14),
                const Divider(height: 1),
                const SizedBox(height: 10),
                _kv(Strings.t('reference'), r.referenceNo, mono: true),
                _kv(Strings.t('status'), _statusLabel(r.status)),
                _kv(Strings.t('payerName'), r.payerName),
                if (r.payerPhone != null)
                  _kv(Strings.t('payerPhone'), r.payerPhone!),
                if (r.payerIdCard != null)
                  _kv(Strings.t('payerIdCard'), r.payerIdCard!),
                if (r.parcelNo != null)
                  _kv(
                    Strings.t('parcelNo'),
                    r.subParcelNo == null || r.subParcelNo!.isEmpty
                        ? r.parcelNo!
                        : '${r.parcelNo!} / ${r.subParcelNo!}',
                  ),
                if (r.mapRefNo != null) _kv(Strings.t('mapSheet'), r.mapRefNo!),
                if (r.villageName != null)
                  _kv(
                    Strings.t('village'),
                    [
                      r.villageName,
                      r.districtName,
                    ].whereType<String>().join(', '),
                  ),
                if (r.feeYear != null) _kv(Strings.t('year'), '${r.feeYear}'),
                if (r.collectedAt != null)
                  _kv(Strings.t('collectedAt'), formatDateTime(r.collectedAt)),
                if (r.handedOverAt != null)
                  _kv(
                    Strings.t('sendToDistrict'),
                    formatDateTime(r.handedOverAt),
                  ),
                const SizedBox(height: 12),
                const Divider(height: 1),
                const SizedBox(height: 10),
                Text(
                  Strings.t('collectSuccessHint'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 11.5,
                    height: 1.5,
                    color: GovColors.muted,
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),
        OutlinedButton.icon(
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: r.referenceNo));
            if (!mounted) return;
            ScaffoldMessenger.of(
              context,
            ).showSnackBar(SnackBar(content: Text(Strings.t('saved'))));
          },
          icon: const Icon(Icons.copy_outlined),
          label: Text('${Strings.t('reference')}: ${r.referenceNo}'),
        ),
      ],
    );
  }

  String _statusLabel(String status) => switch (status) {
    'ISSUED' => Strings.t('statusIssued'),
    'SUBMITTED' => Strings.t('statusSubmitted'),
    'PAID' => Strings.t('statusPaid'),
    'REJECTED' => Strings.t('statusRejected'),
    'REFUNDED' => Strings.t('statusRefunded'),
    _ => status,
  };

  Widget _kv(String label, String value, {bool mono = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 4,
            child: Text(
              label,
              style: const TextStyle(fontSize: 12.5, color: GovColors.muted),
            ),
          ),
          Expanded(
            flex: 6,
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                fontFamily: mono ? 'monospace' : null,
                color: GovColors.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
