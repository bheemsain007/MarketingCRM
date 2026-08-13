import 'package:url_launcher/url_launcher.dart';

/// ADR-B, the whole of it: **the device dials, the CRM orchestrates.**
///
/// This app never places a call. It hands an E.164 number to the platform
/// dialer and gets out of the way; the call happens on the carrier network,
/// through the phone's own call app, with the phone's own recording behaviour.
/// The server has already created the call record and the dial intent by the
/// time this runs, and the outcome comes back to the server afterwards.
///
/// The rejected alternative was a WebRTC/CPaaS softphone (ARCHITECTURE §13).
abstract class PhoneDialer {
  /// Returns false when no app on the device can handle `tel:` — a tablet with
  /// no telephony stack. It does **not** mean the call failed; once the dialer
  /// is open, this app can no longer observe anything.
  Future<bool> dial(String e164Number);
}

class UrlLauncherDialer implements PhoneDialer {
  const UrlLauncherDialer();

  @override
  Future<bool> dial(String e164Number) {
    // `tel:` and not `ACTION_CALL`: opening the dialer needs no CALL_PHONE
    // permission, and the human presses the green button. An app that dials
    // without that press is one bad tap away from ringing a stranger.
    final uri = Uri(scheme: 'tel', path: e164Number);

    return launchUrl(uri, mode: LaunchMode.externalApplication);
  }
}

/// Records what would have been dialled, for tests and for the emulator.
class RecordingDialer implements PhoneDialer {
  RecordingDialer({this.succeeds = true});

  final bool succeeds;
  final List<String> dialled = <String>[];

  @override
  Future<bool> dial(String e164Number) async {
    dialled.add(e164Number);

    return succeeds;
  }
}
