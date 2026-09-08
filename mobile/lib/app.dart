import 'package:flutter/material.dart';

import 'core/di/dependencies.dart';
import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'state/auth_controller.dart';
import 'theme/app_theme.dart';
import 'widgets/animations.dart';

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
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: ThemeMode.dark,
        home: ListenableBuilder(
          listenable: widget.dependencies.auth,
          builder: (context, _) {
            final child = switch (widget.dependencies.auth.status) {
              AuthStatus.checking => const _SplashScreen(),
              AuthStatus.signedOut => const LoginScreen(),
              AuthStatus.signedIn => const HomeShell(),
            };

            // Keyed by status so AnimatedSwitcher treats sign-in/sign-out as a
            // real content change rather than a rebuild of the same widget -
            // otherwise it has nothing to cross-fade between.
            return AnimatedSwitcher(
              duration: const Duration(milliseconds: 280),
              switchInCurve: Curves.easeOut,
              switchOutCurve: Curves.easeIn,
              child: KeyedSubtree(key: ValueKey(widget.dependencies.auth.status), child: child),
            );
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
    final theme = Theme.of(context);

    return Scaffold(
      body: Center(
        child: FadeSlideIn(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Icon(Icons.headset_mic_outlined, size: 48, color: theme.colorScheme.primary),
              const SizedBox(height: 20),
              const CircularProgressIndicator(),
            ],
          ),
        ),
      ),
    );
  }
}
