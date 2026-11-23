import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../theme.dart';
import '../widgets/brand.dart';
import 'home_shell.dart';

/// Sign-in with the same email and password as the web app. Teal brand header
/// (the web sidebar's gradient) with the VODO tile, and a white sign-in card.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _server = TextEditingController(text: ApiClient.instance.baseUrl);
  bool _busy = false;
  bool _showServer = false;
  bool _hidePassword = true;
  String? _error;

  Future<void> _signIn() async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = 'Enter your email and password.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_server.text.trim() != ApiClient.instance.baseUrl) {
        await ApiClient.instance.setBaseUrl(_server.text);
      }
      await ApiClient.instance.login(_email.text.trim(), _password.text);
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const HomeShell()));
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _forgotPassword() {
    final base = _server.text.trim().isEmpty ? ApiClient.instance.baseUrl : _server.text.trim().replaceAll(RegExp(r'/+$'), '');
    launchUrl(Uri.parse('$base/forgot-password'), mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: brandGradient),
        child: Stack(children: [
          const BrandRings(),
          SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: AutofillGroup(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      // ---- brand header ----
                      const Center(child: VodoTile(size: 92)),
                      const SizedBox(height: 18),
                      Text('VODO',
                          textAlign: TextAlign.center,
                          style: text.headlineLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w900, letterSpacing: 2)),
                      const SizedBox(height: 4),
                      Text('PromoMats review & approval',
                          textAlign: TextAlign.center, style: text.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: 0.75))),
                      const SizedBox(height: 28),

                      // ---- sign-in card ----
                      Card(
                        elevation: 10,
                        shadowColor: Colors.black38,
                        margin: EdgeInsets.zero,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            Text('Welcome back', style: text.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
                            const SizedBox(height: 2),
                            Text('Sign in with your VODO account', style: text.bodySmall?.copyWith(color: Colors.grey.shade600)),
                            const SizedBox(height: 20),
                            TextField(
                              controller: _email,
                              keyboardType: TextInputType.emailAddress,
                              textInputAction: TextInputAction.next,
                              autofillHints: const [AutofillHints.email, AutofillHints.username],
                              decoration: _field('Email', Icons.mail_outline),
                            ),
                            const SizedBox(height: 14),
                            TextField(
                              controller: _password,
                              obscureText: _hidePassword,
                              autofillHints: const [AutofillHints.password],
                              onSubmitted: (_) => _busy ? null : _signIn(),
                              decoration: _field('Password', Icons.lock_outline).copyWith(
                                suffixIcon: IconButton(
                                  tooltip: _hidePassword ? 'Show password' : 'Hide password',
                                  icon: Icon(_hidePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                                  onPressed: () => setState(() => _hidePassword = !_hidePassword),
                                ),
                              ),
                            ),
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton(onPressed: _forgotPassword, child: const Text('Forgot password?')),
                            ),
                            if (_showServer) ...[
                              TextField(
                                controller: _server,
                                keyboardType: TextInputType.url,
                                decoration: _field('Server address', Icons.dns_outlined).copyWith(hintText: 'https://vodo.example.com'),
                              ),
                              const SizedBox(height: 12),
                            ],
                            if (_error != null)
                              Container(
                                margin: const EdgeInsets.only(bottom: 12),
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(10)),
                                child: Row(children: [
                                  Icon(Icons.error_outline, color: Colors.red.shade700, size: 18),
                                  const SizedBox(width: 8),
                                  Expanded(child: Text(_error!, style: TextStyle(color: Colors.red.shade800))),
                                ]),
                              ),
                            FilledButton(
                              onPressed: _busy ? null : _signIn,
                              style: FilledButton.styleFrom(
                                padding: const EdgeInsets.symmetric(vertical: 16),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                              ),
                              child: _busy
                                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                  : const Text('Sign in'),
                            ),
                            TextButton.icon(
                              onPressed: () => setState(() => _showServer = !_showServer),
                              icon: Icon(_showServer ? Icons.expand_less : Icons.settings_outlined, size: 18),
                              label: Text(_showServer ? 'Hide server address' : 'Server settings'),
                              style: TextButton.styleFrom(foregroundColor: Colors.grey.shade700),
                            ),
                          ]),
                        ),
                      ),
                      const SizedBox(height: 20),
                      Wrap(alignment: WrapAlignment.center, crossAxisAlignment: WrapCrossAlignment.center, spacing: 6, children: [
                        Icon(Icons.verified_user_outlined, size: 14, color: Colors.white.withValues(alpha: 0.7)),
                        Text('Secure sign-in · same account as the web',
                            textAlign: TextAlign.center, style: text.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.7))),
                      ]),
                    ]),
                  ),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }

  InputDecoration _field(String label, IconData icon) => InputDecoration(
        labelText: label,
        prefixIcon: Icon(icon, color: brandTeal),
        filled: true,
        fillColor: const Color(0xFFF4F7F8),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: Colors.grey.shade200)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: brandTeal, width: 1.6)),
        contentPadding: const EdgeInsets.symmetric(vertical: 16, horizontal: 12),
      );
}
