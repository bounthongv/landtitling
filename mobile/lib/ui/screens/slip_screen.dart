import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/payment.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';
import 'receipt_screen.dart';

/// The payment slip: the reference the citizen quotes at the bank.
///
/// The bank QR is a placeholder for now: the receiving account and the bank's
/// own QR format are not known yet, so the reference is what the citizen
/// types into their banking app. It is shown large, and can be copied.
class SlipScreen extends StatelessWidget {
  const SlipScreen({super.key, required this.slip});

  final IssuedSlip slip;

  Future<void> _copyReference(BuildContext context) async {
    await Clipboard.setData(ClipboardData(text: slip.referenceNo));
    if (!context.mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('ຄັດລອກລະຫັດອ້າງອີງແລ້ວ')));
  }

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
                          fontSize: 22,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 1.2,
                        ),
                      ),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(
                        onPressed: () => _copyReference(context),
                        icon: const Icon(Icons.copy, size: 18),
                        label: const Text('ຄັດລອກລະຫັດ'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: GovColors.gov,
                          side: const BorderSide(color: GovColors.gov),
                          minimumSize: const Size(0, 38),
                        ),
                      ),
                      const SizedBox(height: 14),
                      const _QrPlaceholder(),
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

/// The bank QR, as a placeholder.
///
/// The land office's receiving account and the bank's QR format are not known
/// yet, so there is nothing scannable to show. When they are, this is where
/// the real QR goes: the payload is already in `slip.slipQr`.
class _QrPlaceholder extends StatelessWidget {
  const _QrPlaceholder();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 26, horizontal: 16),
      decoration: BoxDecoration(
        color: GovColors.bg,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: GovColors.line),
      ),
      child: const Column(
        children: [
          Icon(Icons.qr_code_2, size: 54, color: GovColors.line),
          SizedBox(height: 10),
          Text(
            'QR ກຳລັງຈະເປີດ',
            style: TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color: GovColors.muted,
            ),
          ),
          SizedBox(height: 4),
          Text(
            'ປ້ອນລະຫັດອ້າງອີງຂ້າງເທິງໃສ່ໃນແອັບທະນາຄານ',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12, color: GovColors.muted),
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
