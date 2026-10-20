import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/strings.dart';
import '../../core/theme.dart';
import '../../state/parcels_controller.dart';
import '../../state/payments_controller.dart';
import 'account_tab.dart';
import 'parcels_tab.dart';
import 'payments_tab.dart';

/// The signed-in shell: three tabs, mirroring the legacy portal's bottom bar.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<ParcelsController>().load();
      context.read<PaymentsController>().load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final pages = const [ParcelsTab(), PaymentsTab(), AccountTab()];

    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        backgroundColor: GovColors.card,
        indicatorColor: GovColors.gold.withValues(alpha: 0.25),
        height: 66,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.landscape_outlined),
            selectedIcon: const Icon(Icons.landscape),
            label: Strings.t('myParcels'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.receipt_outlined),
            selectedIcon: const Icon(Icons.receipt),
            label: Strings.t('myPayments'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.person_outline),
            selectedIcon: const Icon(Icons.person),
            label: Strings.t('myAccount'),
          ),
        ],
      ),
    );
  }
}
