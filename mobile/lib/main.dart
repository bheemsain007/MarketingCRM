import 'package:flutter/material.dart';

import 'app.dart';
import 'core/di/dependencies.dart';

void main() {
  // Secure storage and shared preferences both need the bindings up before the
  // first frame.
  WidgetsFlutterBinding.ensureInitialized();

  runApp(CrmApp(dependencies: AppDependencies.production()));
}
