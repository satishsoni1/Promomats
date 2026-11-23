import 'package:flutter/material.dart';

import '../api.dart';
import '../theme.dart';
import '../widgets/brand.dart';
import 'home_shell.dart';
import 'login_screen.dart';

/// Opening screen: continues seamlessly from the native (white) launch screen,
/// animates the VODO mark in, checks the saved session, then goes to Active
/// Workflow - or to sign-in if there is no session or it has expired.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, this.minimumDuration = const Duration(milliseconds: 1400)});
  final Duration minimumDuration;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _anim = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100))..forward();
  late final Animation<double> _logo = CurvedAnimation(parent: _anim, curve: const Interval(0, 0.6, curve: Curves.easeOutBack));
  late final Animation<double> _text = CurvedAnimation(parent: _anim, curve: const Interval(0.45, 1, curve: Curves.easeOut));

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    final minimum = Future<void>.delayed(widget.minimumDuration);
    var signedIn = ApiClient.instance.isSignedIn;
    if (signedIn) {
      try {
        await ApiClient.instance.me();
      } on ApiException catch (e) {
        // An expired session goes to sign-in; being offline still opens the app.
        if (e.isUnauthenticated) signedIn = false;
      }
    }
    await minimum;
    if (!mounted) return;
    Navigator.of(context).pushReplacement(PageRouteBuilder(
      transitionDuration: const Duration(milliseconds: 450),
      pageBuilder: (_, _, _) => signedIn ? const HomeShell() : const LoginScreen(),
      transitionsBuilder: (_, a, _, child) => FadeTransition(opacity: a, child: child),
    ));
  }

  @override
  void dispose() {
    _anim.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: Stack(children: [
        Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ScaleTransition(
              scale: Tween(begin: 0.7, end: 1.0).animate(_logo),
              child: FadeTransition(opacity: _logo, child: const VodoMark(width: 170)),
            ),
            const SizedBox(height: 22),
            FadeTransition(
              opacity: _text,
              child: SlideTransition(
                position: Tween(begin: const Offset(0, 0.4), end: Offset.zero).animate(_text),
                child: Column(children: [
                  Text('VODO', style: Theme.of(context).textTheme.displaySmall?.copyWith(fontWeight: FontWeight.w900, color: brandTeal, letterSpacing: 2)),
                  const SizedBox(height: 6),
                  Text('PromoMats review & approval', style: TextStyle(color: Colors.grey.shade600, letterSpacing: 0.4)),
                ]),
              ),
            ),
          ]),
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 48,
          child: FadeTransition(
            opacity: _text,
            child: const Center(
              child: SizedBox(width: 120, child: LinearProgressIndicator(minHeight: 3, color: accentOrange, backgroundColor: Color(0x1A0F5D61))),
            ),
          ),
        ),
      ]),
    );
  }
}
