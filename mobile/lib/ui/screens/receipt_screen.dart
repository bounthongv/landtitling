import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../state/payments_controller.dart';
import '../widgets/common.dart';
import '../widgets/gov_header.dart';

/// Photograph the paid bank slip and send it to the district. The upload moves
/// the payment from ISSUED (nothing arrived yet) to SUBMITTED — proof is in and
/// an officer has to verify it.
class ReceiptScreen extends StatefulWidget {
  const ReceiptScreen({super.key, required this.paymentId});

  final int paymentId;

  @override
  State<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends State<ReceiptScreen> {
  XFile? _picked;
  String? _error;
  bool _uploading = false;

  Future<void> _pick(ImageSource source) async {
    try {
      final picker = ImagePicker();
      final file = await picker.pickImage(
        source: source,
        maxWidth: 2000,
        imageQuality: 85,
      );
      if (file != null) {
        setState(() {
          _picked = file;
          _error = null;
        });
      }
    } catch (e) {
      setState(() => _error = '$e');
    }
  }

  Future<void> _upload() async {
    final file = _picked;
    if (file == null) return;
    setState(() => _uploading = true);
    final messenger = ScaffoldMessenger.of(context);
    final payments = context.read<PaymentsController>();
    try {
      await payments.uploadReceipt(
        paymentId: widget.paymentId,
        filePath: file.path,
        fileName: file.name,
        mimeType: 'image/jpeg',
      );
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(
          content: Text(Strings.t('submittedToDistrict')),
          backgroundColor: GovColors.ok,
        ),
      );
      // The slip is the district's now: back to the start, not to the slip.
      Navigator.of(context).popUntil((route) => route.isFirst);
    } catch (e) {
      if (!mounted) return;
      setState(() => _uploading = false);
      messenger.showSnackBar(
        SnackBar(content: Text('$e'), backgroundColor: GovColors.danger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          GovHeader(
            title: Strings.t('uploadSlip'),
            subtitle: Strings.t('uploadSlipHint'),
            onBack: () => Navigator.of(context).pop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 26),
              children: [
                if (_picked == null)
                  SectionCard(
                    child: Column(
                      children: [
                        const Icon(
                          Icons.receipt_long_outlined,
                          size: 44,
                          color: GovColors.muted,
                        ),
                        const SizedBox(height: 10),
                        Text(
                          Strings.t('uploadSlipHint'),
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                            fontSize: 13,
                            color: GovColors.muted,
                            height: 1.5,
                          ),
                        ),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: () => _pick(ImageSource.camera),
                          icon: const Icon(Icons.camera_alt_outlined),
                          label: Text(Strings.t('takePhoto')),
                        ),
                        const SizedBox(height: 10),
                        OutlinedButton.icon(
                          onPressed: () => _pick(ImageSource.gallery),
                          icon: const Icon(Icons.photo_library_outlined),
                          label: Text(Strings.t('chooseGallery')),
                        ),
                      ],
                    ),
                  )
                else
                  SectionCard(
                    child: Column(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: Image.file(
                            File(_picked!.path),
                            height: 320,
                            fit: BoxFit.contain,
                          ),
                        ),
                        const SizedBox(height: 14),
                        Row(
                          children: [
                            Expanded(
                              child: OutlinedButton(
                                onPressed: _uploading
                                    ? null
                                    : () => setState(() => _picked = null),
                                child: Text(Strings.t('cancel')),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: FilledButton(
                                onPressed: _uploading ? null : _upload,
                                child: _uploading
                                    ? const SizedBox(
                                        width: 20,
                                        height: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : Text(Strings.t('sendToDistrict')),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    style: const TextStyle(
                      color: GovColors.danger,
                      fontSize: 12.5,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
