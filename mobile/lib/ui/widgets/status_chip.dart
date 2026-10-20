import 'package:flutter/material.dart';

import '../../core/strings.dart';
import '../../core/theme.dart';

/// A small coloured pill used for obligation and payment statuses.
class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.status, this.label});

  /// `UNPAID`, `PARTIAL`, `PAID`, `OVERPAID`, `WAIVED` (obligation)
  /// or `ISSUED`, `PAID`, `REJECTED` (payment).
  final String status;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final (color, text) = _style();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Text(
        label ?? text,
        style: TextStyle(
          color: color,
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  (Color, String) _style() {
    switch (status) {
      case 'PAID':
        return (GovColors.ok, Strings.t('statusPaid'));
      case 'PARTIAL':
        return (GovColors.gold, Strings.t('statusPartial'));
      case 'OVERPAID':
        return (GovColors.blue, Strings.t('statusOverpaid'));
      case 'WAIVED':
      case 'REFUNDED':
        return (GovColors.muted, Strings.t('statusWaived'));
      case 'REJECTED':
        return (GovColors.danger, Strings.t('statusRejected'));
      case 'ISSUED':
        return (GovColors.blue, Strings.t('statusIssued'));
      case 'UNPAID':
      case 'PENDING':
      case 'DISPUTED':
      default:
        return (GovColors.danger, Strings.t('statusUnpaid'));
    }
  }
}
