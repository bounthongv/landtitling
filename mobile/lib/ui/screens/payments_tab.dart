import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/money.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../models/payment.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/status_chip.dart';
import 'payment_detail_screen.dart';

/// The citizen's payment history.
class PaymentsTab extends StatefulWidget {
  const PaymentsTab({super.key});

  @override
  State<PaymentsTab> createState() => _PaymentsTabState();
}

class _PaymentsTabState extends State<PaymentsTab> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final controller = context.read<PaymentsController>();
      if (controller.payments.isEmpty) controller.load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final controller = context.watch<PaymentsController>();

    if (controller.loading && controller.payments.isEmpty) {
      return const LoadingView();
    }
    if (controller.error != null && controller.payments.isEmpty) {
      return ErrorView(
        message: controller.error!,
        onRetry: () => controller.load(),
      );
    }
    if (controller.payments.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => controller.load(silent: true),
        child: ListView(
          children: [
            SizedBox(
              height: MediaQuery.of(context).size.height * 0.6,
              child: EmptyState(
                icon: Icons.receipt_outlined,
                title: Strings.t('noPayments'),
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () => controller.load(silent: true),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 24),
        children: controller.payments.map(_card).toList(),
      ),
    );
  }

  Widget _card(Payment p) {
    final status = p.isRejected
        ? 'REJECTED'
        : p.isPaid
        ? 'PAID'
        : p.isPendingReview
        ? 'PENDING'
        : 'ISSUED';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => PaymentDetailScreen(paymentId: p.id),
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        p.referenceNo,
                        style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.6,
                        ),
                      ),
                    ),
                    StatusChip(status: status),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  [
                    '${p.feeYear}',
                    p.parcelNo ?? '',
                  ].where((s) => s.isNotEmpty).join(' · '),
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: GovColors.muted,
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        formatKip(p.amount),
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    Text(
                      formatDateTime(p.createdAt),
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: GovColors.muted,
                      ),
                    ),
                  ],
                ),
                if (!p.hasReceipt && !p.isPaid) ...[
                  const SizedBox(height: 8),
                  const Text(
                    'ຍັງບໍ່ໄດ້ອັບໂຫລດສະລິບເງິນ',
                    style: TextStyle(fontSize: 12, color: GovColors.danger),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
