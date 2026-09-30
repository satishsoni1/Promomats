import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:photo_view/photo_view.dart';
import 'package:url_launcher/url_launcher.dart';

/// Full-screen view of the creative: PDFs render in-app (pinch to zoom, scroll
/// pages), images open zoomable, anything else goes to the phone's own viewer.
/// The URL is a short-lived signed link from the API, so no header is needed.
class ViewerScreen extends StatelessWidget {
  const ViewerScreen({super.key, required this.file});
  final Map<String, dynamic> file;

  @override
  Widget build(BuildContext context) {
    final url = file['url'] as String;
    final kind = file['kind'] as String?;

    return Scaffold(
      appBar: AppBar(
        title: Text('${file['name']}', overflow: TextOverflow.ellipsis),
        actions: [
          IconButton(
            tooltip: 'Open in another app',
            icon: const Icon(Icons.open_in_new),
            onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
          ),
        ],
      ),
      backgroundColor: Colors.grey.shade200,
      body: switch (kind) {
        'pdf' => PdfViewer.uri(Uri.parse(url)),
        'image' => PhotoView(
            imageProvider: NetworkImage(url),
            backgroundDecoration: BoxDecoration(color: Colors.grey.shade200),
            minScale: PhotoViewComputedScale.contained,
          ),
        _ => Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                const Icon(Icons.insert_drive_file_outlined, size: 48),
                const SizedBox(height: 12),
                const Text('This file type opens in another app on your phone.', textAlign: TextAlign.center),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
                  child: const Text('Open file'),
                ),
              ]),
            ),
          ),
      },
    );
  }
}
