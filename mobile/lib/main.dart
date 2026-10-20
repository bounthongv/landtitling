import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/api_client.dart';
import 'core/auth_store.dart';
import 'core/theme.dart';
import 'state/auth_controller.dart';
import 'state/parcels_controller.dart';
import 'state/payments_controller.dart';
import 'ui/screens/home_shell.dart';
import 'ui/screens/login_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final api = ApiClient();
  final store = AuthStore();

  runApp(LandFeeApp(api: api, store: store));
}

class LandFeeApp extends StatelessWidget {
  const LandFeeApp({super.key, required this.api, required this.store});

  final ApiClient api;
  final AuthStore store;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<ApiClient>.value(value: api),
        Provider<AuthStore>.value(value: store),
        ChangeNotifierProvider<AuthController>(
          create: (_) => AuthController(api: api, store: store)..restore(),
        ),
        ChangeNotifierProvider<ParcelsController>(
          create: (_) => ParcelsController(api: api),
        ),
        ChangeNotifierProvider<PaymentsController>(
          create: (_) => PaymentsController(api: api),
        ),
      ],
      child: MaterialApp(
        title: 'Land Tax Payment',
        debugShowCheckedModeBanner: false,
        theme: buildGovTheme(),
        home: const _AuthGate(),
      ),
    );
  }
}

/// Decides between the login screen and the app, based on the saved session.
class _AuthGate extends StatelessWidget {
  const _AuthGate();

  @override
  Widget build(BuildContext context) {
    final status = context.watch<AuthController>().status;

    switch (status) {
      case AuthStatus.unknown:
        return const Scaffold(
          body: Center(child: CircularProgressIndicator(strokeWidth: 2.5)),
        );
      case AuthStatus.authenticated:
        return const HomeShell();
      case AuthStatus.unauthenticated:
      case AuthStatus.waitingForCode:
        return const LoginScreen();
    }
  }
}
