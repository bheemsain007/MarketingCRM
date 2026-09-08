import 'package:flutter/material.dart';

/// Colour, by meaning, for the values the server sends as plain strings.
///
/// Every mapping here is decorative only — which pixel a status paints. The
/// status itself, and what it means, is decided entirely server-side
/// (`LeadStatus`, `LeadTemperature`); this file never derives a business
/// decision from a colour, only the reverse.
abstract final class StatusColors {
  /// One colour pair per raw `lead.status` value (`App\Enums\LeadStatus`),
  /// grouped by where the lead sits in the pipeline rather than assigned
  /// arbitrarily — the eleven statuses read as a handful of colour families,
  /// not eleven unrelated hues.
  static (Color, Color) leadStatus(BuildContext context, String status) {
    final scheme = Theme.of(context).colorScheme;

    return switch (status) {
      'new' => (scheme.primaryContainer, scheme.onPrimaryContainer),
      'contacted' => (_tealContainer(context), _onTeal(context)),
      'interested' || 'follow_up' || 'callback' => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      'proposal' || 'negotiation' || 'decision_pending' => (
          scheme.secondaryContainer,
          scheme.onSecondaryContainer,
        ),
      'converted' => (_greenContainer(context), _onGreen(context)),
      'lost' || 'not_interested' => (scheme.errorContainer, scheme.onErrorContainer),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
    };
  }

  /// `App\Enums\LeadTemperature` — the metaphor the server's own field name
  /// invites: hot runs warm-to-red, cold runs toward blue.
  static (Color, Color) leadTemperature(BuildContext context, String temperature) {
    final scheme = Theme.of(context).colorScheme;

    return switch (temperature) {
      'hot' => (scheme.errorContainer, scheme.onErrorContainer),
      'warm' => (_amberContainer(context), _onAmber(context)),
      'cold' => (scheme.primaryContainer, scheme.onPrimaryContainer),
      'dormant' => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
    };
  }

  /// `App\Enums\FollowUpStatus`. `missed` shares its colour with the
  /// `isOverdue` flag the follow-up screen already renders in red — both are
  /// "this one needed attention it did not get."
  static (Color, Color) followUpStatus(BuildContext context, String status) {
    final scheme = Theme.of(context).colorScheme;

    return switch (status) {
      'open' => (scheme.primaryContainer, scheme.onPrimaryContainer),
      'completed' => (_greenContainer(context), _onGreen(context)),
      'missed' => (scheme.errorContainer, scheme.onErrorContainer),
      'cancelled' => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
    };
  }

  /// One accent colour per send channel (`sms`/`whatsapp`/`email`) — for
  /// tinting an icon or border, not a chip background. WhatsApp keeps its own
  /// green, since that association already exists in a telecaller's head from
  /// every other app on the phone; SMS and email get two more of the
  /// generated hues so the row of three reads as three distinct actions
  /// rather than one button repeated three times.
  static Color channel(BuildContext context, String value) {
    final scheme = Theme.of(context).colorScheme;

    return switch (value) {
      'whatsapp' => _accent(context, const Color(0xFF25D366)),
      'email' => _accent(context, const Color(0xFFB45309)),
      _ => scheme.primary,
    };
  }

  /// A single saturated colour, tuned for use as an icon or border tint
  /// rather than a chip background — visible against a light OR dark
  /// surface, unlike [_onContainer] which assumes it sits on its matching
  /// [_container].
  static Color _accent(BuildContext context, Color seed) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Color.lerp(seed, isDark ? Colors.white : Colors.black, isDark ? 0.25 : 0.05)!;
  }

  /// A wider, more vivid rotation than the theme's own container roles, for
  /// avatars — the one place variety matters more than semantic meaning.
  /// Picked from Material's tonal palette at a fixed tone rather than named
  /// literals, so it shifts with the seed colour instead of fighting it.
  static (Color, Color) avatar(BuildContext context, String name) {
    final scheme = Theme.of(context).colorScheme;
    final palette = <(Color, Color)>[
      (scheme.primaryContainer, scheme.onPrimaryContainer),
      (_tealContainer(context), _onTeal(context)),
      (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      (_amberContainer(context), _onAmber(context)),
      (scheme.secondaryContainer, scheme.onSecondaryContainer),
      (_greenContainer(context), _onGreen(context)),
      (_pinkContainer(context), _onPink(context)),
      (_indigoContainer(context), _onIndigo(context)),
    ];

    final index = name.isEmpty ? 0 : name.codeUnits.fold<int>(0, (sum, unit) => sum + unit) % palette.length;

    return palette[index];
  }

  // ---------------------------------------------------------------------
  // A handful of extra hues Material's own five roles (primary/secondary/
  // tertiary/error/neutral) do not cover, generated from the same seed so
  // they sit at the same saturation and tone as everything else the theme
  // draws — never a hard-coded hex dropped in next to a generated palette.
  // ---------------------------------------------------------------------

  static Color _tealContainer(BuildContext context) => _container(context, const Color(0xFF00695C));
  static Color _onTeal(BuildContext context) => _onContainer(context, const Color(0xFF00695C));

  static Color _greenContainer(BuildContext context) => _container(context, const Color(0xFF2E7D32));
  static Color _onGreen(BuildContext context) => _onContainer(context, const Color(0xFF2E7D32));

  static Color _amberContainer(BuildContext context) => _container(context, const Color(0xFFB45309));
  static Color _onAmber(BuildContext context) => _onContainer(context, const Color(0xFFB45309));

  static Color _pinkContainer(BuildContext context) => _container(context, const Color(0xFFAD1457));
  static Color _onPink(BuildContext context) => _onContainer(context, const Color(0xFFAD1457));

  static Color _indigoContainer(BuildContext context) => _container(context, const Color(0xFF3730A3));
  static Color _onIndigo(BuildContext context) => _onContainer(context, const Color(0xFF3730A3));

  /// A cheap stand-in for HCT tonal generation: light theme gets a pale tint
  /// of [seed], dark theme gets a deep, muted shade of it — matching how
  /// Material's own `xContainer` roles behave in each brightness.
  static Color _container(BuildContext context, Color seed) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Color.lerp(seed, isDark ? Colors.black : Colors.white, isDark ? 0.65 : 0.88)!;
  }

  /// The colour that reads on top of [_container]'s output: darkened toward
  /// [seed] itself in light mode (already dark enough to contrast a pale
  /// container), lightened toward white in dark mode (to contrast a deep
  /// one) — the same inversion Material's `onXContainer` roles make.
  static Color _onContainer(BuildContext context, Color seed) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Color.lerp(seed, isDark ? Colors.white : Colors.black, isDark ? 0.75 : 0.15)!;
  }
}
