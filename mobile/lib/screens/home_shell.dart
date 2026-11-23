import 'package:flutter/material.dart';

import '../api.dart';
import '../navigation.dart';
import 'active_workflow_screen.dart';
import 'create_job_screen.dart';
import 'library_screen.dart';
import 'login_screen.dart';
import 'more_screen.dart';
import 'notifications_screen.dart';

/// The app's main navigation - the web sidebar, as a bottom bar: Active Workflow,
/// Library, New job (for people who may upload), Notifications and More.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;
  Json? _user;
  final _workflowKey = GlobalKey<ActiveWorkflowScreenState>();

  @override
  void initState() {
    super.initState();
    _user = ApiClient.instance.user;
    _refreshUser();
  }

  Future<void> _refreshUser() async {
    try {
      final u = await ApiClient.instance.me();
      if (mounted) setState(() => _user = u);
    } on ApiException catch (e) {
      if (e.isUnauthenticated) _toLogin();
    }
  }

  void _toLogin() {
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
  }

  bool get _canUpload => _user?['can_upload'] == true;

  Future<void> _newJob() async {
    final created = await Navigator.of(context).push<int>(MaterialPageRoute(builder: (_) => const CreateJobScreen()));
    if (created != null && mounted) {
      setState(() => _index = 0);
      await openJob(context, created);
      _workflowKey.currentState?.reload();
    }
    _refreshUser();
  }

  @override
  Widget build(BuildContext context) {
    final actionCount = (_user?['action_count'] as int?) ?? 0;
    final unread = (_user?['unread_notifications'] as int?) ?? 0;

    final pages = <Widget>[
      ActiveWorkflowScreen(key: _workflowKey, onChanged: _refreshUser),
      const LibraryScreen(),
      NotificationsScreen(onChanged: _refreshUser),
      MoreScreen(user: _user, onSignedOut: _toLogin),
    ];

    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      floatingActionButton: _index == 0 && _canUpload
          ? FloatingActionButton.extended(onPressed: _newJob, icon: const Icon(Icons.add), label: const Text('New job'))
          : null,
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) {
          setState(() => _index = i);
          _refreshUser();
        },
        destinations: [
          NavigationDestination(
            icon: Badge(isLabelVisible: actionCount > 0, label: Text('$actionCount'), child: const Icon(Icons.task_alt_outlined)),
            selectedIcon: const Icon(Icons.task_alt),
            label: 'Workflow',
          ),
          const NavigationDestination(icon: Icon(Icons.menu_book_outlined), selectedIcon: Icon(Icons.menu_book), label: 'Library'),
          NavigationDestination(
            icon: Badge(isLabelVisible: unread > 0, label: Text('$unread'), child: const Icon(Icons.notifications_none)),
            selectedIcon: const Icon(Icons.notifications),
            label: 'Alerts',
          ),
          const NavigationDestination(icon: Icon(Icons.more_horiz), label: 'More'),
        ],
      ),
    );
  }
}
