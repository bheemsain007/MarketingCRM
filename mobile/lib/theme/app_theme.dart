import 'package:flutter/material.dart';

/// The app's whole look, in one place.
///
/// Before this file, every screen leaned on `ThemeData`'s bare Material
/// defaults (a seed colour and nothing else) — every card, field and button
/// used whatever shape and elevation Flutter ships out of the box. Centralising
/// the component themes here means a button looks the same whether it is on
/// the login screen or in a bottom sheet, without every screen having to
/// remember the same `BorderRadius.circular(14)`.
abstract final class AppTheme {
  static const Color _seed = Color(0xFF1D4ED8);
  static const double _radius = 14;

  static ThemeData get light => _build(Brightness.light);
  static ThemeData get dark => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final scheme = ColorScheme.fromSeed(seedColor: _seed, brightness: brightness);
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius));

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: scheme.surface,
      splashFactory: InkSparkle.splashFactory,

      textTheme: _textTheme(scheme),

      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        surfaceTintColor: scheme.surfaceTint,
        elevation: 0,
        scrolledUnderElevation: 2,
        centerTitle: false,
        titleTextStyle: _textTheme(scheme).titleLarge,
      ),

      cardTheme: CardThemeData(
        elevation: 0,
        color: scheme.surfaceContainerLow,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius + 2)),
        margin: EdgeInsets.zero,
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_radius),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_radius),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_radius),
          borderSide: BorderSide(color: scheme.primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_radius),
          borderSide: BorderSide(color: scheme.error, width: 1.5),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_radius),
          borderSide: BorderSide(color: scheme.error, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius)),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
          animationDuration: const Duration(milliseconds: 200),
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius)),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          side: BorderSide(color: scheme.outlineVariant),
          animationDuration: const Duration(milliseconds: 200),
        ),
      ),

      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius)),
          animationDuration: const Duration(milliseconds: 200),
        ),
      ),

      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          shape: const CircleBorder(),
          animationDuration: const Duration(milliseconds: 200),
        ),
      ),

      chipTheme: ChipThemeData(
        shape: const StadiumBorder(),
        side: BorderSide.none,
        backgroundColor: scheme.surfaceContainerHighest,
        selectedColor: scheme.primaryContainer,
        labelStyle: _textTheme(scheme).labelLarge,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      ),

      segmentedButtonTheme: SegmentedButtonThemeData(
        style: SegmentedButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius)),
        ),
      ),

      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: scheme.surfaceContainer,
        elevation: 0,
        height: 68,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => _textTheme(scheme).labelMedium?.copyWith(
                fontWeight: states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
                color: states.contains(WidgetState.selected) ? scheme.onSecondaryContainer : scheme.onSurfaceVariant,
              ),
        ),
        indicatorColor: scheme.secondaryContainer,
      ),

      dividerTheme: DividerThemeData(color: scheme.outlineVariant.withValues(alpha: 0.5), space: 1),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radius)),
        backgroundColor: scheme.inverseSurface,
        contentTextStyle: _textTheme(scheme).bodyMedium?.copyWith(color: scheme.onInverseSurface),
      ),

      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        modalElevation: 4,
      ),

      progressIndicatorTheme: ProgressIndicatorThemeData(color: scheme.primary),

      listTileTheme: ListTileThemeData(
        shape: shape,
        iconColor: scheme.onSurfaceVariant,
      ),

      pageTransitionsTheme: const PageTransitionsTheme(
        builders: <TargetPlatform, PageTransitionsBuilder>{
          TargetPlatform.android: FadeThroughPageTransitionsBuilder(),
          TargetPlatform.iOS: FadeThroughPageTransitionsBuilder(),
          TargetPlatform.linux: FadeThroughPageTransitionsBuilder(),
          TargetPlatform.macOS: FadeThroughPageTransitionsBuilder(),
          TargetPlatform.windows: FadeThroughPageTransitionsBuilder(),
        },
      ),
    );
  }

  static TextTheme _textTheme(ColorScheme scheme) {
    return TextTheme(
      headlineSmall: TextStyle(fontSize: 26, fontWeight: FontWeight.w700, color: scheme.onSurface, letterSpacing: -0.5),
      titleLarge: TextStyle(fontSize: 21, fontWeight: FontWeight.w700, color: scheme.onSurface, letterSpacing: -0.3),
      titleMedium: TextStyle(fontSize: 17, fontWeight: FontWeight.w600, color: scheme.onSurface),
      titleSmall: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: scheme.onSurface),
      bodyLarge: TextStyle(fontSize: 16, fontWeight: FontWeight.w400, color: scheme.onSurface),
      bodyMedium: TextStyle(fontSize: 14, fontWeight: FontWeight.w400, color: scheme.onSurface),
      bodySmall: TextStyle(fontSize: 12, fontWeight: FontWeight.w400, color: scheme.onSurfaceVariant),
      labelLarge: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: scheme.onSurface),
      labelMedium: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: scheme.onSurface),
      labelSmall: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: scheme.onSurfaceVariant),
    );
  }
}

/// A gentle cross-fade instead of Android's default slide-up-and-fade.
///
/// Built by hand rather than pulling in the `animations` package for one
/// transition curve — this app adds a dependency only when the standard
/// library genuinely cannot do the job (see `pubspec.yaml`'s own comments on
/// `http` and `connectivity_plus`), and `FadeTransition` already can.
class FadeThroughPageTransitionsBuilder extends PageTransitionsBuilder {
  const FadeThroughPageTransitionsBuilder();

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    final curved = CurvedAnimation(parent: animation, curve: Curves.easeOut);
    final incoming = Tween<double>(begin: 0.96, end: 1).animate(curved);
    final outgoingFade = Tween<double>(begin: 1, end: 0).animate(
      CurvedAnimation(parent: secondaryAnimation, curve: Curves.easeIn),
    );

    return FadeTransition(
      opacity: curved,
      child: ScaleTransition(
        scale: incoming,
        child: FadeTransition(opacity: outgoingFade, child: child),
      ),
    );
  }
}
