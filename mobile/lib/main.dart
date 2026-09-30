import 'package:flutter/material.dart';

import 'api.dart';
import 'screens/login_screen.dart';
import 'screens/tasks_screen.dart';

const brandTeal = Color(0xFF0F5D61);
const accentOrange = Color(0xFFDD6E17);

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
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: brandTeal, primary: brandTeal, secondary: accentOrange),
        useMaterial3: true,
        appBarTheme: const AppBarTheme(backgroundColor: brandTeal, foregroundColor: Colors.white),
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      ),
      home: ApiClient.instance.isSignedIn ? const TasksScreen() : const LoginScreen(),
    );
  }
}
