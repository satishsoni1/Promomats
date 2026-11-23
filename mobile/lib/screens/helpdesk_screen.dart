import 'package:flutter/material.dart';

import '../api.dart';
import '../widgets/common.dart';

/// In-app helpdesk, as on the web: raise a ticket and follow its status.
class HelpdeskScreen extends StatefulWidget {
  const HelpdeskScreen({super.key});

  @override
  State<HelpdeskScreen> createState() => _HelpdeskScreenState();
}

class _HelpdeskScreenState extends State<HelpdeskScreen> {
  Json? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await ApiClient.instance.helpdesk();
      if (mounted) setState(() => (_data = d, _error = null));
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _raise() async {
    final done = await showSheet<bool>(context, _TicketForm(data: _data!));
    if (done == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final tickets = ((_data?['tickets'] as List?) ?? []).cast<Json>();
    return Scaffold(
      appBar: AppBar(title: const Text('Helpdesk')),
      floatingActionButton: _data == null ? null : FloatingActionButton.extended(onPressed: _raise, icon: const Icon(Icons.support_agent), label: const Text('Raise a ticket')),
      body: _data == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: tickets.isEmpty
                  ? const EmptyView('No tickets yet. Raise one for a glitch or a change request.', icon: Icons.support_agent)
                  : ListView(padding: const EdgeInsets.fromLTRB(12, 8, 12, 96), children: [
                      for (final t in tickets)
                        Card(
                          child: ListTile(
                            title: Text('${t['subject']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                            subtitle: Text('${t['reference']} · ${t['category']} · ${t['priority']}\nFirst response due ${fmtShort(t['first_response_due_at'] as String?)}'),
                            trailing: Chip(label: Text('${t['status']}', style: const TextStyle(fontSize: 11)), visualDensity: VisualDensity.compact),
                            isThreeLine: true,
                          ),
                        ),
                    ]),
            ),
    );
  }
}

class _TicketForm extends StatefulWidget {
  const _TicketForm({required this.data});
  final Json data;

  @override
  State<_TicketForm> createState() => _TicketFormState();
}

class _TicketFormState extends State<_TicketForm> {
  String _category = 'technical_issue';
  String _priority = 'normal';
  final _subject = TextEditingController();
  final _description = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final cats = (widget.data['categories'] as List).cast<Json>();
    final pris = (widget.data['priorities'] as List).cast<Json>();
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Raise a ticket', style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        initialValue: _category,
        decoration: const InputDecoration(labelText: 'Category'),
        items: [for (final c in cats) DropdownMenuItem(value: '${c['key']}', child: Text('${c['label']}'))],
        onChanged: (v) => setState(() => _category = v ?? _category),
      ),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        initialValue: _priority,
        decoration: const InputDecoration(labelText: 'Priority'),
        items: [for (final p in pris) DropdownMenuItem(value: '${p['key']}', child: Text('${p['label']}'))],
        onChanged: (v) => setState(() => _priority = v ?? _priority),
      ),
      const SizedBox(height: 12),
      TextField(controller: _subject, decoration: const InputDecoration(labelText: 'Subject')),
      const SizedBox(height: 12),
      TextField(controller: _description, minLines: 4, maxLines: 8, decoration: const InputDecoration(labelText: 'What happened / what do you need?')),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: () async {
          final res = await runAction(context, () => ApiClient.instance.raiseTicket({
                'category': _category,
                'priority': _priority,
                'subject': _subject.text.trim(),
                'description': _description.text.trim(),
                'page_url': 'mobile app',
              }));
          if (res != null && context.mounted) Navigator.of(context).pop(true);
        },
        child: const Text('Submit ticket'),
      ),
    ]);
  }
}
