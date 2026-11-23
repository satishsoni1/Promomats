import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vodo_mobile/screens/login_screen.dart';
import 'package:vodo_mobile/screens/splash_screen.dart';
import 'package:vodo_mobile/theme.dart';
import 'package:vodo_mobile/widgets/brand.dart';

void main() {
  testWidgets('sign-in screen shows the brand, the credential fields and server settings', (tester) async {
    await tester.pumpWidget(MaterialApp(theme: vodoTheme(), home: const LoginScreen()));

    expect(find.byType(VodoWordmark), findsOneWidget);
    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Email'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Password'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Sign in'), findsOneWidget);
    expect(find.text('Forgot password?'), findsOneWidget);

    // Empty form: a clear message instead of a server round trip.
    await tester.ensureVisible(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pump();
    expect(find.text('Enter your email and password.'), findsOneWidget);

    // Password visibility toggle.
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Password')).obscureText, isTrue);
    await tester.tap(find.byTooltip('Show password'));
    await tester.pump();
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Password')).obscureText, isFalse);

    await tester.ensureVisible(find.text('Server settings'));
    await tester.tap(find.text('Server settings'));
    await tester.pump();
    expect(find.widgetWithText(TextField, 'Server address'), findsOneWidget);
  });

  testWidgets('splash shows the logo and goes to sign-in when there is no session', (tester) async {
    await tester.pumpWidget(MaterialApp(theme: vodoTheme(), home: const SplashScreen(minimumDuration: Duration(milliseconds: 300))));

    expect(find.byType(VodoMark), findsOneWidget);
    expect(find.byType(VodoWordmark), findsOneWidget);
    expect(find.text('Review & approval'), findsOneWidget);

    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.byType(LoginScreen), findsOneWidget);
  });
}
