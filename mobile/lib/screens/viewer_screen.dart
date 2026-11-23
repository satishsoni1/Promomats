import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/job_sheets.dart';

/// Full-screen view of the creative, like the web's review viewer: PDFs and
/// artwork show the numbered comment pins, and in comment mode a tap on the page
/// drops a new pinned comment there. Word files open on the web for tracked
/// changes; anything else opens in the phone's own viewer. The URL is a
/// short-lived signed link, so no header is needed. Pops `true` if a comment was added.
class ViewerScreen extends StatefulWidget {
  const ViewerScreen({
    super.key,
    required this.documentId,
    required this.file,
    this.pins = const [],
    this.canComment = false,
    this.commentsAreDraft = false,
    this.webUrl,
  });
  final int documentId;
  final Json file;
  final List<Json> pins;
  final bool canComment;
  final bool commentsAreDraft;
  final String? webUrl;

  @override
  State<ViewerScreen> createState() => _ViewerScreenState();
}

class _ViewerScreenState extends State<ViewerScreen> {
  bool _commentMode = false;
  bool _changed = false;
  late final List<Json> _pins = [...widget.pins];
  Size? _imageSize;

  String get _url => widget.file['url'] as String;
  String? get _kind => widget.file['kind'] as String?;

  @override
  void initState() {
    super.initState();
    if (_kind == 'image') {
      NetworkImage(_url).resolve(const ImageConfiguration()).addListener(ImageStreamListener((info, _) {
        if (mounted) setState(() => _imageSize = Size(info.image.width.toDouble(), info.image.height.toDouble()));
      }));
    }
  }

  /// Tap position on a page -> percentages (what the web stores).
  Future<void> _addPin(int page, Offset local, Size size) async {
    final x = (local.dx / size.width * 100).clamp(0, 100).toDouble();
    final y = (local.dy / size.height * 100).clamp(0, 100).toDouble();
    final body = await askText(context, title: widget.commentsAreDraft ? 'Draft comment on page $page' : 'Comment on page $page');
    if (body == null || body.isEmpty || !mounted) return;
    final res = await runAction(context, () => ApiClient.instance.comment(widget.documentId, body, page: page, x: x, y: y));
    if (res != null && mounted) {
      final c = (res['comment'] as Json?) ?? {};
      setState(() {
        _changed = true;
        _commentMode = false;
        _pins.add({'id': c['id'], 'author': ApiClient.instance.user?['name'] ?? 'You', 'body': body, 'page': page, 'x': x, 'y': y, 'draft': c['draft'] == true});
      });
    }
  }

  void _showPin(Json pin, int number) {
    showSheet<void>(
      context,
      Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          CircleAvatar(radius: 12, backgroundColor: accentOrange, child: Text('$number', style: const TextStyle(fontSize: 11, color: Colors.white))),
          const SizedBox(width: 8),
          Text('${pin['author']}', style: const TextStyle(fontWeight: FontWeight.w600)),
          if (pin['draft'] == true) ...[const SizedBox(width: 6), const StatusChip(status: 'draft_aligned', label: 'draft - only you')],
        ]),
        if (pin['selected_text'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text('“${pin['selected_text']}”', style: const TextStyle(fontStyle: FontStyle.italic))),
        if (pin['replacement_text'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Replace with: ${pin['replacement_text']}', style: TextStyle(color: Colors.green.shade800))),
        Padding(padding: const EdgeInsets.only(top: 8), child: Text('${pin['body']}')),
        for (final r in (pin['replies'] as List? ?? []).cast<Json>()) Padding(padding: const EdgeInsets.only(top: 6, left: 12), child: Text('${r['author']}: ${r['body']}')),
      ]),
    );
  }

  /// Numbered markers for the pins on [page], plus the tap layer in comment mode.
  List<Widget> _overlay(int page, Size size) {
    final onPage = _pins.where((p) => p['page'] == page).toList();
    return [
      if (_commentMode)
        Positioned.fill(
          child: GestureDetector(
            behavior: HitTestBehavior.translucent,
            onTapUp: (d) => _addPin(page, d.localPosition, size),
            child: Container(color: accentOrange.withValues(alpha: 0.05)),
          ),
        ),
      for (final p in onPage)
        Positioned(
          left: ((p['x'] as num?)?.toDouble() ?? 50) / 100 * size.width - 12,
          top: ((p['y'] as num?)?.toDouble() ?? 50) / 100 * size.height - 12,
          child: GestureDetector(
            onTap: () => _showPin(p, _pins.indexOf(p) + 1),
            child: CircleAvatar(
              radius: 12,
              backgroundColor: p['resolved'] == true ? Colors.grey : (p['draft'] == true ? Colors.lightBlue : accentOrange),
              child: Text('${_pins.indexOf(p) + 1}', style: const TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ),
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final annotatable = _kind == 'pdf' || _kind == 'image';
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_changed);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text('${widget.file['name']}', overflow: TextOverflow.ellipsis),
          actions: [
            IconButton(
              tooltip: 'Open in another app',
              icon: const Icon(Icons.open_in_new),
              onPressed: () => launchUrl(Uri.parse(_url), mode: LaunchMode.externalApplication),
            ),
          ],
        ),
        backgroundColor: Colors.grey.shade200,
        floatingActionButton: annotatable && widget.canComment
            ? FloatingActionButton.extended(
                backgroundColor: _commentMode ? accentOrange : null,
                foregroundColor: _commentMode ? Colors.white : null,
                onPressed: () => setState(() => _commentMode = !_commentMode),
                icon: Icon(_commentMode ? Icons.close : Icons.add_comment_outlined),
                label: Text(_commentMode ? 'Tap the page to pin' : 'Comment'),
              )
            : null,
        body: switch (_kind) {
          'pdf' => PdfViewer.uri(
              Uri.parse(_url),
              params: PdfViewerParams(pageOverlaysBuilder: (context, pageRect, page) => _overlay(page.pageNumber, pageRect.size)),
            ),
          'image' => _imageSize == null
              ? const Center(child: CircularProgressIndicator())
              : InteractiveViewer(
                  maxScale: 6,
                  child: Center(
                    child: AspectRatio(
                      aspectRatio: _imageSize!.width / _imageSize!.height,
                      child: LayoutBuilder(
                        builder: (context, box) => Stack(children: [
                          Positioned.fill(child: Image.network(_url, fit: BoxFit.fill)),
                          ..._overlay(1, box.biggest),
                        ]),
                      ),
                    ),
                  ),
                ),
          _ => _external(),
        },
      ),
    );
  }

  Widget _external() {
    final word = _kind == 'word';
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(word ? Icons.description_outlined : Icons.insert_drive_file_outlined, size: 48, color: brandTeal),
          const SizedBox(height: 12),
          Text(
            word
                ? 'Word documents are reviewed with tracked changes, comments and highlights in the web editor. Open it on the web, or open the file in Word on your phone and upload the result as a new version.'
                : 'This file type opens in another app on your phone.',
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          if (word && widget.webUrl != null)
            FilledButton.icon(
              icon: const Icon(Icons.rate_review_outlined),
              onPressed: () => launchUrl(Uri.parse(widget.webUrl!), mode: LaunchMode.externalApplication),
              label: const Text('Review with track changes (web)'),
            ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () => launchUrl(Uri.parse(_url), mode: LaunchMode.externalApplication),
            child: Text(word ? 'Open in Word' : 'Open file'),
          ),
        ]),
      ),
    );
  }
}
