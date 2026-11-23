import 'package:flutter/material.dart';

import 'api.dart';
import 'screens/splash_screen.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await ApiClient.instance.load();
  runApp(const VodoApp());
}

class VodoApp extends StatelessWidget {
  const VodoApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'VODO',
      debugShowCheckedModeBanner: false,
      theme: vodoTheme(),
      // Picks up from the native launch screen, checks the session, then routes.
      home: const SplashScreen(),
    );
  }
}
