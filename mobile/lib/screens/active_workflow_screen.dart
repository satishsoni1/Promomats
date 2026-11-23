import 'package:flutter/material.dart';

import '../api.dart';
import '../navigation.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/work_task_card.dart';

/// The web's Active Workflow: Needs my action, My jobs, the Design Team and
/// Content Agency queues, and the Brand Manager views.
class ActiveWorkflowScreen extends StatefulWidget {
  const ActiveWorkflowScreen({super.key, this.onChanged});
  final VoidCallback? onChanged;

  @override
  State<ActiveWorkflowScreen> createState() => ActiveWorkflowScreenState();
}

class ActiveWorkflowScreenState extends State<ActiveWorkflowScreen> {
  Json? _data;
  String? _error;
  int? _bmId;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() => _error = null);
    try {
      final data = await ApiClient.instance.tasks(brandManagerId: _bmId);
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
    widget.onChanged?.call();
  }

  Future<void> _open(int id) async {
    await openJob(context, id);
    reload();
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    if (data == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Active Workflow')),
        body: _error != null ? ErrorView(message: _error!, onRetry: reload) : const Center(child: CircularProgressIndicator()),
      );
    }

    final counts = (data['counts'] as Json?) ?? {};
    final tabs = <(String, Widget)>[
      ('Needs my action (${counts['action'] ?? 0})', _actionTab(data)),
      ('My jobs (${counts['mine'] ?? 0})', _jobList(data['my_jobs'] as List, 'You have no jobs in flight.')),
      if (data['show_design_queue'] == true) ('Design Team (${counts['design'] ?? 0})', _queue(data['design_queue'] as List, 'The Design Team queue is empty.')),
      if (data['show_agency_queue'] == true) ('Content Agency (${counts['agency'] ?? 0})', _queue(data['agency_queue'] as List, 'The Content Agency queue is empty.')),
      if ((data['brand_managers'] as List).isNotEmpty) ('Brand Managers', _bmTab(data)),
    ];

    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Active Workflow'),
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            indicatorColor: accentOrange,
            tabs: [for (final t in tabs) Tab(text: t.$1)],
          ),
        ),
        body: TabBarView(children: [for (final t in tabs) RefreshIndicator(onRefresh: reload, child: t.$2)]),
      ),
    );
  }

  Widget _actionTab(Json data) {
    final approvals = (data['approvals'] as List).cast<Json>();
    final revisions = (data['revisions'] as List).cast<Json>();
    final work = (data['work'] as List).cast<Json>();
    if (approvals.isEmpty && revisions.isEmpty && work.isEmpty) {
      return const EmptyView('Nothing is waiting on you right now.', icon: Icons.task_alt);
    }

    return ListView(padding: const EdgeInsets.fromLTRB(12, 8, 12, 96), children: [
      if (approvals.isNotEmpty) ...[
        SectionTitle('Review / approve (${approvals.length})'),
        for (final t in approvals) _approvalTile(t),
      ],
      if (revisions.isNotEmpty) ...[
        SectionTitle('Your jobs waiting on you (${revisions.length})'),
        for (final d in revisions)
          Card(
            color: Colors.amber.shade50,
            child: ListTile(
              onTap: () => _open(d['id'] as int),
              title: Text('${d['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text('${d['reference_no']} · ${d['reason']}'),
              trailing: StatusChip(status: d['status'] as String?, label: d['status'] == 'draft_aligned' ? 'Assign creative' : 'Revise'),
            ),
          ),
      ],
      if (work.isNotEmpty) ...[
        SectionTitle('Creative / rework (${work.length})'),
        for (final w in work) WorkTaskCard(task: w, onChanged: reload),
      ],
    ]);
  }

  Widget _approvalTile(Json task) {
    final doc = task['document'] as Json;
    final overdue = task['is_overdue'] == true;
    final label = task['is_offline'] == true ? 'Record sign-off' : (task['is_draft'] == true ? 'Align draft' : 'Review');
    return Card(
      color: overdue ? Colors.red.shade50 : null,
      child: ListTile(
        onTap: () => _open(doc['id'] as int),
        title: Text('${doc['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text([doc['reference_no'], task['stage'], if (doc['collateral'] != null) doc['collateral']].join(' · ')),
          Text('Brand Manager ${doc['owner']}${task['reassigned'] == true ? ' · reassigned to you' : ''}'),
          DueChip(iso: task['due_at'] as String?, overdue: overdue, dueSoon: task['is_due_soon'] == true),
        ]),
        trailing: FilledButton.tonal(onPressed: () => _open(doc['id'] as int), child: Text(label)),
        isThreeLine: true,
      ),
    );
  }

  Widget _jobList(List jobs, String empty) {
    if (jobs.isEmpty) return EmptyView(empty);
    return ListView(padding: const EdgeInsets.fromLTRB(12, 8, 12, 96), children: [
      for (final j in jobs.cast<Json>())
        Card(
          child: ListTile(
            onTap: () => _open(j['id'] as int),
            title: Text('${j['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${j['reference_no']}${j['collateral'] != null ? ' · ${j['collateral']}' : ''}'),
              if (j['with'] != null) Text('With: ${j['with']}', style: const TextStyle(color: Colors.black87)),
              if (j['next_due'] != null) DueChip(iso: j['next_due'] as String?),
            ]),
            trailing: StatusChip(status: j['status'] as String?, label: '${j['status_label']}'),
            isThreeLine: true,
          ),
        ),
    ]);
  }

  Widget _queue(List tasks, String empty) {
    if (tasks.isEmpty) return EmptyView(empty);
    return ListView(padding: const EdgeInsets.fromLTRB(12, 8, 12, 96), children: [
      for (final t in tasks.cast<Json>()) WorkTaskCard(task: t, onChanged: reload),
    ]);
  }

  Widget _bmTab(Json data) {
    final bms = (data['brand_managers'] as List).cast<Json>();
    final selected = data['selected_bm'] as Json?;
    return Column(children: [
      if (bms.length > 1)
        SizedBox(
          height: 52,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), children: [
            for (final bm in bms)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(
                  label: Text('${bm['name']}'),
                  selected: selected?['id'] == bm['id'],
                  onSelected: (_) {
                    _bmId = bm['id'] as int;
                    reload();
                  },
                ),
              ),
          ]),
        ),
      if (selected != null)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
          child: Align(alignment: Alignment.centerLeft, child: Text('Active workflow for ${selected['name']}', style: const TextStyle(fontWeight: FontWeight.w600))),
        ),
      Expanded(child: _jobList(data['bm_jobs'] as List, '${selected?['name'] ?? 'This Brand Manager'} has no jobs in flight.')),
    ]);
  }
}
