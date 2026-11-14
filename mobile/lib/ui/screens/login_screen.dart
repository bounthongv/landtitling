import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../state/auth_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// Sign in — citizen by phone number, agent by username + password.
///
/// The phone number is the citizen identifier — nearly everyone has a mobile
/// phone, and most do not use email. Nothing is sent anywhere and no code is
/// typed: the first time a number is used the account is created, and after
/// that the same number signs in. Agents are staff accounts flagged by the
/// district, so they use the username and password issued to them.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phoneController = TextEditingController();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _phoneFocus = FocusNode();

  bool _agentMode = false;

  @override
  void dispose() {
    _phoneController.dispose();
    _usernameController.dispose();
    _passwordController.dispose();
    _phoneFocus.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final auth = context.read<AuthController>();
    FocusScope.of(context).unfocus();
    if (_agentMode) {
      await auth.signInAgent(
        username: _usernameController.text,
        password: _passwordController.text,
      );
    } else {
      await auth.signIn(phone: _phoneController.text);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();

    return Scaffold(
      body: Column(
        children: [
          const GovHeader(
            title: 'ປ່ອງບໍລິການສາທາລະນະ',
            subtitle: 'Citizen service center',
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 18, 16, 28),
              children: [
                SegmentedButton<bool>(
                  segments: [
                    ButtonSegment(
                      value: false,
                      icon: const Icon(Icons.person_outline),
                      label: Text(Strings.t('citizenSignIn')),
                    ),
                    ButtonSegment(
                      value: true,
                      icon: const Icon(Icons.badge_outlined),
                      label: Text(Strings.t('agentSignIn')),
                    ),
                  ],
                  selected: {_agentMode},
                  showSelectedIcon: false,
                  onSelectionChanged: (s) {
                    auth.clearError();
                    setState(() => _agentMode = s.first);
                  },
                ),
                const SizedBox(height: 18),
                Text(
                  Strings.t(_agentMode ? 'agentSignIn' : 'signIn'),
                  style: const TextStyle(
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  _agentMode
                      ? Strings.t('agentSignInHint')
                      : 'ປ້ອນເບີໂທຂອງທ່ານ',
                  style: const TextStyle(fontSize: 13, color: GovColors.muted),
                ),
                const SizedBox(height: 18),
                if (_agentMode) ...[
                  TextField(
                    controller: _usernameController,
                    autocorrect: false,
                    autofocus: true,
                    textInputAction: TextInputAction.next,
                    decoration: InputDecoration(
                      labelText: Strings.t('username'),
                      prefixIcon: const Icon(Icons.person_outline),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _passwordController,
                    obscureText: true,
                    autocorrect: false,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      labelText: Strings.t('password'),
                      prefixIcon: const Icon(Icons.lock_outline),
                    ),
                  ),
                ] else
                  TextField(
                    controller: _phoneController,
                    focusNode: _phoneFocus,
                    keyboardType: TextInputType.phone,
                    autocorrect: false,
                    autofocus: true,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      labelText: Strings.t('phone'),
                      hintText: '020 5555 1234',
                      prefixIcon: const Icon(Icons.phone_iphone),
                    ),
                  ),
                if (auth.error != null) ...[
                  const SizedBox(height: 10),
                  _errorBox(auth.error!),
                ],
                const SizedBox(height: 18),
                FilledButton(
                  onPressed: auth.busy ? null : _submit,
                  child: auth.busy
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : Text(Strings.t(_agentMode ? 'agentSignIn' : 'signIn')),
                ),
                if (!_agentMode) ...[
                  const SizedBox(height: 18),
                  const _HowItWorks(),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _errorBox(String message) {
    return Container(
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: GovColors.danger.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: GovColors.danger.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline, size: 18, color: GovColors.danger),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(fontSize: 12.5, color: GovColors.danger),
            ),
          ),
        ],
      ),
    );
  }
}

class _HowItWorks extends StatelessWidget {
  const _HowItWorks();

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: 'ວິທີໃຊ້ງານ',
      icon: Icons.help_outline,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: const [
          _Step(no: '1', text: 'ປ້ອນເບີໂທຂອງທ່ານ (ເຊັ່ນ 020 5555 1234)'),
          _Step(
            no: '2',
            text: 'ຄັ້ງທຳອິດທີ່ໃຊ້ເບີໂທນີ້ ລະບົບຈະສ້າງບັນຊີໃຫ້ທັນທີ',
          ),
          _Step(no: '3', text: 'ຄົ້ນຫາຕອນດິນ ແລ້ວ ຊຳລະຄ່າທີ່ດິນ'),
        ],
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({required this.no, required this.text});

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
