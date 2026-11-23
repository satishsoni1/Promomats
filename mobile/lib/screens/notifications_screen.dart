import 'package:flutter/material.dart';

import '../api.dart';
import '../navigation.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// The web's notification bell: every workflow update, newest first. Tapping one
/// marks it read and opens the job.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.onChanged});
  final VoidCallback? onChanged;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Json>? _items;
  int _unread = 0;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final res = await ApiClient.instance.notifications();
      if (!mounted) return;
      setState(() {
        _items = (res['notifications'] as List).cast<Json>();
        _unread = res['unread'] as int? ?? 0;
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
    widget.onChanged?.call();
  }

  Future<void> _open(Json n) async {
    if (n['read'] != true) {
      try {
        await ApiClient.instance.readNotification('${n['id']}');
      } on ApiException catch (_) {}
    }
    if (!mounted) return;
    if (n['document_id'] != null) await openJob(context, n['document_id'] as int);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Notifications${_unread > 0 ? ' ($_unread)' : ''}'),
        actions: [
          if (_unread > 0)
            TextButton(
              onPressed: () async {
                if (await runAction(context, ApiClient.instance.readAllNotifications, success: 'All marked as read.') != null) _load();
              },
              child: const Text('Mark all read', style: TextStyle(color: Colors.white)),
            ),
        ],
      ),
      body: _items == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: _items!.isEmpty
                  ? const EmptyView('No notifications yet.', icon: Icons.notifications_none)
                  : ListView.separated(
                      itemCount: _items!.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, i) {
                        final n = _items![i];
                        final unread = n['read'] != true;
                        return ListTile(
                          tileColor: unread ? brandTeal.withValues(alpha: 0.05) : null,
                          leading: Icon(unread ? Icons.circle : Icons.circle_outlined, size: 10, color: unread ? accentOrange : Colors.grey),
                          title: Text('${n['message']}', style: TextStyle(fontWeight: unread ? FontWeight.w600 : null)),
                          subtitle: Text([n['reference_no'], fmtShort(n['created_at'] as String?)].where((e) => e != null).join(' · ')),
                          onTap: () => _open(n),
                        );
                      },
                    ),
            ),
    );
  }
}
