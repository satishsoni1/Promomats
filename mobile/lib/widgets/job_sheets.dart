import 'package:flutter/material.dart';

import '../api.dart';
import 'common.dart';

/// Record a decision. Labels follow the web exactly: draft stages say "Draft
/// aligned / Needs changes"; the offline Chairperson stage needs the signed copy.
class DecisionSheet extends StatefulWidget {
  const DecisionSheet({super.key, required this.documentId, required this.task});
  final int documentId;
  final Json task;

  @override
  State<DecisionSheet> createState() => _DecisionSheetState();
}

class _DecisionSheetState extends State<DecisionSheet> {
  String? _decision;
  final _comments = TextEditingController();
  List<UploadFile> _signed = [];

  bool get _draft => widget.task['is_draft'] == true;
  bool get _offline => widget.task['is_offline'] == true;
  bool get _final => widget.task['is_final'] == true;

  @override
  Widget build(BuildContext context) {
    final options = [
      (
        'approved',
        _draft ? 'Draft aligned' : (_offline ? 'Approved by the Chairperson' : 'Approved'),
        _draft ? 'Back to the Brand Manager to commission the creative' : (_final ? 'Closes the workflow' : 'Moves on to the next stage'),
      ),
      (
        'approved_with_changes',
        _draft ? 'Needs changes' : 'Approved with changes',
        _draft
            ? 'Back to the Brand Manager to revise, then back to you'
            : (_final ? 'Back to the Brand Manager to make the changes, then it closes' : 'Moves on to the next stage; the Brand Manager sees your changes'),
      ),
      ('not_approved', 'Not approved', 'Back to the Brand Manager to revise - returns to ${_offline ? 'this stage' : 'you'} once resubmitted'),
    ];
    final needsFile = _offline && _decision != null && _decision != 'not_approved';
    final needsComments = _decision != null && _decision != 'approved';

    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(_offline ? 'Record the offline sign-off' : (_draft ? 'Align the draft' : 'Your decision'), style: Theme.of(context).textTheme.titleLarge),
      Text('${widget.task['stage']}', style: Theme.of(context).textTheme.bodySmall),
      if (_offline) ...[
        const SizedBox(height: 8),
        const NoticeBanner(
          tone: 'muted',
          icon: Icons.info_outline,
          text: 'The Chairperson reviews this offline. Upload the copy you received back and record the outcome - approving closes the workflow as Approved for Production.',
        ),
      ],
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
        decoration: InputDecoration(labelText: needsComments ? 'Comments (required)' : 'Comments (optional)'),
      ),
      if (_offline) ...[
        const SizedBox(height: 12),
        FileField(
          label: 'Copy received from the Chairperson',
          hint: needsFile ? 'Required for an approval' : 'Optional',
          files: _signed,
          onChanged: (f) => setState(() => _signed = f),
        ),
      ],
      const SizedBox(height: 6),
      Text('Recorded with your name, the date, time and IP address.', style: Theme.of(context).textTheme.bodySmall),
      const SizedBox(height: 12),
      FilledButton(
        onPressed: _decision == null || (needsFile && _signed.isEmpty)
            ? null
            : () async {
                final res = await runAction(
                  context,
                  () => ApiClient.instance.decide(widget.documentId, _decision!, _comments.text.trim(), signedCopy: _signed.isEmpty ? null : _signed.first),
                );
                if (res != null && context.mounted) Navigator.of(context).pop(true);
              },
        style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
        child: Text(_offline ? 'Upload & record sign-off' : (_draft ? 'Submit' : 'Submit review')),
      ),
    ]);
  }
}

/// Upload a file (optional or required) with notes, then run [onSubmit].
class UploadSheet extends StatefulWidget {
  const UploadSheet({
    super.key,
    required this.title,
    required this.buttonLabel,
    required this.onSubmit,
    this.subtitle,
    this.fileRequired = true,
    this.notesLabel = 'Change notes',
    this.fileHint,
  });
  final String title;
  final String? subtitle;
  final String buttonLabel;
  final bool fileRequired;
  final String notesLabel;
  final String? fileHint;
  final Future<Json> Function(UploadFile? file, String notes) onSubmit;

  @override
  State<UploadSheet> createState() => _UploadSheetState();
}

class _UploadSheetState extends State<UploadSheet> {
  List<UploadFile> _file = [];
  final _notes = TextEditingController();

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(widget.title, style: Theme.of(context).textTheme.titleLarge),
      if (widget.subtitle != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(widget.subtitle!, style: Theme.of(context).textTheme.bodySmall)),
      const SizedBox(height: 12),
      FileField(label: widget.fileRequired ? 'Choose the file' : 'Choose a file (optional)', hint: widget.fileHint, files: _file, onChanged: (f) => setState(() => _file = f)),
      const SizedBox(height: 12),
      TextField(controller: _notes, decoration: InputDecoration(labelText: widget.notesLabel)),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: widget.fileRequired && _file.isEmpty
            ? null
            : () async {
                final res = await runAction(context, () => widget.onSubmit(_file.isEmpty ? null : _file.first, _notes.text.trim()));
                if (res != null && context.mounted) Navigator.of(context).pop(true);
              },
        style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
        child: Text(widget.buttonLabel),
      ),
    ]);
  }
}

/// Send the creative / revision to the Content Agency or the Internal Design Team.
class AssignTeamSheet extends StatefulWidget {
  const AssignTeamSheet({super.key, required this.documentId, required this.team, required this.members, this.instructions, this.allowSkipConfirmation = false, this.placeholder = false});
  final int documentId;
  final String team; // agency | design
  final List<Json> members;
  final String? instructions;
  final bool allowSkipConfirmation;
  final bool placeholder;

