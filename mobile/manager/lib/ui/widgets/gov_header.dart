import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// The government header used across the legacy portal: a green gradient bar
/// with the gold emblem and the ministry line.
class GovHeader extends StatelessWidget {
  const GovHeader({
    super.key,
    this.title,
    this.subtitle,
    this.onBack,
    this.actions,
  });

  final String? title;
  final String? subtitle;
  final VoidCallback? onBack;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [GovColors.gov, GovColors.gov2],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                alignment: Alignment.center,
                decoration: const BoxDecoration(
                  color: GovColors.gold,
                  shape: BoxShape.circle,
                ),
                child: const Text(
                  'ລາວ',
                  style: TextStyle(
                    color: GovColors.gov,
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'ສາທາລະນະລັດ ປະຊາທິປະໄຕ ປະຊາຊົນລາວ\nກະຊວງກະສິກໍາ ແລະ ສິ່ງແວດລ້ອມ',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.9),
                    fontSize: 10.5,
                    height: 1.35,
                  ),
                ),
              ),
              if (actions != null) ...actions!,
            ],
          ),
          if (title != null) ...[
            const SizedBox(height: 10),
            Text(
              title!,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 19,
                fontWeight: FontWeight.w800,
              ),
            ),
            if (subtitle != null)
              Text(
                subtitle!,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.85),
                  fontSize: 12.5,
                ),
              ),
          ],
          if (onBack != null) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: onBack,
                style: TextButton.styleFrom(
                  foregroundColor: Colors.white,
                  padding: EdgeInsets.zero,
                ),
                icon: const Icon(Icons.arrow_back, size: 18),
                label: const Text('ກັບຄືນ', style: TextStyle(fontSize: 13)),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
