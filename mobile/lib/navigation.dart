import 'package:flutter/material.dart';

import 'screens/document_screen.dart';

/// Opens a job; resolves when the user comes back, so lists can refresh.
Future<void> openJob(BuildContext context, int documentId) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentScreen(documentId: documentId)));
