import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../main.dart';
import 'document_screen.dart';
import 'login_screen.dart';

/// "Active Workflow" on the phone: approvals waiting on me, revisions of my jobs,
/// and Design Team work - the same buckets as the web app.
class TasksScreen extends StatefulWidget {
  const TasksScreen({super.key});

  @override
  State<TasksScreen> createState() => _TasksScreenState();
}

class _TasksScreenState extends State<TasksScreen> {
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _data == null;
      _error = null;
    });
    try {
      final data = await ApiClient.instance.tasks();
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (e.isUnauthenticated) return _toLogin();
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _toLogin() {
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
  }

  Future<void> _open(int documentId) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentScreen(documentId: documentId)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final approvals = (_data?['approvals'] as List?) ?? [];
    final revisions = (_data?['revisions'] as List?) ?? [];
    final work = (_data?['work'] as List?) ?? [];

    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Active Workflow'),
          actions: [
            IconButton(
              tooltip: 'Sign out',
              icon: const Icon(Icons.logout),
              onPressed: () async {
                await ApiClient.instance.logout();
                _toLogin();
              },
            ),
          ],
          bottom: TabBar(
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            indicatorColor: accentOrange,
            tabs: [
              Tab(text: 'Review (${approvals.length})'),
              Tab(text: 'Revise (${revisions.length})'),
              Tab(text: 'Design (${work.length})'),
            ],
          ),
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _ErrorView(message: _error!, onRetry: _load)
                : TabBarView(children: [
                    _TaskList(
                      onRefresh: _load,
                      empty: 'Nothing is waiting for your review.',
                      children: [for (final t in approvals) _approvalTile(t as Map<String, dynamic>)],
                    ),
                    _TaskList(
                      onRefresh: _load,
                      empty: 'None of your jobs need a revision.',
                      children: [for (final d in revisions) _documentTile(d as Map<String, dynamic>, subtitle: 'Revision needed — open to see the feedback')],
                    ),
                    _TaskList(
                      onRefresh: _load,
                      empty: 'No design work for you.',
                      children: [
                        for (final w in work)
                          _documentTile(
                            (w as Map<String, dynamic>)['document'] as Map<String, dynamic>,
                            subtitle: '${w['type']} · ${w['assigned_to'] ?? 'Unassigned'}${_dueText(w['due_at'] as String?) ?? ''}',
                          ),
                      ],
                    ),
                  ]),
      ),
    );
  }

  Widget _approvalTile(Map<String, dynamic> task) {
    final doc = task['document'] as Map<String, dynamic>;
    final overdue = task['is_overdue'] == true;
    final dueSoon = task['is_due_soon'] == true;
    return Card(
      child: ListTile(
        onTap: () => _open(doc['id'] as int),
        title: Text('${doc['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text([
          doc['reference_no'],
          task['stage'],
          if (doc['collateral'] != null) doc['collateral'],
          'Owner: ${doc['owner']}',
        ].join(' · ')),
        trailing: task['due_at'] == null
            ? null
            : Chip(
                label: Text(overdue ? 'Overdue' : (dueSoon ? 'Due soon' : _shortDate(task['due_at'] as String))),
                backgroundColor: overdue ? Colors.red.shade50 : (dueSoon ? Colors.amber.shade50 : null),
                labelStyle: TextStyle(fontSize: 11, color: overdue ? Colors.red.shade800 : (dueSoon ? Colors.amber.shade900 : null)),
                visualDensity: VisualDensity.compact,
              ),
      ),
    );
  }

  Widget _documentTile(Map<String, dynamic> doc, {required String subtitle}) {
    return Card(
      child: ListTile(
        onTap: () => _open(doc['id'] as int),
        title: Text('${doc['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text('${doc['reference_no']} · $subtitle'),
        trailing: const Icon(Icons.chevron_right),
      ),
    );
  }

  static String _shortDate(String iso) => DateFormat('d MMM, HH:mm').format(DateTime.parse(iso).toLocal());

  static String? _dueText(String? iso) => iso == null ? null : ' · due ${_shortDate(iso)}';
}

class _TaskList extends StatelessWidget {
  const _TaskList({required this.children, required this.onRefresh, required this.empty});
  final List<Widget> children;
  final Future<void> Function() onRefresh;
  final String empty;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: children.isEmpty
          ? ListView(children: [Padding(padding: const EdgeInsets.all(48), child: Text(empty, textAlign: TextAlign.center))])
          : ListView(padding: const EdgeInsets.all(8), children: children),
    );
  }
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: 12),
          OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
        ]),
      ),
    );
  }
}
