import 'package:flutter/material.dart';

import '../api.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// "New job" - the web upload form: collateral classification, workflow (suggested
/// from Brand + Type), the stakeholder and due time for each stage, dates, a
/// placeholder with who prepares the creative, the file and reference documents.
/// Pops the new job's id.
class CreateJobScreen extends StatefulWidget {
  const CreateJobScreen({super.key});

  @override
  State<CreateJobScreen> createState() => _CreateJobScreenState();
}

class _CreateJobScreenState extends State<CreateJobScreen> {
  Json? _opts;
  String? _error;
  bool _saving = false;

  final _title = TextEditingController();
  final _description = TextEditingController();
  final _category = TextEditingController();
  final _products = TextEditingController();
  final _countries = TextEditingController();
  final _brief = TextEditingController();

  int? _brandId;
  String? _channel;
  int? _typeId;
  String? _audience;
  int? _projectId;
  int? _workflowId;
  bool _suggested = false;
  DateTime? _due;
  DateTime? _start;
  DateTime? _expiry;
  bool _placeholder = false;
  String _creativeTeam = 'later';
  List<UploadFile> _file = [];
  List<UploadFile> _references = [];

  /// stage id -> picked user ids / extra people added / due hours
  final Map<int, Set<int>> _picks = {};
  final Map<int, List<Json>> _extra = {};
  final Map<int, int> _dueHours = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final o = await ApiClient.instance.createOptions();
      if (mounted) setState(() => _opts = o);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  List<Json> _list(String key) => ((_opts?[key] as List?) ?? []).cast<Json>();

  List<Json> get _stages {
    final all = _opts?['stages'];
    if (_workflowId == null || all is! Map) return [];
    return ((all['$_workflowId'] as List?) ?? []).cast<Json>();
  }

  /// Same lookup as the web form / WorkflowResolver: the most specific active rule
  /// matching Brand + Type, by priority.
  void _suggestWorkflow() {
    if (_brandId == null && _typeId == null) return;
    final matches = _list('rules').where((r) =>
        (r['brand_id'] == null || r['brand_id'] == _brandId) && (r['document_type_id'] == null || r['document_type_id'] == _typeId)).toList();
    if (matches.isEmpty) return;
    double score(Json r) => (r['priority'] as num) - ((r['brand_id'] != null ? 1 : 0) + (r['document_type_id'] != null ? 1 : 0)) * 0.1;
    matches.sort((a, b) => score(a).compareTo(score(b)));
    _setWorkflow(matches.first['workflow_template_id'] as int, suggested: true);
  }

  void _setWorkflow(int? id, {bool suggested = false}) {
    _workflowId = id;
    _suggested = suggested;
    _picks.clear();
    _extra.clear();
    _dueHours.clear();
    for (final s in _stages) {
      final cands = (s['candidates'] as List).cast<Json>();
      // A stage with exactly one possible person is ticked for you, as on the web.
      if (cands.length == 1) _picks[s['stage_id'] as int] = {cands.first['id'] as int};
      _dueHours[s['stage_id'] as int] = (s['due_hours'] as num?)?.toInt() ?? ((_opts?['default_due_hours'] as num?)?.toInt() ?? 48);
    }
  }

