import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/payment.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import 'receipt_screen.dart';

/// The payment slip: the reference the citizen quotes at the bank and the QR
/// that identifies the payment.
class SlipScreen extends StatelessWidget {
  const SlipScreen({super.key, required this.slip});

  final IssuedSlip slip;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          const GovHeader(title: 'ສະລິບໄດ້ຊຳລະ', subtitle: 'Payment slip'),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 26),
              children: [
                SectionCard(
                  child: Column(
                    children: [
                      Text(
                        Strings.t('reference'),
                        style: const TextStyle(
                          fontSize: 12,
                          color: GovColors.muted,
                        ),
                      ),
                      const SizedBox(height: 4),
                      SelectableText(
                        slip.referenceNo,
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 1.2,
                        ),
                      ),
                      const SizedBox(height: 14),
                      if (slip.slipQr.isNotEmpty)
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            border: Border.all(color: GovColors.line),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: QrImageView(
                            data: slip.slipQr,
                            version: QrVersions.auto,
                            size: 170,
                            backgroundColor: Colors.white,
                          ),
                        ),
                      const SizedBox(height: 14),
                      InfoRow(
                        label: Strings.t('amountDue'),
                        value: formatKip(slip.amount),
                        bold: true,
                        valueColor: GovColors.gov,
                      ),
                      if (slip.dueDate != null)
                        InfoRow(
                          label: Strings.t('dueDate'),
                          value: formatDate(slip.dueDate),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'ຂັ້ນຕອນຕໍ່ໄປ',
                  icon: Icons.list_alt,
                  child: const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _NextStep(no: '1', text: 'ຊຳລະຈຳນວນເງິນຕາມສະລິບນີ້'),
                      _NextStep(no: '2', text: 'ຖ່າຍຮູບສະລິບເງິນທີ່ຊຳລະ'),
                      _NextStep(no: '3', text: 'ອັບໂຫລດຮູບສະລິບເງິນໃນແອັບ'),
                      _NextStep(
                        no: '4',
                        text: 'ພະນັກງານກວດສອບ ແລະ ຢືນຢັນການຊຳລະ',
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 18),
                FilledButton.icon(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ReceiptScreen(paymentId: slip.id),
                    ),
                  ),
                  icon: const Icon(Icons.camera_alt_outlined),
                  label: Text(Strings.t('uploadSlip')),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: () {
                    final controller = context.read<PaymentsController>();
                    controller.load(silent: true);
                    Navigator.of(context).popUntil((route) => route.isFirst);
                  },
                  icon: const Icon(Icons.check),
                  label: Text(Strings.t('saved')),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _NextStep extends StatelessWidget {
  const _NextStep({required this.no, required this.text});

  final String no;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 22,
            height: 22,
            alignment: Alignment.center,
            decoration: const BoxDecoration(
              color: GovColors.gov,
              shape: BoxShape.circle,
            ),
            child: Text(
              no,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 11,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: const TextStyle(
                fontSize: 13,
                color: GovColors.ink,
                height: 1.5,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
