import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/config.dart';
import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../state/auth_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// The citizen's own account: identity, language, and sign-out.
class AccountTab extends StatefulWidget {
  const AccountTab({super.key});

  @override
  State<AccountTab> createState() => _AccountTabState();
}

class _AccountTabState extends State<AccountTab> {
  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();

    return Column(
      children: [
        const GovHeader(title: 'ບ້ານຂ້ອຍ', subtitle: 'My account'),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 26),
            children: [
              SectionCard(
                title: 'ຂໍ້ມູນບັນຊີ',
                icon: Icons.person_outline,
                child: Column(
                  children: [
                    InfoRow(label: 'ເບີໂທ', value: auth.phone ?? '-'),
                    InfoRow(label: 'ບົບທີ່', value: auth.session?.role ?? '-'),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              SectionCard(
                title: Strings.t('language'),
                icon: Icons.translate,
                child: RadioGroup<String>(
                  groupValue: Strings.isLao ? 'lo' : 'en',
                  onChanged: (v) {
                    if (v == null) return;
                    setState(
                      () => Strings.setLanguage(
                        v == 'lo' ? AppLang.lo : AppLang.en,
                      ),
                    );
                  },
                  child: Column(
                    children: [
                      RadioListTile<String>(
                        value: 'lo',
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        title: const Text(
                          'ລາວ',
                          style: TextStyle(fontSize: 14),
                        ),
                      ),
                      RadioListTile<String>(
                        value: 'en',
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        title: const Text(
                          'English',
                          style: TextStyle(fontSize: 14),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              SectionCard(
                title: Strings.t('about'),
                icon: Icons.info_outline,
                child: Column(
                  children: [
                    InfoRow(
                      label: Strings.t('appTitle'),
                      value: '${AppConfig.appNameLo} / ${AppConfig.appNameEn}',
                    ),
                    InfoRow(label: Strings.t('version'), value: '1.0.0'),
                    InfoRow(
                      label: Strings.t('appSubtitle'),
                      value: AppConfig.provinceLo,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 18),
              OutlinedButton.icon(
                onPressed: () async {
                  final messenger = ScaffoldMessenger.of(context);
                  await context.read<AuthController>().signOut();
                  messenger.showSnackBar(
                    const SnackBar(content: Text('ອອກຈາກລະບົບແລ້ວ')),
                  );
                },
                icon: const Icon(Icons.logout),
                label: Text(Strings.t('signOut')),
                style: OutlinedButton.styleFrom(
                  foregroundColor: GovColors.danger,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
