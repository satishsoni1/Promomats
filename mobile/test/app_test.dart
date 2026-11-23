import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:vodo_mobile/screens/active_workflow_screen.dart';
import 'package:vodo_mobile/screens/create_job_screen.dart';
import 'package:vodo_mobile/screens/document_screen.dart';
import 'package:vodo_mobile/theme.dart';

/// Screens rendered against canned API responses (the same JSON the Laravel
/// MobileController / MobileCatalogController return).
void main() {
  Widget app(Widget home) => MaterialApp(theme: vodoTheme(), home: home);

  Future<void> pumpWith(WidgetTester tester, Map<String, Object?> routes, Widget home) async {
    // A tall phone, so whole pages are laid out (lists only build what is on screen).
    tester.view.physicalSize = const Size(1080, 7000);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await http.runWithClient(() async {
      await tester.pumpWidget(app(home));
      await tester.pumpAndSettle();
    }, () => MockClient((req) async {
          final body = routes[req.url.path.replaceFirst('/api/v1', '')];
          return body == null ? http.Response('{"message":"Not found"}', 404) : http.Response(jsonEncode(body), 200);
        }));
  }

  Map<String, Object?> job({Map<String, Object?> overrides = const {}}) => {
        'document': {
          'id': 7, 'title': 'Liv.52 DS LBL', 'reference_no': 'IN-PR-1', 'status': 'in_review', 'status_label': 'In Review',
          'collateral': 'Print · LBL', 'owner': 'Olivia Owner', 'products': ['Liv.52'], 'countries': ['IN'],
          'web_url': 'http://x/documents/7', 'legal_hold': false, 'is_placeholder': false,
        },
        'permissions': {'is_owner': false, 'can_manage_tasks': false, 'can_upload_version': false, 'can_add_reference': false, 'comments_locked': false, 'comments_draft': true},
        'banner': null,
        'file': {'id': 1, 'version_no': 1, 'name': 'lbl.pdf', 'kind': 'pdf', 'size': '10 KB', 'url': 'http://x/f'},
        'versions': [],
        'references': [],
        'stages': [
          {'name': 'TM/AGM', 'sequence_no': 1, 'parallel_group': null, 'state': 'done', 'with': []},
          {'name': 'Regulatory', 'sequence_no': 2, 'parallel_group': 'MLR', 'state': 'skipped', 'with': []},
          {'name': 'R&D', 'sequence_no': 3, 'parallel_group': 'MLR', 'state': 'current', 'with': ['Ravi RnD']},
          {'name': 'Chairperson Approval (offline)', 'sequence_no': 4, 'parallel_group': null, 'stage_type': 'offline', 'state': 'upcoming', 'with': []},
        ],
        'pending': [],
        'my_task': {'task_id': 3, 'stage': 'R&D', 'is_draft': false, 'is_offline': false, 'is_final': false, 'can_decide': true},
        'start_panel': null,
        'owner_panel': null,
        'work_task': null,
        'lifecycle': [],
        'history': [],
        'comments': [
          {'id': 1, 'author': 'Ravi RnD', 'body': 'Add the reference', 'draft': true, 'can_edit': true, 'can_resolve': true, 'replies': [], 'created_at': '2026-11-23T10:00:00Z'},
        ],
        ...overrides,
      };

  testWidgets('a reviewer sees parallel MLR with skipped tracks, their decision and draft comments', (tester) async {
    await pumpWith(tester, {'/documents/7': job()}, const DocumentScreen(documentId: 7));

    expect(find.text('IN PARALLEL'), findsOneWidget);
    expect(find.text('SKIPPED'), findsOneWidget);
    expect(find.text('With Ravi RnD'), findsOneWidget);
    expect(find.text('Your decision needed'), findsOneWidget);
    expect(find.textContaining('Your comments are drafts'), findsOneWidget);
    expect(find.text('draft - only you'), findsOneWidget);
  });

  testWidgets('a Brand Manager with a rejected job gets Revise & Resubmit with the Content Agency, never a decision', (tester) async {
    await pumpWith(tester, {
      '/documents/7': job(overrides: {
        'permissions': {'is_owner': true, 'can_manage_tasks': true, 'can_upload_version': true, 'can_add_reference': true, 'comments_locked': false, 'comments_draft': false},
        'my_task': {'task_id': 3, 'stage': 'TM/AGM', 'can_decide': false},
        'banner': {'tone': 'warning', 'text': 'Waiting on Olivia Owner (Brand Manager) to upload the revision.'},
        'owner_panel': {
          'mode': 'revise', 'resubmit_to': 'TM/AGM', 'can_skip_confirmation': true,
          'last_decision': {'by': 'Tara', 'decision': 'Not approved', 'stage': 'TM/AGM', 'comments': 'Wrong claim'},
          'upload_to_confirm': {'version_no': 2, 'by': 'Amy ContentAgency', 'at': '2026-11-23T10:00:00Z'},
        },
        'team_members': {'design': [], 'agency': []},
      }),
    }, const DocumentScreen(documentId: 7));

    expect(find.text('Revise & Resubmit'), findsOneWidget);
    expect(find.text('Content Agency'), findsOneWidget);
    expect(find.text('Design Team'), findsOneWidget);
    expect(find.textContaining('v2 uploaded by Amy ContentAgency'), findsOneWidget);
    expect(find.textContaining("makers can't be checkers"), findsOneWidget);
    expect(find.text('Record decision'), findsNothing);
  });

  testWidgets('the offline Chairperson stage asks for the signed copy', (tester) async {
    await pumpWith(tester, {
      '/documents/7': job(overrides: {'my_task': {'task_id': 3, 'stage': 'Chairperson Approval (offline)', 'is_offline': true, 'is_final': true, 'can_decide': true}}),
    }, const DocumentScreen(documentId: 7));

    await tester.tap(find.text('Record sign-off'));
    await tester.pumpAndSettle();
    expect(find.text('Approved by the Chairperson'), findsOneWidget);
    expect(find.text('Copy received from the Chairperson'), findsOneWidget);
  });

  testWidgets('Active Workflow shows the same tabs as the web, including the agency queue', (tester) async {
    await pumpWith(tester, {
      '/tasks': {
        'approvals': [], 'my_jobs': [], 'design_queue': [], 'bm_jobs': [], 'brand_managers': [],
        'revisions': [
          {'id': 9, 'title': 'Septilin Emailer', 'reference_no': 'IN-2', 'status': 'draft_aligned', 'status_label': 'Draft Aligned', 'reason': 'Draft aligned by Carl - assign the creative, then start the review'},
        ],
        'work': [],
        'agency_queue': [
          {'work_task_id': 4, 'type': 'Rework after review', 'team': 'agency', 'team_label': 'Content Agency', 'requested_by': 'Olivia', 'document': {'id': 5, 'title': 'Neem Standee', 'reference_no': 'IN-3'}, 'can_upload': true, 'members': []},
        ],
        'show_design_queue': false,
        'show_agency_queue': true,
        'counts': {'action': 1, 'mine': 0, 'design': 0, 'agency': 1},
      },
    }, const ActiveWorkflowScreen());

    expect(find.text('Needs my action (1)'), findsOneWidget);
    expect(find.text('Content Agency (1)'), findsOneWidget);
    expect(find.text('Septilin Emailer'), findsOneWidget);
    expect(find.text('Assign creative'), findsOneWidget);
  });

  testWidgets('New job offers stakeholders per stage with the MLR skip rule and reference documents', (tester) async {
    await pumpWith(tester, {
      '/create-options': {
        'brands': [], 'document_types': [], 'projects': [], 'target_audiences': [], 'rules': [], 'all_users': [],
        'templates': [{'id': 1, 'name': 'Pharma Workflow 1', 'customisable': true}],
        'stages': {
          '1': [
            {'stage_id': 11, 'name': 'R&D', 'sequence_no': 1, 'parallel_group': 'MLR', 'due_hours': 48, 'candidates': [{'id': 5, 'name': 'Ravi RnD'}, {'id': 6, 'name': 'Ann'}]},
          ],
        },
        'default_due_hours': 48,
        'creative_teams': [{'key': 'later', 'label': 'Decide later'}],
      },
    }, const CreateJobScreen());

    expect(find.text('Reference documents (optional)'), findsOneWidget);
    await tester.tap(find.text('Auto-assign from Brand / Type'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pharma Workflow 1').last);
    await tester.pumpAndSettle();

    expect(find.text('Stakeholders & due dates for each stage'), findsOneWidget);
    expect(find.text('Ravi RnD'), findsOneWidget);
    expect(find.textContaining('tracks left unticked are skipped'), findsOneWidget);
  });
}
