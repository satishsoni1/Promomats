import 'package:flutter/material.dart';

import '../api.dart';
import '../navigation.dart';
import '../theme.dart';
import 'common.dart';

/// One piece of Design Team / Content Agency work: take it, hand it to a
/// colleague, or upload the file (which goes back to the Brand Manager to confirm).
class WorkTaskCard extends StatelessWidget {
  const WorkTaskCard({super.key, required this.task, required this.onChanged, this.showDocument = true});
  final Json task;
  final VoidCallback onChanged;
  final bool showDocument;

  int get _id => task['work_task_id'] as int;

  Future<void> _take(BuildContext context) async {
    if (await runAction(context, () => ApiClient.instance.takeWork(_id)) != null) onChanged();
  }

  Future<void> _assign(BuildContext context) async {
    final members = (task['members'] as List? ?? []).cast<Json>();
    final picked = await showSheet<int>(
      context,
      Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('Assign to', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 8),
        for (final m in members)
          ListTile(
            leading: const Icon(Icons.person_outline),
            title: Text('${m['name']}'),
            trailing: m['id'] == task['assigned_to_id'] ? const Icon(Icons.check, color: brandTeal) : null,
            onTap: () => Navigator.of(context).pop(m['id'] as int),
          ),
      ]),
    );
    if (picked != null && context.mounted && await runAction(context, () => ApiClient.instance.assignWork(_id, picked)) != null) onChanged();
  }

  Future<void> _upload(BuildContext context) async {
    final done = await showSheet<bool>(context, _UploadSheet(taskId: _id));
    if (done == true) onChanged();
  }

  Future<void> _withdraw(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Withdraw from the ${task['team_label']}?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Withdraw')),
        ],
      ),
    );
    if (ok == true && context.mounted && await runAction(context, () => ApiClient.instance.cancelWork(_id)) != null) onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final doc = task['document'] as Json;
    final overdue = task['is_overdue'] == true;
    final text = Theme.of(context).textTheme;

    return Card(
      color: overdue ? Colors.red.shade50 : null,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (showDocument)
            InkWell(
              onTap: () async {
                await openJob(context, doc['id'] as int);
                onChanged();
              },
              child: Text('${doc['title']}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
            ),
          const SizedBox(height: 2),
          Text(
            [if (showDocument) doc['reference_no'], task['type'], task['team_label'], 'from ${task['requested_by']}'].join(' · '),
            style: text.bodySmall,
          ),
          Text(task['assigned_to'] != null ? 'Assigned to ${task['assigned_to']}' : 'Unassigned (whole team)', style: text.bodySmall),
          if ((task['instructions'] ?? '').toString().isNotEmpty) ...[
            const SizedBox(height: 6),
            Text('${task['instructions']}'),
          ],
          const SizedBox(height: 6),
          DueChip(iso: task['due_at'] as String?, overdue: overdue),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 4, children: [
            if (task['can_take'] == true) OutlinedButton(onPressed: () => _take(context), child: const Text('Take it')),
            if (task['can_assign'] == true && (task['members'] as List? ?? []).isNotEmpty)
              OutlinedButton.icon(onPressed: () => _assign(context), icon: const Icon(Icons.person_add_alt, size: 18), label: const Text('Assign')),
            if (task['can_upload'] == true)
              FilledButton.icon(onPressed: () => _upload(context), icon: const Icon(Icons.upload, size: 18), label: const Text('Upload file')),
            if (task['can_withdraw'] == true)
              TextButton(onPressed: () => _withdraw(context), style: TextButton.styleFrom(foregroundColor: Colors.red), child: const Text('Withdraw')),
          ]),
        ]),
      ),
    );
  }
}

class _UploadSheet extends StatefulWidget {
  const _UploadSheet({required this.taskId});
  final int taskId;

  @override
  State<_UploadSheet> createState() => _UploadSheetState();
}

class _UploadSheetState extends State<_UploadSheet> {
  List<UploadFile> _file = [];
  final _notes = TextEditingController();

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Upload file', style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 4),
      Text('The Brand Manager checks your upload before it goes for review.', style: Theme.of(context).textTheme.bodySmall),
      const SizedBox(height: 12),
      FileField(label: 'Choose the file', files: _file, onChanged: (f) => setState(() => _file = f)),
      const SizedBox(height: 12),
      TextField(controller: _notes, decoration: const InputDecoration(labelText: 'Notes for the Brand Manager (optional)')),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: _file.isEmpty
            ? null
            : () async {
                final res = await runAction(context, () => ApiClient.instance.completeWork(widget.taskId, _file.first, notes: _notes.text.trim()));
                if (res != null && context.mounted) Navigator.of(context).pop(true);
              },
        child: const Text('Upload & send to Brand Manager'),
      ),
    ]);
  }
}
