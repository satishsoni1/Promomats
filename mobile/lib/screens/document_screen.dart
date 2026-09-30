import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../main.dart';
import 'viewer_screen.dart';

/// One job: open the creative, see where it is in the workflow and what reviewers
/// said, comment, and record a decision when it's the user's turn.
class DocumentScreen extends StatefulWidget {
  const DocumentScreen({super.key, required this.documentId});
  final int documentId;

  @override
  State<DocumentScreen> createState() => _DocumentScreenState();
}

class _DocumentScreenState extends State<DocumentScreen> {
  Map<String, dynamic>? _data;
  String? _error;
  final _commentCtrl = TextEditingController();
  bool _posting = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await ApiClient.instance.document(widget.documentId);
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _postComment() async {
    final text = _commentCtrl.text.trim();
    if (text.isEmpty) return;
    setState(() => _posting = true);
    try {
      await ApiClient.instance.comment(widget.documentId, text);
      _commentCtrl.clear();
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    } finally {
      if (mounted) setState(() => _posting = false);
    }
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  Future<void> _openDecision(Map<String, dynamic> task) async {
    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _DecisionSheet(documentId: widget.documentId, isDraft: task['is_draft'] == true),
    );
    if (result == true && mounted) {
      _snack('Your decision has been recorded.');
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AppBar(title: Text(data?['document']?['reference_no'] ?? 'Document')),
      body: data == null
          ? Center(child: _error != null ? Text(_error!) : const CircularProgressIndicator())
          : RefreshIndicator(onRefresh: _load, child: _body(data)),
      bottomNavigationBar: _decisionBar(data),
    );
  }

  Widget? _decisionBar(Map<String, dynamic>? data) {
    final task = data?['my_task'] as Map<String, dynamic>?;
    if (task == null) return null;
    final canDecide = task['can_decide'] == true;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        child: canDecide
            ? FilledButton.icon(
                onPressed: () => _openDecision(task),
                icon: const Icon(Icons.how_to_vote_outlined),
                label: Text(task['is_draft'] == true ? 'Submit draft' : 'Record decision — ${task['stage']}'),
                style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
              )
            : const Text('Brand Managers assign and upload, but cannot approve or reject.', textAlign: TextAlign.center),
      ),
    );
  }

  Widget _body(Map<String, dynamic> data) {
    final doc = data['document'] as Map<String, dynamic>;
    final file = data['file'] as Map<String, dynamic>?;
    final stages = (data['stages'] as List?) ?? [];
    final history = (data['history'] as List?) ?? [];
    final comments = (data['comments'] as List?) ?? [];
    final text = Theme.of(context).textTheme;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('${doc['title']}', style: text.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        Wrap(spacing: 8, runSpacing: 4, children: [
          Chip(label: Text('${doc['status_label']}'), visualDensity: VisualDensity.compact),
          if (doc['collateral'] != null) Chip(label: Text('${doc['collateral']}'), visualDensity: VisualDensity.compact),
        ]),
        Text('Task owner: ${doc['owner']}${doc['due_date'] != null ? ' · due ${doc['due_date']}' : ''}', style: text.bodySmall),
        const SizedBox(height: 12),
        if (file != null)
          Card(
            color: brandTeal.withValues(alpha: 0.06),
            child: ListTile(
              leading: Icon(file['kind'] == 'pdf' ? Icons.picture_as_pdf : (file['kind'] == 'image' ? Icons.image_outlined : Icons.insert_drive_file_outlined), color: brandTeal),
              title: Text('${file['name']}', maxLines: 1, overflow: TextOverflow.ellipsis),
              subtitle: Text('v${file['version_no']} · ${file['size']}'),
              trailing: const Icon(Icons.open_in_full),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ViewerScreen(file: file))),
            ),
          )
        else
          const Card(child: ListTile(leading: Icon(Icons.hourglass_empty), title: Text('Waiting for artwork from the Design Team'))),
        _section('Workflow'),
        for (final s in stages) _stageRow(s as Map<String, dynamic>),
        if (history.isNotEmpty) _section('Review history'),
        for (final h in history)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(_decisionIcon(h['decision'] as String?), color: _decisionColor(h['decision'] as String?)),
            title: Text('${h['decision_label']} — ${h['stage']}'),
            subtitle: Text('${h['by']} · ${_date(h['at'] as String?)}${(h['comments'] ?? '').toString().isNotEmpty ? '\n${h['comments']}' : ''}'),
          ),
        _section('Comments (${comments.length})'),
        Row(children: [
          Expanded(child: TextField(controller: _commentCtrl, minLines: 1, maxLines: 4, decoration: const InputDecoration(hintText: 'Add a comment…', isDense: true))),
          const SizedBox(width: 8),
          IconButton.filled(onPressed: _posting ? null : _postComment, icon: const Icon(Icons.send)),
        ]),
        const SizedBox(height: 8),
        for (final c in comments) _commentCard(c as Map<String, dynamic>),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _section(String title) => Padding(
        padding: const EdgeInsets.only(top: 20, bottom: 6),
        child: Text(title, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold, color: brandTeal)),
      );

  Widget _stageRow(Map<String, dynamic> s) {
    final status = s['status'] as String?;
    final withWhom = (s['with'] as List?)?.join(', ') ?? '';
    final (icon, color) = switch (status) {
      'resolved' => (Icons.check_circle, Colors.green),
      'open' => (Icons.radio_button_checked, accentOrange),
      _ => (Icons.radio_button_unchecked, Colors.grey),
    };
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: Icon(icon, color: color),
      title: Text('${s['name']}${s['parallel_group'] != null ? '  (parallel)' : ''}'),
      subtitle: status == 'open' && withWhom.isNotEmpty ? Text('With $withWhom') : null,
    );
  }

  Widget _commentCard(Map<String, dynamic> c) {
    final replies = (c['replies'] as List?) ?? [];
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('${c['author']}', style: const TextStyle(fontWeight: FontWeight.w600))),
            if (c['page'] != null) Text('p.${c['page']}  ', style: Theme.of(context).textTheme.bodySmall),
            Text(_date(c['created_at'] as String?), style: Theme.of(context).textTheme.bodySmall),
          ]),
          if (c['selected_text'] != null) ...[
            const SizedBox(height: 6),
            Text('“${c['selected_text']}”', style: const TextStyle(fontStyle: FontStyle.italic, color: Colors.black54)),
          ],
          if (c['replacement_text'] != null) ...[
            const SizedBox(height: 4),
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(6)),
              child: Text('Replace with: ${c['replacement_text']}', style: TextStyle(color: Colors.green.shade900)),
            ),
          ],
          const SizedBox(height: 6),
          Text('${c['body']}', style: TextStyle(decoration: c['resolved'] == true ? TextDecoration.lineThrough : null)),
          for (final r in replies)
            Padding(
              padding: const EdgeInsets.only(left: 12, top: 6),
              child: Text('${r['author']}: ${r['body']}', style: Theme.of(context).textTheme.bodySmall),
            ),
        ]),
      ),
    );
  }

  static IconData _decisionIcon(String? d) => switch (d) {
        'approved' => Icons.check_circle_outline,
        'approved_with_changes' => Icons.edit_note,
        _ => Icons.cancel_outlined,
      };

  static Color _decisionColor(String? d) => switch (d) {
        'approved' => Colors.green,
        'approved_with_changes' => Colors.amber.shade800,
        _ => Colors.red,
      };

  static String _date(String? iso) => iso == null ? '' : DateFormat('d MMM yyyy, HH:mm').format(DateTime.parse(iso).toLocal());
}

