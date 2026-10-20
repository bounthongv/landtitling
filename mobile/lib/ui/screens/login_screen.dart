import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../state/auth_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// Email + one-time code sign-in.
///
/// The code is emailed (the SMS channel was dropped — no gateway), so this is
/// the only credential a citizen needs. A citizen with no linked land still
/// signs in fine; the parcels screen explains what to do next.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController();
  final _codeController = TextEditingController();
  final _emailFocus = FocusNode();

  @override
  void dispose() {
    _emailController.dispose();
    _codeController.dispose();
    _emailFocus.dispose();
    super.dispose();
  }

  Future<void> _sendCode() async {
    final auth = context.read<AuthController>();
    final ok = await auth.requestCode(_emailController.text);
    if (ok && mounted) {
      FocusScope.of(context).unfocus();
      _codeController.clear();
    }
  }

  Future<void> _verify() async {
    final auth = context.read<AuthController>();
    final ok = await auth.verifyCode(_codeController.text);
    if (!ok && mounted) {
      _codeController.clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();
    final waitingForCode = auth.status == AuthStatus.waitingForCode;

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
                if (!waitingForCode) _emailStep(auth) else _codeStep(auth),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _emailStep(AuthController auth) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'ເຂົ້າສູ່ລະບົບດ້ວຍອີເມວ',
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 4),
        const Text(
          'ກະລຸນາປ້ອນອີເມວຂອງທ່ານ ລະບົບຈະສົ່ງລະຫັດຢືນຢັນໄປຫາ',
          style: TextStyle(fontSize: 13, color: GovColors.muted),
        ),
        const SizedBox(height: 18),
        TextField(
          controller: _emailController,
          focusNode: _emailFocus,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          textInputAction: TextInputAction.done,
          onSubmitted: (_) => _sendCode(),
          decoration: InputDecoration(
            labelText: Strings.t('email'),
            hintText: Strings.t('emailHint'),
            prefixIcon: const Icon(Icons.mail_outline),
          ),
        ),
        if (auth.error != null) ...[
          const SizedBox(height: 10),
          _errorBox(auth.error!),
        ],
        const SizedBox(height: 18),
        FilledButton(
          onPressed: auth.busy ? null : _sendCode,
          child: auth.busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : Text(Strings.t('sendCode')),
        ),
        const SizedBox(height: 26),
        const _HowItWorks(),
      ],
    );
  }

  Widget _codeStep(AuthController auth) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          Strings.t('enterCode'),
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 4),
        Text(
          '${Strings.t('codeSentTo')} ${auth.pendingEmail ?? ''}',
          style: const TextStyle(fontSize: 13, color: GovColors.muted),
        ),
        if (auth.debugCode != null) ...[
          const SizedBox(height: 12),
          _debugBox(auth.debugCode!),
        ],
        const SizedBox(height: 18),
        TextField(
          controller: _codeController,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.center,
          autofocus: true,
          maxLength: 6,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: const TextStyle(
            fontSize: 26,
            letterSpacing: 10,
            fontWeight: FontWeight.w700,
          ),
          decoration: const InputDecoration(
            hintText: '------',
            counterText: '',
          ),
          onSubmitted: (_) => _verify(),
        ),
        if (auth.error != null) ...[
          const SizedBox(height: 10),
          _errorBox(auth.error!),
        ],
        const SizedBox(height: 14),
        FilledButton(
          onPressed: auth.busy ? null : _verify,
          child: auth.busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : Text(Strings.t('verify')),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: auth.busy ? null : () => auth.changeEmail(),
                child: Text(Strings.t('changeEmail')),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextButton(
                onPressed: auth.busy ? null : _sendCode,
                child: Text(Strings.t('resend')),
              ),
            ),
          ],
        ),
      ],
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

  Widget _debugBox(String code) {
    return Container(
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: GovColors.gold.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: GovColors.gold.withValues(alpha: 0.5)),
      ),
      child: Row(
        children: [
          const Icon(Icons.developer_mode, size: 18, color: GovColors.gov),
          const SizedBox(width: 8),
          Text(
            'DEV CODE: $code',
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
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
          _Step(no: '1', text: 'ປ້ອນອີເມວຂອງທ່ານໃສ່ຂ້າງເທິງ'),
          _Step(
            no: '2',
            text: 'ເປີດການຮັບຂໍ້ຄຳໃນອີເມວ ແລ້ວໃສ່ລະຫັດ 6 ຕົວລັດຖະມານ',
          ),
          _Step(
            no: '3',
            text: 'ເຂົ້າສູ່ລະບົບ ແລ້ວເບິ່ງຕອນດິນ ແລະ ຄ່າທີ່ຕ້ອງຊຳລະ',
          ),
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
