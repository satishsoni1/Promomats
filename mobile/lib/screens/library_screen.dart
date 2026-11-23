import 'package:flutter/material.dart';

import '../api.dart';
import '../navigation.dart';
import '../widgets/common.dart';

/// Library -> Jobs: every job the person can see, In progress / Approved, with search.
class LibraryScreen extends StatefulWidget {
  const LibraryScreen({super.key});

  @override
  State<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends State<LibraryScreen> {
  final _search = TextEditingController();
  String _stage = 'all';
  final List<Json> _jobs = [];
  Json _counts = {};
  int? _nextPage = 1;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    _jobs.clear();
    _nextPage = 1;
    await _more();
  }

  Future<void> _more() async {
    if (_loading || _nextPage == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await ApiClient.instance.library(stage: _stage, query: _search.text.trim(), page: _nextPage!);
      if (!mounted) return;
      setState(() {
        _jobs.addAll((res['jobs'] as List).cast<Json>());
        _counts = (res['counts'] as Json?) ?? {};
        _nextPage = res['next_page'] as int?;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Library')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
          child: TextField(
            controller: _search,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _reload(),
            decoration: InputDecoration(
              hintText: 'Search title or reference no…',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: IconButton(icon: const Icon(Icons.arrow_forward), onPressed: _reload),
            ),
          ),
        ),
        SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), children: [
            for (final (key, label) in const [('all', 'All'), ('progress', 'In progress'), ('approved', 'Approved')])
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(
                  label: Text('$label${_counts[key] != null ? ' (${_counts[key]})' : ''}'),
                  selected: _stage == key,
                  onSelected: (_) {
                    _stage = key;
                    _reload();
                  },
                ),
              ),
          ]),
        ),
        Expanded(
          child: _error != null && _jobs.isEmpty
              ? ErrorView(message: _error!, onRetry: _reload)
              : RefreshIndicator(
                  onRefresh: _reload,
                  child: _jobs.isEmpty && !_loading
                      ? const EmptyView('No jobs found.', icon: Icons.menu_book_outlined)
                      : NotificationListener<ScrollNotification>(
                          onNotification: (n) {
                            if (n.metrics.pixels > n.metrics.maxScrollExtent - 300) _more();
                            return false;
                          },
                          child: ListView.builder(
                            padding: const EdgeInsets.fromLTRB(12, 4, 12, 24),
                            itemCount: _jobs.length + (_loading ? 1 : 0),
                            itemBuilder: (context, i) {
                              if (i >= _jobs.length) return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
                              final j = _jobs[i];
                              return Card(
                                child: ListTile(
                                  onTap: () async {
                                    await openJob(context, j['id'] as int);
                                    _reload();
                                  },
                                  title: Text('${j['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                                  subtitle: Text([j['reference_no'], j['collateral'], j['brand'], 'Brand Manager ${j['owner']}', fmtDate(j['updated_at'] as String?, pattern: 'd MMM yyyy')]
                                      .where((e) => e != null && '$e'.isNotEmpty)
                                      .join(' · ')),
                                  trailing: StatusChip(status: j['status'] as String?, label: '${j['status_label']}'),
                                ),
                              );
                            },
                          ),
                        ),
                ),
        ),
      ]),
    );
  }
}