  @override
  State<AssignTeamSheet> createState() => _AssignTeamSheetState();
}

class _AssignTeamSheetState extends State<AssignTeamSheet> {
  late final _instructions = TextEditingController(text: widget.instructions ?? '');
  DateTime _due = DateTime.now().add(const Duration(hours: 48));
  int? _person;
  bool _skip = false;

  String get _teamName => widget.team == 'agency' ? 'Content Agency' : 'Internal Design Team';

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Send to $_teamName', style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 12),
      TextField(controller: _instructions, minLines: 3, maxLines: 6, decoration: InputDecoration(labelText: widget.placeholder ? 'Brief' : 'What should be done?')),
      if (!widget.placeholder) ...[
        const SizedBox(height: 12),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.event),
          title: const Text('Due date'),
          subtitle: Text(fmtDate(_due.toIso8601String(), pattern: 'd MMM yyyy')),
          onTap: () async {
            final d = await showDatePicker(context: context, initialDate: _due, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)));
            if (d != null) setState(() => _due = d);
          },
        ),
        DropdownButtonFormField<int?>(
          initialValue: _person,
          decoration: const InputDecoration(labelText: 'Person (optional)'),
          items: [
            DropdownMenuItem<int?>(value: null, child: Text('Whole $_teamName')),
            for (final m in widget.members) DropdownMenuItem<int?>(value: m['id'] as int, child: Text('${m['name']}')),
          ],
          onChanged: (v) => setState(() => _person = v),
        ),
        if (widget.allowSkipConfirmation && widget.team == 'design')
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            value: _skip,
            onChanged: (v) => setState(() => _skip = v ?? false),
            title: const Text("Skip my confirmation - send it back into review as soon as it's uploaded"),
          )
        else
          const Padding(padding: EdgeInsets.only(top: 8), child: Text('Their upload comes back to you to confirm before it goes for review.')),
      ],
      if (widget.members.isEmpty)
        Padding(padding: const EdgeInsets.only(top: 8), child: Text('No one has the $_teamName role yet - ask an admin to assign it.', style: TextStyle(color: Colors.amber.shade900))),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: () async {
          final api = ApiClient.instance;
          final res = await runAction(
            context,
            () => widget.placeholder
                ? api.assignPlaceholder(widget.documentId, widget.team, _instructions.text.trim())
                : api.requestWork(widget.documentId, team: widget.team, instructions: _instructions.text.trim(), due: _due, assignTo: _person, resubmit: _skip),
          );
          if (res != null && context.mounted) Navigator.of(context).pop(true);
        },
        style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
        child: Text('Send to $_teamName'),
      ),
    ]);
  }
}

/// Brand Manager: reassign a pending stakeholder, or move their due date.
class ReassignSheet extends StatefulWidget {
  const ReassignSheet({super.key, required this.documentId, required this.pending, required this.options});
  final int documentId;
  final Json pending;
  final List<Json> options;

  @override
  State<ReassignSheet> createState() => _ReassignSheetState();
}

class _ReassignSheetState extends State<ReassignSheet> {
  int? _user;
  final _reason = TextEditingController();

  int get _taskId => widget.pending['task_id'] as int;

  @override
  Widget build(BuildContext context) {
    final current = widget.pending['due_at'] != null ? DateTime.parse(widget.pending['due_at'] as String).toLocal() : DateTime.now().add(const Duration(hours: 48));
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('${widget.pending['stage']} - ${widget.pending['user']}', style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 12),
      DropdownButtonFormField<int>(
        initialValue: _user,
        isExpanded: true,
        decoration: const InputDecoration(labelText: 'Reassign to'),
        items: [for (final o in widget.options) if (o['name'] != widget.pending['user']) DropdownMenuItem(value: o['id'] as int, child: Text('${o['name']}'))],
        onChanged: (v) => setState(() => _user = v),
      ),
      const SizedBox(height: 8),
      TextField(controller: _reason, decoration: const InputDecoration(labelText: 'Reason (e.g. on leave)')),
      const SizedBox(height: 8),
      FilledButton(
        onPressed: _user == null
            ? null
            : () async {
                final res = await runAction(context, () => ApiClient.instance.reassign(widget.documentId, _taskId, _user!, _reason.text.trim()));
                if (res != null && context.mounted) Navigator.of(context).pop(true);
              },
        child: const Text('Reassign'),
      ),
      const Divider(height: 32),
      OutlinedButton.icon(
        icon: const Icon(Icons.event),
        label: Text('Change due date (now ${fmtShort(widget.pending['due_at'] as String?)})'),
        onPressed: () async {
          final d = await showDatePicker(context: context, initialDate: current.isAfter(DateTime.now()) ? current : DateTime.now(), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)));
          if (d == null || !context.mounted) return;
          final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(current));
          if (t == null || !context.mounted) return;
          final due = DateTime(d.year, d.month, d.day, t.hour, t.minute);
          final res = await runAction(context, () => ApiClient.instance.setDue(widget.documentId, _taskId, due));
          if (res != null && context.mounted) Navigator.of(context).pop(true);
        },
      ),
    ]);
  }
}

/// Write (or edit) a comment.
Future<String?> askText(BuildContext context, {required String title, String initial = '', String hint = 'Write a comment…'}) {
  final ctrl = TextEditingController(text: initial);
  return showSheet<String>(
    context,
    Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(title, style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 12),
      TextField(controller: ctrl, autofocus: true, minLines: 3, maxLines: 8, decoration: InputDecoration(hintText: hint)),
      const SizedBox(height: 12),
      FilledButton(onPressed: () => Navigator.of(context).pop(ctrl.text.trim()), child: const Text('Save')),
    ]),
  );
}