class _DecisionSheet extends StatefulWidget {
  const _DecisionSheet({required this.documentId, required this.isDraft});
  final int documentId;
  final bool isDraft;

  @override
  State<_DecisionSheet> createState() => _DecisionSheetState();
}

class _DecisionSheetState extends State<_DecisionSheet> {
  String? _decision;
  final _comments = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    if (_decision == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ApiClient.instance.decide(widget.documentId, _decision!, _comments.text.trim());
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final options = widget.isDraft
        ? const [('approved', 'Submit to next stage', 'Draft is ready — move it on'), ('approved_with_changes', 'Needs changes', 'Back to the task owner to fix')]
        : const [
            ('approved', 'Approved', 'No changes needed'),
            ('approved_with_changes', 'Approved with changes', 'Task owner fixes it, then it moves on'),
            ('not_approved', 'Not approved', 'Back to the task owner'),
          ];

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(widget.isDraft ? 'Submit draft' : 'Your decision', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 8),
        RadioGroup<String>(
          groupValue: _decision,
          onChanged: (v) => setState(() => _decision = v),
          child: Column(children: [
            for (final (value, label, hint) in options)
              RadioListTile<String>(value: value, title: Text(label), subtitle: Text(hint), contentPadding: EdgeInsets.zero),
          ]),
        ),
        TextField(
          controller: _comments,
          minLines: 2,
          maxLines: 5,
          decoration: InputDecoration(labelText: _decision == 'approved' || _decision == null ? 'Comments (optional)' : 'Comments (required)'),
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        ],
        const SizedBox(height: 12),
        FilledButton(
          onPressed: _busy || _decision == null ? null : _submit,
          style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
          child: _busy ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('Submit'),
        ),
      ]),
    );
  }
}
