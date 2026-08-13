import 'package:flutter/material.dart';

import 'core/di/dependencies.dart';
import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'state/auth_controller.dart';

/// The root.
///
/// There is exactly one routing decision in this app and it is made here, off
/// [AuthController.status]. No screen navigates to login by itself, so a 401
/// raised anywhere — including from a background outbox flush — lands in the
/// same place, and no screen can be left mounted holding data it is no longer
/// entitled to.
class CrmApp extends StatefulWidget {
  const CrmApp({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<CrmApp> createState() => _CrmAppState();
}

class _CrmAppState extends State<CrmApp> {
  @override
  void initState() {
    super.initState();
    widget.dependencies.auth.restore();
  }

  @override
  Widget build(BuildContext context) {
    return AppScope(
      dependencies: widget.dependencies,
      child: MaterialApp(
        title: 'Marketing CRM',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF1D4ED8)),
          useMaterial3: true,
        ),
        home: ListenableBuilder(
          listenable: widget.dependencies.auth,
          builder: (context, _) {
            switch (widget.dependencies.auth.status) {
              case AuthStatus.checking:
                return const _SplashScreen();
              case AuthStatus.signedOut:
                return const LoginScreen();
              case AuthStatus.signedIn:
                return const HomeShell();
            }
          },
        ),
      ),
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(child: CircularProgressIndicator()),
    );
  }
}
