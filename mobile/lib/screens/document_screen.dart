import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/job_sheets.dart';
import '../widgets/work_task_card.dart';
import 'viewer_screen.dart';

/// The job page - everything the web's document page offers, laid out for a phone:
/// where it is in the workflow, the creative, what's needed from me (decision,
/// revise & resubmit, draft aligned, design / agency work), stakeholders, versions,
/// references, review history, comments and lifecycle.
class DocumentScreen extends StatefulWidget {
  const DocumentScreen({super.key, required this.documentId});
  final int documentId;

  @override
  State<DocumentScreen> createState() => _DocumentScreenState();
}

class _DocumentScreenState extends State<DocumentScreen> {
  Json? _data;
  String? _error;

  int get _id => widget.documentId;
  ApiClient get _api => ApiClient.instance;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await _api.document(_id);
      if (mounted) {
        setState(() {
          _data = data;
          _error = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _sheet(Widget sheet) async {
    final done = await showSheet<bool>(context, sheet);
    if (done == true) _load();
  }

  Future<void> _act(Future<Json> Function() action) async {
    if (await runAction(context, action) != null) _load();
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final doc = data?['document'] as Json?;
    return Scaffold(
      appBar: AppBar(
        title: Text(doc?['reference_no'] ?? 'Job'),
        actions: [
          if (doc != null)
            IconButton(
              tooltip: 'Open on the web',
              icon: const Icon(Icons.public),
              onPressed: () => launchUrl(Uri.parse('${doc['web_url']}'), mode: LaunchMode.externalApplication),
            ),
        ],
      ),
      body: data == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: _body(data)),
    );
  }

  Widget _body(Json data) {
    final doc = data['document'] as Json;
    final perms = data['permissions'] as Json;
    final banner = data['banner'] as Json?;
    final text = Theme.of(context).textTheme;

    return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 40), children: [
      Text('${doc['title']}', style: text.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
      const SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
        StatusChip(status: doc['status'] as String?, label: '${doc['status_label']}'),
        if (doc['collateral'] != null) Text('${doc['collateral']}', style: text.bodySmall),
        for (final p in [...(doc['products'] as List? ?? []), ...(doc['countries'] as List? ?? [])])
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(4)),
            child: Text('$p', style: const TextStyle(fontSize: 11)),
          ),
      ]),
      Padding(padding: const EdgeInsets.only(top: 4, bottom: 12), child: Text('Brand Manager: ${doc['owner']}', style: text.bodySmall)),
      if (banner != null) NoticeBanner(tone: '${banner['tone']}', text: '${banner['text']}'),
      if (perms['comments_draft'] == true)
        const NoticeBanner(tone: 'draft', text: 'Your comments are drafts - only you can see them until you submit your review.'),
      _stepper(data),
      const SizedBox(height: 12),
      _fileCard(data),
      const SizedBox(height: 12),
      ..._actionPanels(data),
      _workflowStatus(data),
      _details(doc),
      _versions(data),
      _references(data),
      _history(data),
      _comments(data),
      _lifecycle(data),
    ]);
  }

  // ------------------------------------------------------------ stepper

  Widget _stepper(Json data) {
    final stages = (data['stages'] as List).cast<Json>();
    if (stages.isEmpty) return const SizedBox.shrink();

    // Parallel stages (MLR) are drawn together in one box, like the web.
    final groups = <List<Json>>[];
    for (final s in stages) {
      final g = s['parallel_group'];
      if (g != null && groups.isNotEmpty && groups.last.first['parallel_group'] == g) {
        groups.last.add(s);
      } else {
        groups.add([s]);
      }
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
        child: Column(children: [
          for (final g in groups)
            g.length == 1
                ? _stageRow(g.first)
                : Container(
                    margin: const EdgeInsets.symmetric(vertical: 4),
                    padding: const EdgeInsets.fromLTRB(8, 4, 8, 4),
                    decoration: BoxDecoration(border: Border.all(color: brandTeal.withValues(alpha: 0.3)), borderRadius: BorderRadius.circular(8)),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      const Text('IN PARALLEL', style: TextStyle(fontSize: 10, color: brandTeal, letterSpacing: 1)),
                      for (final s in g) _stageRow(s),
                    ]),
                  ),
        ]),
      ),
    );
  }

  Widget _stageRow(Json s) {
    final state = s['state'] as String;
    final (icon, color) = switch (state) {
      'done' => (Icons.check_circle, brandTeal),
      'current' => (Icons.radio_button_checked, accentOrange),
      'returned' => (Icons.undo, Colors.amber.shade700),
      'rejected' => (Icons.cancel, Colors.red),
      'skipped' => (Icons.remove_circle_outline, Colors.grey.shade400),
      _ => (Icons.radio_button_unchecked, Colors.grey.shade400),
    };
    final withWhom = (s['with'] as List? ?? []).join(', ');
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(children: [
        Icon(icon, color: color, size: 22),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              '${s['name']}',
              style: TextStyle(
                fontWeight: state == 'current' || state == 'returned' ? FontWeight.w600 : null,
                color: state == 'skipped' || state == 'upcoming' ? Colors.grey.shade500 : null,
                decoration: state == 'skipped' ? TextDecoration.lineThrough : null,
              ),
            ),
            if (state == 'current' && withWhom.isNotEmpty) Text('With $withWhom', style: Theme.of(context).textTheme.bodySmall),
            if (state == 'returned') Text('Back with the Brand Manager', style: TextStyle(fontSize: 12, color: Colors.amber.shade900)),
          ]),
        ),
        if (state == 'skipped') Text('SKIPPED', style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
        if (s['stage_type'] == 'offline') const Tooltip(message: 'Offline sign-off', child: Icon(Icons.cloud_off, size: 16, color: Colors.grey)),
      ]),
    );
  }

  // ------------------------------------------------------------ file

  Widget _fileCard(Json data) {
    final file = data['file'] as Json?;
    if (file == null) {
      return const Card(child: ListTile(leading: Icon(Icons.hourglass_empty), title: Text('No file yet'), subtitle: Text('Waiting for the draft or the creative.')));
    }
    final kind = file['kind'] as String?;
    final comments = (data['comments'] as List).cast<Json>();
    final perms = data['permissions'] as Json;
    return Card(
      color: brandTeal.withValues(alpha: 0.06),
      child: ListTile(
        leading: Icon(switch (kind) { 'pdf' => Icons.picture_as_pdf, 'image' => Icons.image_outlined, 'word' => Icons.description_outlined, 'video' => Icons.movie_outlined, _ => Icons.insert_drive_file_outlined }, color: brandTeal, size: 32),
        title: Text('${file['name']}', maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text('v${file['version_no']} · ${file['size']}${kind == 'word' ? '\nTrack changes: open on the web' : ''}'),
        trailing: const Icon(Icons.open_in_full),
        onTap: () async {
          final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(
            builder: (_) => ViewerScreen(
              documentId: _id,
              file: file,
              pins: comments.where((c) => c['page'] != null).toList(),
              canComment: perms['comments_locked'] != true,
              commentsAreDraft: perms['comments_draft'] == true,
              webUrl: '${(data['document'] as Json)['web_url']}',
            ),
          ));
          if (changed == true) _load();
        },
      ),
    );
  }

  // ------------------------------------------------------------ what I need to do

  List<Widget> _actionPanels(Json data) {
    final panels = <Widget>[];
    final perms = data['permissions'] as Json;
    final myTask = data['my_task'] as Json?;
    final start = data['start_panel'] as Json?;
    final owner = data['owner_panel'] as Json?;
    final work = data['work_task'] as Json?;
    final members = (data['team_members'] as Json?) ?? {};

    if (myTask != null) {
      if (myTask['can_decide'] == true) {
        final offline = myTask['is_offline'] == true;
        final draft = myTask['is_draft'] == true;
        panels.add(PanelCard(
          title: offline ? 'Record the offline sign-off' : (draft ? 'Align the draft' : 'Your decision needed'),
          subtitle: '${myTask['stage']}${myTask['due_at'] != null ? ' · due ${fmtShort(myTask['due_at'] as String?)}' : ''}',
          color: accentOrange,
          children: [
            if (draft) const Text("Check the Brand Manager's draft; send it back with changes until it's aligned."),
            if (offline) const Text('Upload the copy received from the Chairperson and record their outcome.'),
            const SizedBox(height: 8),
            FilledButton.icon(
              onPressed: () => _sheet(DecisionSheet(documentId: _id, task: myTask)),
              icon: const Icon(Icons.how_to_vote_outlined),
              label: Text(offline ? 'Record sign-off' : (draft ? 'Align the draft' : 'Record decision')),
            ),
          ],
        ));
      } else {
        panels.add(NoticeBanner(
          tone: 'muted',
          text: "You're listed at ${myTask['stage']}, but makers can't be checkers - Brand Managers start, upload and assign rather than approving or rejecting.",
        ));
      }
    }

    if (start != null) {
      final firstDraft = start['first_stage_is_draft'] == true;
      panels.add(PanelCard(
        title: firstDraft ? 'Send for draft alignment' : 'Assign to stakeholders',
        subtitle: start['has_file'] != true
            ? 'Upload the Word draft under Versions first, or assign the creative to the Content Agency / Design Team.'
            : (firstDraft
                ? "Sends the draft to the Content Manager. It goes back and forth with you until it's aligned, then comes back to you."
                : 'Starts the workflow with the stakeholders you picked.'),
        children: [
          if (start['has_file'] == true)
            FilledButton(onPressed: () => _act(() => _api.submit(_id)), child: Text(firstDraft ? 'Send to Content Manager' : 'Start workflow')),
          if (start['can_assign_placeholder'] == true) ...[
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: OutlinedButton(onPressed: () => _sheet(AssignTeamSheet(documentId: _id, team: 'agency', members: const [], placeholder: true, instructions: '${data['document']['description'] ?? ''}')), child: const Text('Brief to Agency'))),
              const SizedBox(width: 8),
              Expanded(child: OutlinedButton(onPressed: () => _sheet(AssignTeamSheet(documentId: _id, team: 'design', members: const [], placeholder: true, instructions: '${data['document']['description'] ?? ''}')), child: const Text('Brief to Design'))),
            ]),
          ],
        ],
      ));
    }

    if (owner != null) {
      final aligned = owner['mode'] == 'draft_aligned';
      final last = owner['last_decision'] as Json?;
      final confirm = owner['upload_to_confirm'] as Json?;
      panels.add(PanelCard(
        title: aligned ? 'Draft aligned - commission the creative' : 'Revise & Resubmit',
        color: aligned ? Colors.purple.shade200 : Colors.amber.shade400,
        children: [
          if (last?['comments'] != null && '${last!['comments']}'.isNotEmpty)
            Container(
              padding: const EdgeInsets.all(8),
              margin: const EdgeInsets.only(bottom: 8),
              decoration: BoxDecoration(color: Colors.amber.shade50, borderRadius: BorderRadius.circular(6)),
              child: Text('${last['by']}: ${last['comments']}'),
            ),
          if (work != null)
            Text('With the ${work['team_label']}${work['assigned_to'] != null ? ' - ${work['assigned_to']}' : ' (not yet assigned)'}. '
                '${work['resubmit_on_upload'] == true ? 'Their upload goes straight back into review.' : 'Their upload comes back to you to confirm.'}')
          else ...[
            if (confirm != null)
              Container(
                padding: const EdgeInsets.all(8),
                margin: const EdgeInsets.only(bottom: 8),
                decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(6)),
                child: Text('v${confirm['version_no']} uploaded by ${confirm['by']} - check it under Versions, then ${aligned ? 'start the review' : 'resubmit'}.',
                    style: TextStyle(color: Colors.green.shade900)),
              ),
            FilledButton(
              onPressed: () => _sheet(UploadSheet(
                title: aligned ? 'Start review' : 'Resubmit',
                subtitle: aligned
                    ? 'Attach the creative, or leave it empty to send the current version on to ${owner['resume_stage'] ?? 'the next stage'}.'
                    : 'Upload the revision (or leave empty if it is already under Versions). It goes back to ${owner['resubmit_to'] ?? 'review'}.',
                fileRequired: false,
                notesLabel: aligned ? 'Notes (optional)' : 'What changed?',
                buttonLabel: aligned ? 'Start review' : 'Resubmit to ${owner['resubmit_to'] ?? 'review'}',
                onSubmit: (file, notes) => _api.submit(_id, file: file, notes: notes),
              )),
              child: Text(aligned ? 'Start review${owner['resume_stage'] != null ? ' - ${owner['resume_stage']}' : ''}' : 'Upload it myself & resubmit'),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () => _sheet(AssignTeamSheet(documentId: _id, team: 'agency', members: ((members['agency'] as List?) ?? []).cast<Json>(), instructions: aligned ? '' : last?['comments'] as String?)),
                  child: const Text('Content Agency'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton(
                  onPressed: () => _sheet(AssignTeamSheet(
                    documentId: _id,
                    team: 'design',
                    members: ((members['design'] as List?) ?? []).cast<Json>(),
                    instructions: aligned ? '' : last?['comments'] as String?,
                    allowSkipConfirmation: owner['can_skip_confirmation'] == true,
                  )),
                  child: const Text('Design Team'),
                ),
              ),
            ]),
          ],
        ],
      ));
    }

    // Team work on this job (agency / designers see it here too).
    if (work != null && (work['can_upload'] == true || work['can_take'] == true || (work['can_withdraw'] == true && owner != null))) {
      panels.add(Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: WorkTaskCard(task: work, onChanged: _load, showDocument: false),
      ));
    }

    if (perms['can_upload_version'] == true && owner == null && (data['document'] as Json)['legal_hold'] != true && myTask?['is_offline'] != true) {
      panels.add(Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          icon: const Icon(Icons.upload_file),
          label: const Text('Upload new version'),
          onPressed: () => _sheet(UploadSheet(
            title: 'Upload new version',
            subtitle: (data['document'] as Json)['status'] == 'in_review' ? 'The review carries on with this version - current reviewers see it straight away.' : null,
            buttonLabel: 'Upload version',
            onSubmit: (file, notes) => _api.uploadVersion(_id, file!, notes: notes),
          )),
        ),
      ));
    }
    return panels;
  }

  // ------------------------------------------------------------ workflow status

  Widget _workflowStatus(Json data) {
    final pending = (data['pending'] as List).cast<Json>();
    final perms = data['permissions'] as Json;
    final options = ((data['reassign_options'] as List?) ?? []).cast<Json>();
    if (pending.isEmpty) return const SizedBox.shrink();

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle(data['in_parallel'] == true ? 'Workflow status - in parallel review' : 'Workflow status'),
      for (final p in pending)
        Card(
          child: ListTile(
            leading: CircleAvatar(backgroundColor: brandTeal.withValues(alpha: 0.12), child: Text('${p['user']}'.substring(0, 1), style: const TextStyle(color: brandTeal))),
            title: Text('${p['user']}'),
            subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${p['stage']}'),
              DueChip(iso: p['due_at'] as String?, overdue: p['is_overdue'] == true, dueSoon: p['is_due_soon'] == true),
              if (p['note'] != null) Text('${p['note']}', style: Theme.of(context).textTheme.bodySmall),
            ]),
            trailing: perms['can_manage_tasks'] == true
                ? TextButton(onPressed: () => _sheet(ReassignSheet(documentId: _id, pending: p, options: options)), child: const Text('Reassign / due'))
                : null,
          ),
        ),
    ]);
  }

  // ------------------------------------------------------------ details & files

  Widget _details(Json doc) {
    final rows = <(String, String?)>[
      ('Brand', doc['brand'] as String?),
      ('Collateral', [doc['channel'], doc['type']].where((e) => e != null).join(' · ')),
      ('Category', doc['category'] as String?),
      ('Target audience', doc['target_audience'] as String?),
      ('Project', doc['project'] as String?),
      ('Task due date', doc['due_date'] as String?),
      ('Start / expiry', doc['start_date'] == null && doc['expiry_date'] == null ? null : '${doc['start_date'] ?? '—'} → ${doc['expiry_date'] ?? '—'}'),
      ('Workflow', doc['workflow'] as String?),
    ].where((r) => r.$2 != null && r.$2!.isNotEmpty).toList();

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SectionTitle('Details'),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final (k, v) in rows)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SizedBox(width: 120, child: Text(k, style: Theme.of(context).textTheme.bodySmall)),
                  Expanded(child: Text(v!)),
                ]),
              ),
            if ((doc['description'] ?? '').toString().isNotEmpty) ...[
              const Divider(),
              Text('${doc['description']}'),
            ],
          ]),
        ),
      ),
    ]);
  }

  Widget _versions(Json data) {
    final versions = (data['versions'] as List).cast<Json>();
    if (versions.isEmpty) return const SizedBox.shrink();
    final comments = (data['comments'] as List).cast<Json>();
    final perms = data['permissions'] as Json;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle('Versions (${versions.length})'),
      for (final v in versions)
        Card(
          child: ListTile(
            dense: true,
            title: Text('v${v['version_no']}  ${v['name']}', maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: Text([v['uploaded_by'], v['size'], fmtShort(v['created_at'] as String?), if ((v['notes'] ?? '').toString().isNotEmpty) v['notes']].join(' · ')),
            trailing: v['is_current'] == true ? const StatusChip(status: 'approved', label: 'current') : const Icon(Icons.visibility_outlined),
            onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => ViewerScreen(
                documentId: _id,
                file: v,
                pins: v['is_current'] == true ? comments.where((c) => c['page'] != null).toList() : const [],
                canComment: v['is_current'] == true && perms['comments_locked'] != true,
                commentsAreDraft: perms['comments_draft'] == true,
                webUrl: '${(data['document'] as Json)['web_url']}',
              ),
            )),
          ),
        ),
    ]);
  }

  Widget _references(Json data) {
    final refs = (data['references'] as List).cast<Json>();
    final canAdd = (data['permissions'] as Json)['can_add_reference'] == true;
    if (refs.isEmpty && !canAdd) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle(
        'Reference documents (${refs.length})',
        trailing: canAdd
            ? TextButton.icon(
                icon: const Icon(Icons.attach_file, size: 18),
                label: const Text('Attach'),
                onPressed: () => _sheet(UploadSheet(
                  title: 'Attach reference file',
                  notesLabel: 'Title (optional - defaults to the file name)',
                  buttonLabel: 'Attach',
                  onSubmit: (file, title) => _api.addReference(_id, file!, title: title.isEmpty ? null : title),
                )),
              )
            : null,
      ),
      if (refs.isEmpty) Text('No reference files attached yet.', style: Theme.of(context).textTheme.bodySmall),
      for (final r in refs)
        Card(
          child: ListTile(
            dense: true,
            leading: const Icon(Icons.library_books_outlined, color: brandTeal),
            title: Text('${r['title']}'),
            subtitle: Text('${r['name']} · ${r['size']} · ${r['uploaded_by'] ?? ''}'),
            trailing: const Icon(Icons.open_in_new, size: 18),
            onTap: () => launchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication),
          ),
        ),
    ]);
  }

  Widget _history(Json data) {
    final history = (data['history'] as List).cast<Json>();
    if (history.isEmpty) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle('Review actions (${history.length})'),
      for (final h in history)
        Card(
          child: ListTile(
            dense: true,
            leading: Icon(
              switch (h['decision']) { 'approved' => Icons.check_circle_outline, 'approved_with_changes' => Icons.edit_note, _ => Icons.cancel_outlined },
              color: switch (h['decision']) { 'approved' => Colors.green, 'approved_with_changes' => Colors.amber.shade800, _ => Colors.red },
            ),
            title: Text('${h['decision_label']} - ${h['stage']}'),
            subtitle: Text('${h['by']} · ${fmtDate(h['at'] as String?)}${(h['comments'] ?? '').toString().isNotEmpty ? '\n${h['comments']}' : ''}\nElectronically signed - 21 CFR Part 11'),
          ),
        ),
    ]);
  }

  // ------------------------------------------------------------ comments

  Widget _comments(Json data) {
    final comments = (data['comments'] as List).cast<Json>();
    final perms = data['permissions'] as Json;
    final locked = perms['comments_locked'] == true;
    final draft = perms['comments_draft'] == true;

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle(
        'Comments (${comments.length})',
        trailing: locked
            ? const Text('🔒 Locked', style: TextStyle(fontSize: 12))
            : TextButton.icon(
                icon: const Icon(Icons.add_comment_outlined, size: 18),
                label: Text(draft ? 'Draft comment' : 'Comment'),
                onPressed: () async {
                  final body = await askText(context, title: draft ? 'Draft comment (visible once you submit)' : 'Add a comment');
                  if (body != null && body.isNotEmpty && mounted) _act(() => _api.comment(_id, body));
                },
              ),
      ),
      if (comments.isEmpty) Text('No comments yet.', style: Theme.of(context).textTheme.bodySmall),
      for (final c in comments) _commentCard(c, locked),
    ]);
  }

  Widget _commentCard(Json c, bool locked) {
    final replies = (c['replies'] as List? ?? []).cast<Json>();
    final small = Theme.of(context).textTheme.bodySmall;
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 4, 6),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text('${c['author']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                if (c['draft'] == true) const StatusChip(status: 'draft_aligned', label: 'draft - only you'),
                if (c['page'] != null) Text('p.${c['page']}', style: small),
                if (c['edited'] == true) Text('(edited)', style: small),
              ]),
            ),
            Text(fmtShort(c['created_at'] as String?), style: small),
            PopupMenuButton<String>(
              onSelected: (v) async {
                if (v == 'edit') {
                  final body = await askText(context, title: 'Edit comment', initial: '${c['body']}');
                  if (body != null && body.isNotEmpty && mounted) _act(() => _api.editComment(_id, c['id'] as int, body));
                } else if (v == 'reply') {
                  final body = await askText(context, title: 'Reply to ${c['author']}');
                  if (body != null && body.isNotEmpty && mounted) _act(() => _api.comment(_id, body, parentId: c['id'] as int));
                } else if (v == 'resolve') {
                  _act(() => _api.resolveComment(_id, c['id'] as int, c['resolved'] != true));
                }
              },
              itemBuilder: (_) => [
                if (!locked) const PopupMenuItem(value: 'reply', child: Text('Reply')),
                if (c['can_edit'] == true) const PopupMenuItem(value: 'edit', child: Text('Edit')),
                if (c['can_resolve'] == true) PopupMenuItem(value: 'resolve', child: Text(c['resolved'] == true ? 'Reopen' : 'Resolve')),
              ],
            ),
          ]),
          if (c['selected_text'] != null)
            Text('“${c['selected_text']}”', style: const TextStyle(fontStyle: FontStyle.italic, color: Colors.black54)),
          if (c['replacement_text'] != null)
            Container(
              margin: const EdgeInsets.only(top: 4),
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(6)),
              child: Text('Replace with: ${c['replacement_text']}', style: TextStyle(color: Colors.green.shade900)),
            ),
          Padding(
            padding: const EdgeInsets.only(top: 4, right: 8),
            child: Text('${c['body']}', style: TextStyle(decoration: c['resolved'] == true ? TextDecoration.lineThrough : null)),
          ),
          for (final r in replies)
            Container(
              margin: const EdgeInsets.only(top: 6, right: 8),
              padding: const EdgeInsets.only(left: 10),
              decoration: BoxDecoration(border: Border(left: BorderSide(color: Colors.grey.shade300, width: 2))),
              child: Row(children: [
                Expanded(child: Text('${r['author']}${r['draft'] == true ? ' (draft)' : ''}: ${r['body']}', style: small?.copyWith(color: Colors.black87))),
                if (r['can_edit'] == true)
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.edit, size: 16),
                    onPressed: () async {
                      final body = await askText(context, title: 'Edit reply', initial: '${r['body']}');
                      if (body != null && body.isNotEmpty && mounted) _act(() => _api.editComment(_id, r['id'] as int, body));
                    },
                  ),
              ]),
            ),
        ]),
      ),
    );
  }

  // ------------------------------------------------------------ lifecycle

  Widget _lifecycle(Json data) {
    final actions = ((data['lifecycle'] as List?) ?? []).cast<Json>();
    if (actions.isEmpty) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const SectionTitle('Lifecycle'),
      Wrap(spacing: 8, runSpacing: 4, children: [
        for (final a in actions)
          OutlinedButton(
            onPressed: () async {
              final ok = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: Text('${a['label']}?'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                    FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Confirm')),
                  ],
                ),
              );
              if (ok == true && mounted) _act(() => _api.setStatus(_id, '${a['status']}'));
            },
            child: Text('${a['label']}'),
          ),
      ]),
    ]);
  }
}
