import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/obligation.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import 'slip_screen.dart';

/// Review the assessed fee for one year, choose how much to pay and how, then
/// issue the payment slip.
///
/// The amounts shown come from the server's assessment — the app never
/// recomputes the fee.
class PayScreen extends StatefulWidget {
  const PayScreen({super.key, required this.obligation});

  final Obligation obligation;

  @override
  State<PayScreen> createState() => _PayScreenState();
}

class _PayScreenState extends State<PayScreen> {
  static const _methods = <String, String>{
    'bank_transfer': 'methodBank',
    'mobile': 'methodMobile',
    'counter': 'methodCounter',
  };

  String _method = 'bank_transfer';
  bool _payInFull = true;
  late final TextEditingController _amountController;

  @override
  void initState() {
    super.initState();
    _amountController = TextEditingController(
      text: _format(widget.obligation.outstanding),
    );
  }

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  String _format(double v) => v.round().toString();

  double get _amount {
    final parsed = double.tryParse(_amountController.text.trim());
    if (parsed == null) return 0;
    return parsed.clamp(0, widget.obligation.outstanding).toDouble();
  }

  Future<void> _issue() async {
    final payments = context.read<PaymentsController>();
    final messenger = ScaffoldMessenger.of(context);
    try {
      final slip = await payments.issueSlip(
        obligationId: widget.obligation.id,
        method: _method,
        amount: _payInFull ? null : _amount,
      );
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(builder: (_) => SlipScreen(slip: slip)),
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text('$e'), backgroundColor: GovColors.danger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = widget.obligation;
    final payments = context.watch<PaymentsController>();

    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: Strings.t('viewFee'),
            subtitle: '${Strings.t('year')} ${o.feeYear}',
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 26),
              children: [
                SectionCard(
                  title: 'ຄົນລືມຕ້ອງຊຳລະ',
                  icon: Icons.receipt_long_outlined,
                  child: Column(
                    children: [
                      InfoRow(
                        label: Strings.t('amountDue'),
                        value: formatKip(o.amountDue),
                      ),
                      if (o.ratePerSqm != null && o.areaSqm != null)
                        InfoRow(
                          label: 'ຄ່າຕ້ອງເມີ',
                          value:
                              '${formatKipPlain(o.ratePerSqm)} × ${formatArea(o.areaSqm)}',
                        ),
                      if (o.penalty > 0)
                        InfoRow(
                          label: Strings.t('penalty'),
                          value: formatKip(o.penalty),
                          valueColor: GovColors.danger,
                        ),
                      const Divider(height: 18),
                      InfoRow(
                        label: Strings.t('outstanding'),
                        value: formatKip(o.outstanding),
                        valueColor: GovColors.danger,
                        bold: true,
                      ),
                      InfoRow(
                        label: Strings.t('dueDate'),
                        value: formatDate(o.dueDate),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: Strings.t('method'),
                  icon: Icons.account_balance_outlined,
                  child: RadioGroup<String>(
                    groupValue: _method,
                    onChanged: (v) {
                      if (v != null) setState(() => _method = v);
                    },
                    child: Column(
                      children: _methods.entries.map((entry) {
                        return RadioListTile<String>(
                          value: entry.key,
                          contentPadding: EdgeInsets.zero,
                          dense: true,
                          title: Text(
                            Strings.t(entry.value),
                            style: const TextStyle(fontSize: 14),
                          ),
                          subtitle: _methodHint(entry.key),
                        );
                      }).toList(),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: Strings.t('fullAmount'),
                  icon: Icons.payments_outlined,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SwitchListTile(
                        value: _payInFull,
                        onChanged: (v) => setState(() => _payInFull = v),
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        title: Text(
                          _payInFull
                              ? '${Strings.t('fullAmount')} (${formatKip(o.outstanding)})'
                              : Strings.t('partialAmount'),
                          style: const TextStyle(fontSize: 14),
                        ),
                      ),
                      if (!_payInFull) ...[
                        const SizedBox(height: 6),
                        TextField(
                          controller: _amountController,
                          keyboardType: TextInputType.number,
                          inputFormatters: [
                            FilteringTextInputFormatter.digitsOnly,
                          ],
                          decoration: InputDecoration(
                            labelText: Strings.t('amountDue'),
                            suffixText: 'ກີບ',
                          ),
                          onChanged: (_) => setState(() {}),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          '${Strings.t('outstanding')}: ${formatKip(o.outstanding)}',
                          style: const TextStyle(
                            fontSize: 12,
                            color: GovColors.muted,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed:
                      payments.issuing || (_payInFull ? false : _amount <= 0)
                      ? null
                      : _issue,
                  child: payments.issuing
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : Text(Strings.t('paymentSlip')),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget? _methodHint(String method) {
    switch (method) {
      case 'bank_transfer':
        return const Text(
          'ສະລິບເງິນຜ່ານທະນາຄານ ແລ້ວອັບໂຫລດສະລິບໃນແອັບ',
          style: TextStyle(fontSize: 11.5, color: GovColors.muted),
        );
      case 'mobile':
        return const Text(
          'ຊຳລະຜ່ານທະນາຄານມືຖື',
          style: TextStyle(fontSize: 11.5, color: GovColors.muted),
        );
      case 'counter':
        return const Text(
          'ມາຊຳລະທີ່ຫຼັງການ ແລະ ແຈ້ງງົນແລ້ວ',
          style: TextStyle(fontSize: 11.5, color: GovColors.muted),
        );
      default:
        return null;
    }
  }
}
