import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';
import '../state/auth_controller.dart';
import '../widgets/animations.dart';

/// `POST /auth/login`.
///
/// The form does no validation the server would also do. It checks that the two
/// boxes are non-empty — which saves a round trip and is not a business rule —
/// and shows whatever the server says about everything else, including the
/// deliberately identical message for "wrong password" and "no such account".
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  static const Key emailFieldKey = Key('login.email');
  static const Key passwordFieldKey = Key('login.password');
  static const Key submitButtonKey = Key('login.submit');
  static const Key errorKey = Key('login.error');

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit(AuthController auth) async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    FocusScope.of(context).unfocus();

    await auth.signIn(email: _email.text.trim(), password: _password.text);
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final auth = deps.auth;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: ListenableBuilder(
          listenable: auth,
          builder: (context, _) {
            final fieldErrors = auth.fieldErrors;

            return Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: FadeSlideIn(
                    child: Form(
                    key: _formKey,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Container(
                          width: 88,
                          height: 88,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: theme.colorScheme.primaryContainer,
                            shape: BoxShape.circle,
                          ),
                          child: Icon(Icons.headset_mic_outlined, size: 40, color: theme.colorScheme.onPrimaryContainer),
                        ),
                        const SizedBox(height: 20),
                        Text(
                          'Marketing CRM',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.headlineSmall,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Telecaller sign-in',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                        const SizedBox(height: 32),
                        TextFormField(
                          key: LoginScreen.emailFieldKey,
                          controller: _email,
                          autocorrect: false,
                          enabled: !auth.busy,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          decoration: InputDecoration(
                            labelText: 'Email',
                            prefixIcon: const Icon(Icons.alternate_email),
                            border: const OutlineInputBorder(),
                            errorText: fieldErrors['email'],
                          ),
                          validator: (value) =>
                              (value == null || value.trim().isEmpty) ? 'Enter your email.' : null,
                        ),
                        const SizedBox(height: 16),
                        TextFormField(
                          key: LoginScreen.passwordFieldKey,
                          controller: _password,
                          obscureText: _obscure,
                          enabled: !auth.busy,
                          textInputAction: TextInputAction.done,
                          onFieldSubmitted: (_) => _submit(auth),
                          decoration: InputDecoration(
                            labelText: 'Password',
                            prefixIcon: const Icon(Icons.lock_outline),
                            border: const OutlineInputBorder(),
                            errorText: fieldErrors['password'],
                            suffixIcon: IconButton(
                              icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                              tooltip: _obscure ? 'Show password' : 'Hide password',
                              onPressed: () => setState(() => _obscure = !_obscure),
                            ),
                          ),
                          validator: (value) =>
                              (value == null || value.isEmpty) ? 'Enter your password.' : null,
                        ),
                        AnimatedSize(
                          duration: AppSwitcher.duration,
                          curve: Curves.easeOut,
                          alignment: Alignment.topCenter,
                          child: auth.errorMessage == null
                              ? const SizedBox(width: double.infinity)
                              : Padding(
                                  padding: const EdgeInsets.only(top: 16),
                                  child: _ErrorBanner(
                                    key: LoginScreen.errorKey,
                                    message: auth.errorMessage!,
                                  ),
                                ),
                        ),
                        const SizedBox(height: 24),
                        FilledButton(
                          key: LoginScreen.submitButtonKey,
                          onPressed: auth.busy ? null : () => _submit(auth),
                          child: AppSwitcher(
                            child: auth.busy
                                ? const SizedBox(
                                    key: ValueKey('busy'),
                                    height: 20,
                                    width: 20,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : const Text('Sign in', key: ValueKey('idle')),
                          ),
                        ),
                        const SizedBox(height: 24),
                        Text(
                          deps.config.baseUrl,
                          textAlign: TextAlign.center,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message, super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(Icons.error_outline, color: theme.colorScheme.onErrorContainer, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onErrorContainer),
            ),
          ),
        ],
      ),
    );
  }
}
