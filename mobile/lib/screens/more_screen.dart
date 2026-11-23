import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../theme.dart';
import 'helpdesk_screen.dart';

/// Profile, helpdesk, the web version and sign-out.
class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key, required this.user, required this.onSignedOut});
  final Json? user;
  final VoidCallback onSignedOut;

  @override
  Widget build(BuildContext context) {
    final roles = ((user?['roles'] as List?) ?? []).join(', ');
    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(children: [
        if (user != null)
          ListTile(
            leading: CircleAvatar(backgroundColor: brandTeal, child: Text('${user!['name']}'.substring(0, 1), style: const TextStyle(color: Colors.white))),
            title: Text('${user!['name']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text('${user!['email']}${roles.isNotEmpty ? '\n$roles' : ''}'),
            isThreeLine: roles.isNotEmpty,
          ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.support_agent),
          title: const Text('Helpdesk'),
          subtitle: const Text('Report a glitch or request a change'),
          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const HelpdeskScreen())),
        ),
        ListTile(
          leading: const Icon(Icons.public),
          title: const Text('Open the web version'),
          subtitle: Text(ApiClient.instance.baseUrl),
          onTap: () => launchUrl(Uri.parse(ApiClient.instance.baseUrl), mode: LaunchMode.externalApplication),
        ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.logout, color: Colors.red),
          title: const Text('Sign out', style: TextStyle(color: Colors.red)),
          onTap: () async {
            await ApiClient.instance.logout();
            onSignedOut();
          },
        ),
      ]),
    );
  }
}
