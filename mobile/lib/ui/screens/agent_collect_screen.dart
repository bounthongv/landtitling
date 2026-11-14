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
import 'agent_receipt_screen.dart';

/// Record a cash collection for one obligation.
class AgentCollectScreen extends StatefulWidget {
  const AgentCollectScreen({super.key, required this.obligation});

  final AgentObligation obligation;

  @override
  State<AgentCollectScreen> createState() => _AgentCollectScreenState();
}

class _AgentCollectScreenState extends State<AgentCollectScreen> {
  late final TextEditingController _name;
  late final TextEditingController _phone;
  late final TextEditingController _idCard;
  late final TextEditingController _amount;

  final _formKey = GlobalKey<FormState>();

  @override
  void initState() {
    super.initState();
    final o = widget.obligation;
    _name = TextEditingController(text: o.ownerName);
    _phone = TextEditingController(text: o.ownerPhone ?? '');
    _idCard = TextEditingController(text: o.ownerIdNumber ?? '');
    _amount = TextEditingController(text: o.outstanding.round().toString());
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _idCard.dispose();
    _amount.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final ctrl = context.read<AgentController>();
    final result = await ctrl.collectCash(
      obligationId: widget.obligation.id,
      payerName: _name.text.trim(),
      payerPhone: _phone.text.trim(),
      payerIdCard: _idCard.text.trim(),
      amount: double.tryParse(_amount.text.trim()),
    );
    if (!mounted) return;

    if (result == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(ctrl.error ?? Strings.t('retry'))));
      return;
    }

    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _SuccessDialog(result: result),
    );
    if (mounted) {
      Navigator.of(context).pop(true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = context.watch<AgentController>();
    final o = widget.obligation;

    return Scaffold(
      backgroundColor: GovColors.bg,
      body: Column(
        children: [
          GovHeader(
            title: Strings.t('collectCashTitle'),
            subtitle: o.parcelNo,
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 28),
                children: [
                  _ObligationSummaryCard(obligation: o),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: Strings.t('payerName'),
                    icon: Icons.person_outline,
                    child: Column(
                      children: [
                        TextFormField(
                          controller: _name,
                          textCapitalization: TextCapitalization.words,
                          decoration: InputDecoration(
                            labelText: Strings.t('payerName'),
                          ),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? Strings.t('payerName')
                              : null,
                        ),
                        const SizedBox(height: 10),
                        TextFormField(
                          controller: _phone,
                          keyboardType: TextInputType.phone,
                          decoration: InputDecoration(
                            labelText: Strings.t('payerPhone'),
                            hintText: '020 5555 1234',
                          ),
                          validator: (v) {
                            final s = (v ?? '').trim();
                            if (s.isEmpty) return Strings.t('payerPhone');
                            if (!RegExp(r'^\+?[0-9]{8,15}$').hasMatch(s)) {
                              return Strings.t('payerPhone');
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: 10),
                        TextFormField(
                          controller: _idCard,
                          keyboardType: TextInputType.text,
                          decoration: InputDecoration(
                            labelText: Strings.t('payerIdCard'),
                          ),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? Strings.t('payerIdCard')
                              : null,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: Strings.t('amountDue'),
                    icon: Icons.payments_outlined,
                    child: TextFormField(
                      controller: _amount,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: false,
                      ),
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: InputDecoration(
                        labelText: Strings.t('amountDue'),
                        suffixText: 'ກີບ',
                      ),
                      validator: (v) {
                        final n = double.tryParse((v ?? '').trim());
                        if (n == null || n <= 0) return Strings.t('amountDue');
                        if (n > o.outstanding + 0.005) {
                          return formatKip(o.outstanding);
                        }
                        return null;
                      },
                    ),
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: GovColors.gold.withValues(alpha: 0.10),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(
                        color: GovColors.gold.withValues(alpha: 0.45),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(
                          Icons.info_outline,
                          size: 18,
                          color: GovColors.gov,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            Strings.t('collectSuccessHint'),
                            style: const TextStyle(
                              fontSize: 12,
                              height: 1.45,
                              color: GovColors.ink,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: ctrl.busy ? null : _submit,
                    icon: ctrl.busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Icon(Icons.check_circle_outline),
                    label: Text(Strings.t('collectConfirm')),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ObligationSummaryCard extends StatelessWidget {
  const _ObligationSummaryCard({required this.obligation});

  final AgentObligation obligation;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: obligation.parcelNo,
      icon: Icons.landscape_outlined,
      child: Column(
        children: [
          InfoRow(label: Strings.t('owner'), value: obligation.ownerName),
          InfoRow(
            label: Strings.t('village'),
            value: '${obligation.villageName}, ${obligation.districtName}',
          ),
          if (obligation.areaSqm != null)
            InfoRow(
              label: Strings.t('area'),
              value: formatArea(obligation.areaSqm),
            ),
          InfoRow(label: Strings.t('year'), value: '${obligation.feeYear}'),
          InfoRow(
            label: Strings.t('penalty'),
            value: formatKip(obligation.penalty),
            valueColor: obligation.penalty > 0 ? GovColors.danger : null,
          ),
          InfoRow(
            label: Strings.t('outstanding'),
            value: formatKip(obligation.outstanding),
            valueColor: GovColors.gov,
            bold: true,
          ),
        ],
      ),
    );
  }
}

class _SuccessDialog extends StatelessWidget {
  const _SuccessDialog({required this.result});

  final AgentCollectResult result;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      icon: const Icon(Icons.check_circle, color: GovColors.ok, size: 40),
      title: Text(Strings.t('collectSuccess')),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InfoRow(label: Strings.t('reference'), value: result.referenceNo),
          InfoRow(
            label: Strings.t('amountDue'),
            value: formatKip(result.amount),
          ),
          InfoRow(
            label: Strings.t('status'),
            value: Strings.t('statusSubmitted'),
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
          onPressed: () => Navigator.of(context).pop(),
          child: Text(Strings.t('cancel')),
        ),
        FilledButton(
          onPressed: () {
            Navigator.of(context).pop();
            Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => AgentReceiptScreen(paymentId: result.paymentId),
              ),
            );
          },
          child: Text(Strings.t('viewReceipt')),
        ),
      ],
    );
  }
}