  String _ymd(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _save() async {
    if (_title.text.trim().isEmpty) return snack(context, 'Give the job a title.');
    if (!_placeholder && _file.isEmpty) return snack(context, 'Choose the file, or tick Placeholder.');

    setState(() => _saving = true);
    final fields = <String, dynamic>{
      'title': _title.text.trim(),
      'description': _description.text.trim(),
      'category': _category.text.trim(),
      'products': _products.text.trim(),
      'countries': _countries.text.trim(),
      'brand_id': _brandId,
      'channel': _channel,
      'document_type_id': _typeId,
      'target_audience': _audience,
      'project_id': _projectId,
      'workflow_template_id': _workflowId,
      if (_due != null) 'due_date': _ymd(_due!),
      if (_start != null) 'start_date': _ymd(_start!),
      if (_expiry != null) 'expiry_date': _ymd(_expiry!),
      if (_placeholder) ...{'is_placeholder': true, 'creative_team': _creativeTeam, 'design_instructions': _brief.text.trim()},
      'stage_approvers': {for (final e in _picks.entries) if (e.value.isNotEmpty) '${e.key}': e.value.toList()},
      'stage_due_hours': {for (final e in _dueHours.entries) '${e.key}': e.value},
    };
    try {
      final res = await ApiClient.instance.createJob(fields, file: _placeholder ? null : _file.first, references: _references);
      if (!mounted) return;
      snack(context, '${res['message'] ?? 'Job created.'}');
      Navigator.of(context).pop(res['document_id'] as int?);
    } on ApiException catch (e) {
      if (mounted) snack(context, e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New job')),
      body: _opts == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 100), children: _form()),
      bottomNavigationBar: _opts == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton(
                  onPressed: _saving ? null : _save,
                  style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
                  child: _saving ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('Upload'),
                ),
              ),
            ),
    );
  }

  List<Widget> _form() {
    const gap = SizedBox(height: 12);
    final types = _list('document_types').where((t) => _channel == null || t['channel'] == _channel).toList();

    return [
      TextField(controller: _title, decoration: const InputDecoration(labelText: 'Title *')),
      gap,
      TextField(controller: _description, minLines: 2, maxLines: 4, decoration: const InputDecoration(labelText: 'Description')),
      gap,
      _dropdown<int>('Brand', _brandId, [for (final b in _list('brands')) (b['id'] as int, '${b['name']}')], (v) {
        setState(() {
          _brandId = v;
          _suggestWorkflow();
        });
      }),
      gap,
      Row(children: [
        Expanded(
          child: _dropdown<String>('Collateral classification', _channel, const [('print', 'Print'), ('digital', 'Digital')], (v) {
            setState(() {
              _channel = v;
              final current = _list('document_types').where((t) => t['id'] == _typeId).firstOrNull;
              if (current != null && v != null && current['channel'] != v) _typeId = null;
            });
          }),
        ),
      ]),
      gap,
      _dropdown<int>('Type of collateral', _typeId, [for (final t in types) (t['id'] as int, '${t['name']}')], (v) {
        setState(() {
          _typeId = v;
          _suggestWorkflow();
        });
      }),
      gap,
      TextField(controller: _category, decoration: const InputDecoration(labelText: 'Category', hintText: 'e.g. Promotional Material')),
      gap,
      TextField(controller: _products, decoration: const InputDecoration(labelText: 'Product(s)', hintText: 'Comma-separated')),
      gap,
      TextField(controller: _countries, decoration: const InputDecoration(labelText: 'Country / Countries', hintText: 'e.g. IN, Global')),
      gap,
      _dropdown<String>('Target audience', _audience, [for (final a in _list('target_audiences')) ('${a['key']}', '${a['label']}')], (v) => setState(() => _audience = v)),
      gap,
      _dropdown<int>('Approval workflow', _workflowId, [for (final t in _list('templates')) (t['id'] as int, '${t['name']}')], (v) => setState(() => _setWorkflow(v)),
          emptyLabel: 'Auto-assign from Brand / Type'),
      if (_suggested) const Padding(padding: EdgeInsets.only(top: 4), child: Text('Suggested from Brand / Type - change if needed.', style: TextStyle(color: brandTeal, fontSize: 12))),
      gap,
      _dropdown<int>('Project (optional)', _projectId, [for (final p in _list('projects')) (p['id'] as int, '${p['name']}')], (v) => setState(() => _projectId = v)),
      if (_stages.isNotEmpty) ..._stakeholders(),
      gap,
      Row(children: [
        Expanded(child: _dateField('Task due date', _due, (d) => setState(() => _due = d))),
        const SizedBox(width: 8),
        Expanded(child: _dateField('Start', _start, (d) => setState(() => _start = d))),
        const SizedBox(width: 8),
        Expanded(child: _dateField('Expiry', _expiry, (d) => setState(() => _expiry = d))),
      ]),
      gap,
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        value: _placeholder,
        onChanged: (v) => setState(() => _placeholder = v ?? false),
        title: const Text('Placeholder - no file yet'),
        subtitle: const Text('Upload the Word draft later for Content Manager alignment, or hand the brief to the Content Agency / Internal Design Team.'),
      ),
      if (_placeholder) ...[
        _dropdown<String>('Who prepares the creative?', _creativeTeam, [for (final t in _list('creative_teams')) ('${t['key']}', '${t['label']}')], (v) => setState(() => _creativeTeam = v ?? 'later'), allowEmpty: false),
        gap,
        TextField(controller: _brief, minLines: 3, maxLines: 6, decoration: const InputDecoration(labelText: 'Brief', hintText: 'Sizes, key message, claims to use…')),
      ] else
        FileField(label: 'Choose the file *', hint: 'Any file type, up to 500 MB', files: _file, onChanged: (f) => setState(() => _file = f)),
      gap,
      FileField(
        label: 'Reference documents (optional)',
        hint: 'Studies, sources or the print input brief - attached for every reviewer',
        files: _references,
        multiple: true,
        onChanged: (f) => setState(() => _references = f),
      ),
    ];
  }

  List<Widget> _stakeholders() {
    final allUsers = _list('all_users');
    return [
      const SectionTitle('Stakeholders & due dates for each stage'),
      Text(
        "Tick the person (or people) for each stage. You own this job, so you can't be picked as a reviewer.",
        style: Theme.of(context).textTheme.bodySmall,
      ),
      for (final s in _stages)
        Card(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(
                  child: Text.rich(TextSpan(children: [
                    TextSpan(text: '${s['sequence_no']}. ${s['name']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                    if (s['parallel_group'] != null) const TextSpan(text: '  PARALLEL', style: TextStyle(fontSize: 10, color: brandTeal)),
                    if (s['is_draft'] == true) TextSpan(text: '  DRAFT', style: TextStyle(fontSize: 10, color: Colors.purple.shade700)),
                  ])),
                ),
                SizedBox(
                  width: 92,
                  child: TextFormField(
                    initialValue: '${_dueHours[s['stage_id']] ?? 48}',
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Due (h)'),
                    onChanged: (v) => _dueHours[s['stage_id'] as int] = int.tryParse(v) ?? 48,
                  ),
                ),
              ]),
              for (final c in [...(s['candidates'] as List).cast<Json>(), ...(_extra[s['stage_id']] ?? [])])
                CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  value: _picks[s['stage_id']]?.contains(c['id']) ?? false,
                  title: Text('${c['name']}'),
                  onChanged: (v) => setState(() {
                    final set = _picks.putIfAbsent(s['stage_id'] as int, () => {});
                    v == true ? set.add(c['id'] as int) : set.remove(c['id']);
                  }),
                ),
              TextButton.icon(
                icon: const Icon(Icons.person_add_alt, size: 18),
                label: const Text('Add someone else'),
                onPressed: () async {
                  final picked = await showSheet<Json>(
                    context,
                    Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text('Add to ${s['name']}', style: Theme.of(context).textTheme.titleLarge),
                      for (final u in allUsers) ListTile(title: Text('${u['name']}'), onTap: () => Navigator.of(context).pop(u)),
                    ]),
                  );
                  if (picked == null) return;
                  setState(() {
                    final id = s['stage_id'] as int;
                    final known = [...(s['candidates'] as List).cast<Json>(), ...(_extra[id] ?? [])].any((c) => c['id'] == picked['id']);
                    if (!known) _extra.putIfAbsent(id, () => []).add(picked);
                    _picks.putIfAbsent(id, () => {}).add(picked['id'] as int);
                  });
                },
              ),
              Text(
                s['parallel_group'] != null
                    ? 'MLR stakeholders run in parallel. Only the tracks you tick someone for are sent - tracks left unticked are skipped (leave every MLR track unticked to send to all reviewers).'
                    : 'Leave everyone unticked to send it to all of them (first to act decides).',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ]),
          ),
        ),
    ];
  }

  Widget _dropdown<T>(String label, T? value, List<(T, String)> items, ValueChanged<T?> onChanged, {String emptyLabel = '— Not specified —', bool allowEmpty = true}) {
    return DropdownButtonFormField<T?>(
      initialValue: value,
      key: ValueKey('$label-$value-${items.length}'),
      isExpanded: true,
      decoration: InputDecoration(labelText: label),
      items: [
        if (allowEmpty) DropdownMenuItem<T?>(value: null, child: Text(emptyLabel)),
        for (final (v, l) in items) DropdownMenuItem<T?>(value: v, child: Text(l, overflow: TextOverflow.ellipsis)),
      ],
      onChanged: onChanged,
    );
  }

  Widget _dateField(String label, DateTime? value, ValueChanged<DateTime?> onChanged) {
    return InkWell(
      onTap: () async {
        final d = await showDatePicker(context: context, initialDate: value ?? DateTime.now(), firstDate: DateTime(2020), lastDate: DateTime(2035));
        onChanged(d ?? value);
      },
      child: InputDecorator(
        decoration: InputDecoration(labelText: label, suffixIcon: value == null ? null : IconButton(icon: const Icon(Icons.clear, size: 16), onPressed: () => onChanged(null))),
        child: Text(value == null ? '—' : _ymd(value), style: const TextStyle(fontSize: 13)),
      ),
    );
  }
}
