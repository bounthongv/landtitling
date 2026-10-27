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
                child: Text(
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
                  'ສາທາລະນະລັດ ປະຊາທິປະໄຕ ປະຊາຊົນລາວ\nກະຊວງກະສິກຳ ແລະ ສິ່ງແວດລ້ອມ',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.9),
                    fontSize: 10.5,
                    height: 1.3,
                  ),
                ),
              ),
              if (actions != null) ...actions!,
              if (onBack != null)
                IconButton(
                  onPressed: onBack,
                  icon: const Icon(Icons.arrow_back, color: Colors.white),
                  tooltip: 'Back',
                ),
            ],
          ),
          if (title != null) ...[
            const SizedBox(height: 8),
            Text(
              title!,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 17,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
          if (subtitle != null) ...[
            const SizedBox(height: 2),
            Text(
              subtitle!,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.85),
                fontSize: 12,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
