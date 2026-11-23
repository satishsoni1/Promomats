import 'package:flutter/material.dart';

/// VODO brand colours - the same teal and orange as the web app.
const brandTeal = Color(0xFF0F5D61);
const accentOrange = Color(0xFFDD6E17);

ThemeData vodoTheme() => ThemeData(
      colorScheme: ColorScheme.fromSeed(seedColor: brandTeal, primary: brandTeal, secondary: accentOrange),
      useMaterial3: true,
      scaffoldBackgroundColor: const Color(0xFFF6F8F9),
      appBarTheme: const AppBarTheme(backgroundColor: brandTeal, foregroundColor: Colors.white),
      inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder(), isDense: true),
      cardTheme: const CardThemeData(color: Colors.white, surfaceTintColor: Colors.white, margin: EdgeInsets.symmetric(vertical: 4)),
      navigationBarTheme: NavigationBarThemeData(indicatorColor: brandTeal.withValues(alpha: 0.15)),
    );
