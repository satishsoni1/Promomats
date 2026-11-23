import 'package:flutter/material.dart';

import '../theme.dart';

/// The VODO mark - the same two rings as the web app's logo.
class VodoMark extends StatelessWidget {
  const VodoMark({super.key, this.width = 96});
  final double width;

  @override
  Widget build(BuildContext context) =>
      Image.asset('assets/branding/vodo_mark.png', width: width, filterQuality: FilterQuality.medium, semanticLabel: 'VODO');
}

/// The mark on a white rounded tile, as in the web sidebar (keeps the teal ring
/// visible on teal backgrounds).
class VodoTile extends StatelessWidget {
  const VodoTile({super.key, this.size = 88});
  final double size;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        padding: EdgeInsets.all(size * 0.14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(size * 0.26),
          boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.18), blurRadius: 24, offset: const Offset(0, 10))],
        ),
        child: const FittedBox(child: VodoMark(width: 220)),
      );
}

/// The product name, "VODO Vault": VODO bold, Vault light.
class VodoWordmark extends StatelessWidget {
  const VodoWordmark({super.key, this.color = brandTeal, this.size = 36});
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) => Text.rich(
        TextSpan(children: [
          TextSpan(text: 'VODO', style: TextStyle(fontWeight: FontWeight.w900, letterSpacing: size * 0.05)),
          TextSpan(text: ' Vault', style: TextStyle(fontWeight: FontWeight.w300, letterSpacing: size * 0.02)),
        ]),
        textAlign: TextAlign.center,
        style: TextStyle(fontSize: size, color: color, height: 1.1),
        semanticsLabel: 'VODO Vault',
      );
}

/// The web sidebar's teal gradient.
const brandGradient = LinearGradient(
  begin: Alignment.topCenter,
  end: Alignment.bottomCenter,
  colors: [brandTeal, Color(0xFF0B3F42)],
);

/// Faint rings echoing the logo, for the splash and sign-in backgrounds.
class BrandRings extends StatelessWidget {
  const BrandRings({super.key});

  @override
  Widget build(BuildContext context) => IgnorePointer(
        child: Stack(children: [
          Positioned(top: -90, right: -70, child: _ring(260, accentOrange.withValues(alpha: 0.16))),
          Positioned(top: 40, right: 70, child: _ring(120, Colors.white.withValues(alpha: 0.06))),
          Positioned(bottom: -120, left: -90, child: _ring(300, Colors.white.withValues(alpha: 0.07))),
        ]),
      );

  static Widget _ring(double size, Color color) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: color, width: size * 0.16)),
      );
}
