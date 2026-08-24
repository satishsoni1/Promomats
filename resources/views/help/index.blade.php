@php
    $sections = [
        'overview' => 'Overview',
        'roles-workflows' => 'Roles & Workflows',
        'upload' => 'Uploading a Document',
        'submit' => 'Submitting for Review',
        'approve' => 'Approving / Rejecting',
        'versions' => 'Version Control & Revisions',
        'comments' => 'Comments & PDF Annotations',
        'claims' => 'Claims & Content Modules',
        'ai' => 'AI Features',
        'library' => 'Library, Search & Dashboards',
        'admin' => 'Admin Functions',
        'notifications' => 'Notifications',
        'lifecycle' => 'Document Lifecycle Statuses',
        'basket' => 'Download Basket',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Help') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('help._nav')

            <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6">
                <!-- On-this-page nav -->
                <aside class="hidden lg:block">
                    <div class="sticky top-6 bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 text-sm">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">On this page</p>
                        <ul class="space-y-1.5">
                            @foreach ($sections as $anchor => $label)
                                <li><a href="#{{ $anchor }}" class="text-gray-600 hover:text-brand-600">{{ $label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </aside>

                <div class="space-y-6 min-w-0">

                    <div id="overview" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Overview</h3>
                        <p class="text-sm text-gray-700 leading-relaxed">
                            VODO is a document approval, version control and compliance system. Every
                            document (any file type, any size) moves through an <strong>admin-configured
                            approval workflow</strong> — a sequence of stages, each with its own approver
                            role. Reviewers record one of three decisions at each stage:
                        </p>
                        <div class="grid grid-cols-3 gap-3 mt-4">
                            <div class="bg-green-50 rounded-md p-3 text-center">
                                <div class="text-lg font-bold text-green-700">A</div>
                                <div class="text-xs text-green-700">Approved — moves to the next stage</div>
                            </div>
                            <div class="bg-amber-50 rounded-md p-3 text-center">
                                <div class="text-lg font-bold text-amber-700">AwC</div>
                                <div class="text-xs text-amber-700">Approved with Changes — returns for revision</div>
                            </div>
                            <div class="bg-red-50 rounded-md p-3 text-center">
                                <div class="text-lg font-bold text-red-700">NA</div>
                                <div class="text-xs text-red-700">Not Approved — returns for revision or terminates</div>
                            </div>
                        </div>
                    </div>

                    <div id="roles-workflows" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Roles &amp; Workflows</h3>
                        <p class="text-sm text-gray-700 mb-3">
                            Three workflow templates are pre-configured (Admin → Workflows lets you edit
                            stage order, approvers, and A/AwC/NA rules for any of them, or build new ones):
                        </p>
                        <ul class="text-sm text-gray-700 space-y-2 list-disc list-inside">
                            <li><strong>Pharma Workflow 1</strong> — 10-stage chain for new promotional
                                material: Content Manager → TM/AGM Marketing → Regulatory (L1/L2) → Legal
                                (L1/L2) → R&amp;D → Chairperson's Office → post-production Content Manager
                                pass → Design (Internal) → Approved for Distribution.</li>
                            <li><strong>Pharma Workflow 2</strong> — the agency-originated equivalent (11
                                stages), where the revision hub is the Document Owner rather than Content
                                Manager.</li>
                            <li><strong>Pharma Workflow 3</strong> — single-stage fast path for adapting
                                already-approved content: one TM/AGM Marketing sign-off.</li>
                        </ul>
                        <p class="text-sm text-gray-500 mt-3">
                            Your account's roles determine what you're asked to approve. Admin → Users lets
                            an administrator create accounts and assign roles.
                        </p>
                    </div>

                    <div id="upload" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Uploading a Document</h3>
                        <ol class="text-sm text-gray-700 space-y-1.5 list-decimal list-inside">
                            <li>Go to <strong>Documents → Upload Document</strong>.</li>
                            <li>Fill in title, description, category, and optionally product(s)/country(ies)
                                (comma-separated if more than one — this drives the reference number prefix).</li>
                            <li>Pick the approval workflow this document should follow.</li>
                            <li>Optionally set a start date, expiry date, and aging-warning window.</li>
                            <li>Attach the file — any type, up to 500MB — and upload.</li>
                        </ol>
                        <p class="text-sm text-gray-500 mt-3">
                            The document starts in <strong>Draft</strong>. It isn't visible to any approver
                            until you submit it.
                        </p>
                    </div>

                    <div id="submit" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Submitting for Review</h3>
                        <p class="text-sm text-gray-700">
                            From the document page, click <strong>Submit for Review</strong>. This starts the
                            workflow at its first stage and notifies that stage's approver(s). The document's
                            status changes to <strong>In Review</strong>, and a stage-progress bar appears at
                            the top of the page showing exactly where it is in the chain.
                        </p>
                    </div>

                    <div id="approve" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Approving / Rejecting</h3>
                        <p class="text-sm text-gray-700">
                            Check <strong>Approvals Inbox</strong> (or the badge count in the nav bar) for
                            documents waiting on you. Open one and use the <strong>Your Decision</strong> panel
                            to record A / AwC / NA. Comments are required for anything other than a clean
                            Approve — this is enforced, not optional, matching standard audit practice. Every
                            decision is permanently logged in the document's Approval History.
                        </p>
                    </div>

                    <div id="versions" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Version Control &amp; Revisions</h3>
                        <p class="text-sm text-gray-700">
                            Every upload is a new, immutable version — full history (uploader, checksum,
                            change notes) is kept on the document page, and you can download any past version.
                            When a stage returns AwC/NA, the document's status becomes <strong>Revise &amp;
                            Resubmit</strong>: the owner uploads a new version with change notes and
                            resubmits, and the workflow automatically resumes at the stage that sent it back
                            — it doesn't restart from scratch.
                        </p>
                    </div>

                    <div id="comments" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Comments &amp; PDF Annotations</h3>
                        <p class="text-sm text-gray-700 mb-2">
                            Any user who can see a document can post a comment (and reply to one) in the
                            general comment thread at the bottom of the page — useful for discussion that
                            isn't tied to a specific spot in the file.
                        </p>
                        <p class="text-sm text-gray-700">
                            For <strong>PDF files</strong>, a page-by-page preview appears above the document
                            details with zoom and page navigation. Click anywhere on the page to drop a
                            comment pin at that exact spot — reviewers can reply right from the pin. This
                            mirrors inline review-comment tools like Veeva PromoMats, without needing a
                            separate viewer application.
                        </p>
                    </div>

                    <div id="claims" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Claims &amp; Content Modules</h3>
                        <p class="text-sm text-gray-700 mb-2">
                            <strong>Claims</strong> (Admin → Claims) are reusable, pre-approved statements with
                            source references — write and approve a claim once, then insert it into any number
                            of documents. Every document shows "Where Used" so you can see everywhere a claim
                            appears.
                        </p>
                        <p class="text-sm text-gray-700">
                            <strong>Content Modules</strong> (Admin → Content Modules) bundle several claims
                            (plus optional usage rules) into one reusable block — insert the whole module into
                            a document in a single action instead of picking claims one at a time. On a
                            document page, the system also suggests relevant approved claims automatically
                            based on the document's title, description, and product.
                        </p>
                    </div>

                    <div id="ai" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">🤖 AI Features</h3>
                        <p class="text-sm text-gray-700 mb-3">
                            Powered by Claude, configured once at <strong>Admin → AI Settings</strong> (paste an
                            API key — everything below activates immediately, no code changes). Until then,
                            these stay hidden and the rest of the app behaves exactly as before.
                        </p>
                        <ul class="text-sm text-gray-700 space-y-2 list-disc list-inside">
                            <li><strong>AI Compliance Pre-check</strong> — on a document's page, the owner can
                                run a first-pass regulatory scan (reads the actual PDF text where possible)
                                before submitting: flags missing fair-balance/safety info, off-label language,
                                unsubstantiated superlatives, and claims without a reference. Every run is saved
                                to the document permanently as part of its audit trail.</li>
                            <li><strong>AI Suggest Claims</strong> — an on-demand upgrade over the always-on
                                text-match suggestions; reasons about genuine relevance so paraphrased matches
                                get caught too.</li>
                            <li><strong>AI Revision Suggestions</strong> — next to any Approved-with-Changes or
                                Not-Approved decision, the owner can ask AI to draft a specific starting point
                                for the revision based on the reviewer's comment. Always a draft for a human to
                                rewrite and get re-reviewed — never auto-applied.</li>
                            <li><strong>AI Search</strong> — from the search page, ask a plain-English question
                                ("documents mentioning cardiovascular risk expiring this quarter") instead of
                                using manual filters.</li>
                        </ul>
                    </div>

                    <div id="library" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Library, Search &amp; Dashboards</h3>
                        <p class="text-sm text-gray-700 mb-2">
                            The <strong>Documents</strong> library has list and grid views, with a live
                            status/category filter sidebar. The search box in the top nav searches documents
                            and claims together. <strong>Admin → Dashboards</strong> shows approval-rate stats,
                            a materials-by-status breakdown, a content-created-by-month chart, and any overdue
                            approval tasks.
                        </p>
                    </div>

                    <div id="admin" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Admin Functions</h3>
                        <ul class="text-sm text-gray-700 space-y-1.5 list-disc list-inside">
                            <li><strong>Users</strong> — create accounts, assign/change roles for anyone.</li>
                            <li><strong>Roles</strong> — create custom roles and permission sets.</li>
                            <li><strong>Workflows</strong> — build new templates or edit stage order,
                                approvers, and A/AwC/NA transition rules on existing ones.</li>
                            <li><strong>Claims</strong> and <strong>Content Modules</strong> — manage the
                                reusable claims library.</li>
                        </ul>
                        <p class="text-sm text-gray-500 mt-3">Admin access is gated by the "Admin" role.</p>
                    </div>

                    <div id="notifications" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Notifications</h3>
                        <p class="text-sm text-gray-700 mb-2">
                            Every stage assignment, decision, comment, new version, claim insertion,
                            lifecycle status change, account creation/role change, and workflow completion
                            triggers a notification — both in-app (bell icon) and by email — to the relevant
                            owner, current approvers, watchers, and prior commenters. Whoever performed the
                            action isn't notified about their own click.
                        </p>
                        <p class="text-sm text-gray-700 mb-2">
                            <strong>Overdue reminders (the "red flag"):</strong> if a pending approval sits
                            longer than its stage's SLA (or 48 hours by default, if no SLA is set), it gets
                            flagged 🚩 in the Approvals Inbox, the document page, and Admin → Dashboards, and
                            a one-time reminder email goes to both the approver and the document owner. A
                            scheduled job checks every 2 hours.
                        </p>
                        <p class="text-sm text-gray-700">
                            <strong>Outbound email</strong> is configured at Admin → Mail Settings — point it
                            at Gmail SMTP (or any provider) and send a test email right from that page. Until
                            it's configured, emails are logged rather than actually sent.
                        </p>
                    </div>

                    <div id="lifecycle" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Document Lifecycle Statuses</h3>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                            @foreach (\App\Models\Document::STATUS_LABELS as $status => $label)
                                <div class="flex items-center gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 shrink-0">{{ $label }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-sm text-gray-500 mt-3">
                            Expiry/aging are computed automatically by a daily scheduled job; owners/admins can
                            also manually mark a document Approved for Production, Superseded, Obsolete, or
                            Archived from the document page.
                        </p>
                    </div>

                    <div id="basket" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 scroll-mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Download Basket</h3>
                        <p class="text-sm text-gray-700">
                            Add documents to your basket (from the library list or a document page) and
                            download everything as a single zip from the basket icon in the top nav — handy
                            for pulling together a set of approved materials at once.
                        </p>
                    </div>

                    <div class="bg-brand-50 border border-brand-100 rounded-lg p-6 text-sm text-brand-800">
                        Want to see all of this in action with realistic pharma data? See the
                        <a href="{{ route('help.demo-script') }}" class="font-semibold underline">Client Demo Script</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
