import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/app.dart';

import 'harness.dart';

/// Pumps a fixed number of frames instead of `pumpAndSettle`.
///
/// This app is almost never free of animation while it is working — every
/// loading state is a `CircularProgressIndicator`, which schedules frames
/// forever and makes `pumpAndSettle` hang rather than settle. Pumping a known
/// span of time is the honest way to wait for an async load in a widget test.
Future<void> settle(WidgetTester tester, {int frames = 10}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

Future<Harness> pumpApp(WidgetTester tester, Harness harness) async {
  await tester.pumpWidget(CrmApp(dependencies: harness.dependencies));
  await settle(tester);

  return harness;
}
