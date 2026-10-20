import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/payment.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import '../widgets/status_chip.dart';
import 'receipt_screen.dart';

/// One payment in detail: what was paid, what state it is in, and what to do
/// next (upload the receipt, or see why it was rejected).
class PaymentDetailScreen extends StatelessWidget {
  const PaymentDetailScreen({super.key, required this.paymentId});

  final int paymentId;

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<PaymentsController>();
    Payment? payment;
    for (final p in controller.payments) {
      if (p.id == paymentId) {
        payment = p;
        break;
      }
    }

    if (payment == null) {
      return Scaffold(
        appBar: AppBar(title: Text(Strings.t('myPayments'))),
        body: const ErrorView(message: 'ບໍ່ພົບຂໍ້ມູນການຊຳລະນີ້'),
      );
    }

    final p = payment;
    final status = p.isRejected
        ? 'REJECTED'
        : p.isPaid
        ? 'PAID'
        : p.isPendingReview
        ? 'PENDING'
        : 'ISSUED';

    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: p.referenceNo,
            subtitle: '${Strings.t('year')} ${p.feeYear}',
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 26),
              children: [
                SectionCard(
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              formatKip(p.amount),
                              style: const TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          StatusChip(status: status),
                        ],
                      ),
                      const SizedBox(height: 10),
                      InfoRow(
                        label: Strings.t('reference'),
                        value: p.referenceNo,
                      ),
                      if (p.parcelNo != null)
                        InfoRow(
                          label: Strings.t('parcelNo'),
                          value: p.parcelNo!,
                        ),
                      if (p.districtName != null)
                        InfoRow(
                          label: Strings.t('district'),
                          value: p.districtName!,
                        ),
                      InfoRow(
                        label: Strings.t('method'),
                        value: _methodLabel(p.method),
                      ),
                      InfoRow(
                        label: 'ສ້າງ',
                        value: formatDateTime(p.createdAt),
                      ),
                      if (p.paidAt != null)
                        InfoRow(
                          label: 'ຢືນຢັນ',
                          value: formatDateTime(p.paidAt),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'ສະຖານະ',
                  icon: Icons.timeline,
                  child: _timeline(p),
                ),
                if (p.isRejected && p.verifyNote != null) ...[
                  const SizedBox(height: 12),
                  SectionCard(
                    title: 'ເຫດຜົນການປະດົວມ',
                    icon: Icons.error_outline,
                    child: Text(
                      p.verifyNote!,
                      style: const TextStyle(
                        fontSize: 13,
                        color: GovColors.danger,
                        height: 1.5,
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                if (!p.isPaid && !p.isRejected)
                  FilledButton.icon(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => ReceiptScreen(paymentId: p.id),
                      ),
                    ),
                    icon: const Icon(Icons.camera_alt_outlined),
                    label: Text(
                      p.hasReceipt
                          ? Strings.t('uploadSlip')
                          : Strings.t('uploadSlip'),
                    ),
                  ),
                if (!p.isPaid) ...[
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
                    onPressed: () => _openSlip(context, p),
                    icon: const Icon(Icons.description_outlined),
                    label: Text(Strings.t('openInBrowser')),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _timeline(Payment p) {
    final steps = <(String, bool, String)>[
      ('ອໍານາການຊຳລະ', true, formatDateTime(p.createdAt)),
      (
        'ອັບໂຫລດສະລິບເງິນ',
        p.hasReceipt,
        p.hasReceipt ? Strings.t('saved') : 'ລະວ່າງ',
      ),
      if (p.isPaid) ('ຢືນຢັນໂດຍພະນັກງານ', true, formatDateTime(p.paidAt)),
      if (p.isRejected) ('ໄມ່ຮັບ', true, Strings.t('statusRejected')),
    ];

    return Column(
      children: steps.map((step) {
        final done = step.$2;
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                done ? Icons.check_circle : Icons.radio_button_unchecked,
                size: 18,
                color: done ? GovColors.ok : GovColors.muted,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      step.$1,
                      style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w600,
                        color: done ? GovColors.ink : GovColors.muted,
                      ),
                    ),
                    Text(
                      step.$3,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: GovColors.muted,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      }).toList(),
    );
  }

  String _methodLabel(String method) {
    switch (method) {
      case 'bank_transfer':
        return Strings.t('methodBank');
      case 'mobile':
        return Strings.t('methodMobile');
      case 'cash':
      case 'counter':
        return Strings.t('methodCounter');
      default:
        return method;
    }
  }

  Future<void> _openSlip(BuildContext context, Payment p) async {
    final messenger = ScaffoldMessenger.of(context);
    final base = Uri.parse(AppConfig.apiBase);
    final url = base.replace(
      path: '${base.path}/payments.php',
      queryParameters: {'id': p.id.toString(), 'slip': '1'},
    );
    final ok = await launchUrl(url, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      messenger.showSnackBar(SnackBar(content: Text(Strings.t('noBrowser'))));
    }
  }
}
