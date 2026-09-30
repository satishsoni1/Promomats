import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vodo_mobile/screens/login_screen.dart';

void main() {
  testWidgets('sign-in screen shows the credential fields', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));

    expect(find.text('VODO'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Email'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Password'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Sign in'), findsOneWidget);

    await tester.tap(find.text('Server settings'));
    await tester.pump();
    expect(find.widgetWithText(TextField, 'Server address'), findsOneWidget);
  });
}
