import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../theme.dart';

// ---------------------------------------------------------------- formatting

String fmtDate(String? iso, {String pattern = 'd MMM yyyy, HH:mm'}) =>
    iso == null || iso.isEmpty ? '' : DateFormat(pattern).format(DateTime.parse(iso).toLocal());

String fmtShort(String? iso) => fmtDate(iso, pattern: 'd MMM, HH:mm');

String dueLabel(String? iso, {bool overdue = false, bool dueSoon = false}) {
  if (iso == null) return '';
  if (overdue) return 'Overdue';
  if (dueSoon) return 'Due soon';
  return 'Due ${fmtShort(iso)}';
}

// ---------------------------------------------------------------- status

/// Same colours as the web's status badge.
(Color, Color) statusColors(String? status) => switch (status) {
      'in_review' || 'pending_expiration' => (Colors.amber.shade50, Colors.amber.shade900),
      'approved_with_changes_pending' => (Colors.orange.shade50, Colors.orange.shade900),
      'draft_aligned' => (Colors.purple.shade50, Colors.purple.shade800),
      'rejected' || 'expired' => (Colors.red.shade50, Colors.red.shade800),
      'approved' || 'approved_for_production' => (Colors.green.shade50, Colors.green.shade800),
      'approved_for_distribution' => (Colors.teal.shade50, Colors.teal.shade800),
      _ => (Colors.grey.shade100, Colors.grey.shade700),
    };

class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.status, required this.label});
  final String? status;
  final String label;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = statusColors(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Text(label, style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.w600)),
    );
  }
}

class DueChip extends StatelessWidget {
  const DueChip({super.key, required this.iso, this.overdue = false, this.dueSoon = false});
  final String? iso;
  final bool overdue;
  final bool dueSoon;

  @override
  Widget build(BuildContext context) {
    if (iso == null) return const SizedBox.shrink();
    final color = overdue ? Colors.red.shade800 : (dueSoon ? Colors.amber.shade900 : Colors.grey.shade700);
    return Text(dueLabel(iso, overdue: overdue, dueSoon: dueSoon),
        style: TextStyle(fontSize: 11, color: color, fontWeight: overdue || dueSoon ? FontWeight.w600 : null));
  }
}

/// The coloured notice at the top of a job (waiting on…, draft aligned, locked…).
class NoticeBanner extends StatelessWidget {
  const NoticeBanner({super.key, required this.tone, required this.text, this.icon});
  final String tone;
  final String text;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final (bg, fg, ic) = switch (tone) {
      'warning' => (Colors.amber.shade50, Colors.amber.shade900, Icons.hourglass_top),
      'danger' => (Colors.red.shade50, Colors.red.shade900, Icons.gavel),
      'info' => (Colors.purple.shade50, Colors.purple.shade900, Icons.info_outline),
      'draft' => (Colors.lightBlue.shade50, Colors.lightBlue.shade900, Icons.edit_note),
      _ => (Colors.grey.shade100, Colors.grey.shade800, Icons.lock_outline),
    };
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10), border: Border.all(color: fg.withValues(alpha: 0.2))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon ?? ic, size: 18, color: fg),
        const SizedBox(width: 8),
        Expanded(child: Text(text, style: TextStyle(color: fg, fontSize: 13))),
      ]),
    );
  }
}

// ---------------------------------------------------------------- layout

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key, this.trailing});
  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 20, bottom: 6),
        child: Row(children: [
          Expanded(
            child: Text(title, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold, color: brandTeal)),
          ),
          ?trailing,
        ]),
      );
}

class PanelCard extends StatelessWidget {
  const PanelCard({super.key, required this.title, required this.children, this.color, this.subtitle});
  final String title;
  final String? subtitle;
  final List<Widget> children;
  final Color? color;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: color ?? brandTeal.withValues(alpha: 0.35), width: 1.5)),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
            if (subtitle != null) ...[
              const SizedBox(height: 4),
              Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
            ],
            const SizedBox(height: 10),
            ...children,
          ]),
        ),
      );
}

class EmptyView extends StatelessWidget {
  const EmptyView(this.message, {super.key, this.icon = Icons.inbox_outlined});
  final String message;
  final IconData icon;

  @override
  Widget build(BuildContext context) => ListView(children: [
        Padding(
          padding: const EdgeInsets.all(48),
          child: Column(children: [
            Icon(icon, size: 40, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: TextStyle(color: Colors.grey.shade600)),
          ]),
        ),
      ]);
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(Icons.cloud_off, size: 40, color: Colors.grey.shade500),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
          ]),
        ),
      );
}

// ---------------------------------------------------------------- actions

void snack(BuildContext context, String message) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

/// Runs an API action with a blocking spinner and reports the server's message.
/// Returns the response, or null if it failed (the error is shown).
Future<Json?> runAction(BuildContext context, Future<Json> Function() action, {String? success}) async {
  showDialog<void>(context: context, barrierDismissible: false, builder: (_) => const Center(child: CircularProgressIndicator()));
  try {
    final res = await action();
    if (context.mounted) {
      Navigator.of(context, rootNavigator: true).pop();
      final msg = success ?? (res['message'] as String?);
      if (msg != null && msg.isNotEmpty) snack(context, msg);
    }
    return res;
  } on ApiException catch (e) {
    if (context.mounted) {
      Navigator.of(context, rootNavigator: true).pop();
      snack(context, e.message);
    }
    return null;
  }
}

/// Lets the user choose files on the phone.
Future<List<UploadFile>> pickFiles({bool multiple = false}) async {
  final result = await FilePicker.platform.pickFiles(allowMultiple: multiple);
  if (result == null) return [];
  return result.files.where((f) => f.path != null).map((f) => UploadFile(path: f.path!, name: f.name)).toList();
}

/// A tappable "choose a file" field showing the chosen file's name.
class FileField extends StatelessWidget {
  const FileField({super.key, required this.label, required this.files, required this.onChanged, this.multiple = false, this.hint});
  final String label;
  final String? hint;
  final List<UploadFile> files;
  final bool multiple;
  final ValueChanged<List<UploadFile>> onChanged;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () async {
        final picked = await pickFiles(multiple: multiple);
        if (picked.isNotEmpty) onChanged(multiple ? [...files, ...picked] : picked);
      },
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(border: Border.all(color: Colors.grey.shade400), borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          const Icon(Icons.upload_file, color: brandTeal),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(files.isEmpty ? label : files.map((f) => f.name).join(', '),
                  style: TextStyle(fontWeight: files.isEmpty ? null : FontWeight.w600), maxLines: 3, overflow: TextOverflow.ellipsis),
              if (hint != null && files.isEmpty) Text(hint!, style: Theme.of(context).textTheme.bodySmall),
            ]),
          ),
          if (files.isNotEmpty) IconButton(icon: const Icon(Icons.close, size: 18), onPressed: () => onChanged([])),
        ]),
      ),
    );
  }
}

/// Bottom-sheet wrapper with the keyboard inset handled.
Future<T?> showSheet<T>(BuildContext context, Widget child) => showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: SingleChildScrollView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 20), child: child),
      ),
    );
