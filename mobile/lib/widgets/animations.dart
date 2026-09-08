import 'package:flutter/material.dart';

/// Fades and slides its child in shortly after it first mounts.
///
/// Kept short and capped deliberately: `test/support/pump.dart`'s `settle()`
/// pumps a fixed 1 second (never `pumpAndSettle`, because a
/// `CircularProgressIndicator` schedules frames forever and would hang it) —
/// an entrance animation that took longer than that would leave widget tests
/// asserting against a still-transparent or still-offset widget. 320ms of
/// travel plus at most 240ms of stagger leaves comfortable margin under that
/// budget even for a long list.
class FadeSlideIn extends StatefulWidget {
  const FadeSlideIn({
    required this.child,
    this.delay = Duration.zero,
    super.key,
  });

  final Widget child;
  final Duration delay;

  /// One `index`'s worth of stagger, capped so a long list does not push its
  /// last rows past the settle budget above.
  factory FadeSlideIn.staggered({required int index, required Widget child, Key? key}) {
    final steps = index.clamp(0, 6);

    return FadeSlideIn(delay: Duration(milliseconds: steps * 40), key: key, child: child);
  }

  static const Duration duration = Duration(milliseconds: 320);

  @override
  State<FadeSlideIn> createState() => _FadeSlideInState();
}

class _FadeSlideInState extends State<FadeSlideIn> {
  bool _visible = false;

  @override
  void initState() {
    super.initState();

    if (widget.delay == Duration.zero) {
      _visible = true;
    } else {
      Future<void>.delayed(widget.delay, () {
        if (mounted) {
          setState(() => _visible = true);
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedSlide(
      offset: _visible ? Offset.zero : const Offset(0, 0.06),
      duration: FadeSlideIn.duration,
      curve: Curves.easeOutCubic,
      child: AnimatedOpacity(
        opacity: _visible ? 1 : 0,
        duration: FadeSlideIn.duration,
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}

/// A shorter, standard cross-fade for swapping one bit of content for another
/// in place — a button's label for its spinner, an icon for its opposite
/// state, a refusal banner for the control it replaces. One duration and one
/// transition so these swaps read as the same language everywhere they show
/// up, rather than each screen picking its own.
class AppSwitcher extends StatelessWidget {
  const AppSwitcher({required this.child, super.key});

  final Widget child;

  static const Duration duration = Duration(milliseconds: 200);

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: duration,
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      transitionBuilder: (child, animation) => FadeTransition(
        opacity: animation,
        child: ScaleTransition(
          scale: Tween<double>(begin: 0.94, end: 1).animate(animation),
          child: child,
        ),
      ),
      child: child,
    );
  }
}

/// Scales a tappable child down very slightly while pressed, for the tactile
/// feedback Material's own ripple does not give a whole card or row.
///
/// Deliberately just a scale on top of whatever gesture handling the child
/// already does (a `Card`'s `InkWell`, a `ListTile`'s own `onTap`) rather than
/// a replacement for it, so hit-testing in `WidgetTester.tap` is unaffected —
/// the child is exactly where it always was, just briefly smaller.
class PressableScale extends StatefulWidget {
  const PressableScale({required this.child, super.key});

  final Widget child;

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale> {
  bool _pressed = false;

  void _set(bool value) {
    if (_pressed != value) {
      setState(() => _pressed = value);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Listener(
      onPointerDown: (_) => _set(true),
      onPointerUp: (_) => _set(false),
      onPointerCancel: (_) => _set(false),
      child: AnimatedScale(
        scale: _pressed ? 0.97 : 1,
        duration: const Duration(milliseconds: 120),
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}
