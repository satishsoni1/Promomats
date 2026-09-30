# VODO PromoMats — Change Report: Himalaya UAT Feedback Round

30 September 2026 · Prepared by Satish Soni (Globalspace)

## Summary

All 37 requested items are built. Two items still have a part that needs a
decision from Himalaya:

- **Single sign-on:** the decision password is gone, but SSO login itself is
  deferred for now.
- **Helpdesk:** the in-app helpdesk is live, but the support turnaround (TAT)
  still has to be agreed.

The Design Team member list is also needed to finish setting up roles.

Sources:

- **The stakeholder feedback list:** 19 items.
- **Prathamesh's observation sheet:** 15 items.
- **Three review items requested earlier in this round:** drag-and-drop upload,
  Word review with tracked changes, and PDF commenting.

The headline changes are:

- **New approval flow:** Draft stages (Content Manager, then Content Creator /
  Design Team), with the task owner choosing the person for every stage.
- **Active Workflow page:** one place for everything that needs action, with a
  separate view per Brand Manager.
- **Adobe-style review viewer:** comments sit in a side panel, so nothing covers
  the artwork.
- **Stakeholder-only visibility:** only a job's stakeholders can see it while it
  is in workflow. Approved material stays open to everyone as the library.
- **Flutter mobile app:** review and approve from a phone.

Two problems reported as "stuck" jobs turned out to be real gaps. Both are fixed:

- **GL-VS-202609-MVWMXB** was waiting for its task owner to upload a revision
  after TM/AGM gave *Approved with changes*. Nothing on screen or by email told
  anyone. It now shows a clear "waiting on" banner, appears in the owner's Active
  Workflow, and the owner gets an email. Once the revision is uploaded, the job
  moves straight on to MLR.
- **IN-PR-202609-BQEURQ** went back to the Content Manager after the
  Chairperson's *Approved with changes* and re-ran every stage. Its status also
  showed "Revise & Resubmit" while a reviewer actually had it. Both are fixed,
  and the job now correctly shows as In Review.

A third bug turned up during testing: **every workflow email was being sent
twice**, and every audit entry was written twice. This is also fixed.

**Testing:**

- 95 automated tests pass, 24 of them added during this work.
- Every main page was checked as five real accounts: admin, Content Manager,
  MLR Medical, Designer, and Brand Manager/Regulatory.
- The mobile app passes code analysis and its test. Its install file (APK) must
  be built on a machine with internet access.

## How the approval flow works now

Both Himalaya PromoMats workflows (PDF/JPG/GIF and Word/Video/PPT) have a new
version. Jobs already in progress stay on the version they started with.

```
Task owner starts the job (uploads, or creates a placeholder)
        │  picks the person for each stage + due time (48h default)
        ▼
1. Draft – Content Manager          (Jalba or Rathna)
2. Draft – Content Creator          (Design Team — anyone in the team, or a named designer)
3. Content Manager Review           (Jalba or Rathna)
4. TM/AGM Approval                  (the TM/AGM the owner chose)
5. MLR Review — Medical ║ Regulatory ║ Legal   ← all three at the same time
6. Final Approval – Chairperson
        ▼
Approved for distribution → visible to everyone in the library
```

What happens at each decision:

| Decision | What happens next |
| --- | --- |
| Approved | Moves to the next stage. |
| Approved with changes | Goes back to the **task owner**. The owner uploads the revision or sends it to the Design Team. Once the revision is in, it moves on to the **next** stage without being re-reviewed, and after the Chairperson it completes. |
| Not approved | Goes back to the task owner. Once revised, it returns to the **same** stage. |
| Draft stage | Buttons read "Submit to next stage" or "Needs changes". |

Other rules:

- **Brand Managers** can start jobs, upload files and assign stakeholders. They
  never get Approve/Reject tasks.
- **MLR reviewers** can review, select text and comment, but cannot upload.
- **Uploading at any stage:** anyone else currently holding a stage can upload a
  revised file. The job carries on with the new version.
- **Admin setting:** an admin can switch a workflow to "send back to the same
  stage after Approved with changes" if a particular workflow needs it.

## Traceability — every feedback item

**Stakeholder feedback list**

| # | Feedback | What was done | Where | Status |
| --- | --- | --- | --- | --- |
| 1 | Draft stage for Content Manager and Content Creator | Two Draft stages added at the start of both Himalaya workflows. Draft stages use "Submit / Needs changes" instead of Approve/Reject. | New workflow versions (v2) | Done |
| 2 | Draft flow: Document Owner → Content Manager → Content Creator | The owner starts the job, then Draft – Content Manager, then Draft – Content Creator (Design Team). | Workflow v2 | Done |
| 3 | Inputs show in the library for viewing only; should be in Active Workflow where action is needed | New **Active Workflow** page (replaces "Approvals"). **Needs my action** covers approvals, revisions of my jobs and design work. **My jobs** shows who has each job and when it's due. The sidebar badge counts everything waiting on you. | Sidebar → Active Workflow | Done |
| 4 | After Approved with changes, Brand Manager reassigns to the Design Team | The Revise & Resubmit panel has two choices: **Upload it myself** or **Send to Design Team**, with instructions, a due date, an optional designer, and "send back into review when uploaded". | Document page | Done |
| 5 | Content Manager goes straight to Rathna; owner should pick Jalba or Rathna, and the right TMM/AGM | Every stage is now role-based. The owner ticks the person per stage on the upload form. | Upload form → Stakeholders & due dates | Done |
| 6 | After Approved / Approved with changes, move to the next stage, not back for committee approval | The engine moves on after the revision. It no longer loops back through the Content Manager or re-runs earlier stages. | Engine | Done |
| 7 | Task Manager / Brand Manager selects the stakeholder for each stage | Per-stage people picker, plus **Add someone else** for anyone outside the usual list. | Upload form | Done |
| 8 | Reassign a task when the assignee is unavailable | **Reassign / due** on each pending person, with a reason. It keeps the due date, notifies the new person, and is recorded in the audit trail. | Document page → Workflow Status | Done |
| 9 | Remove upload access for MLR; keep review, text selection and commenting | MLR roles can't start jobs or upload versions. Commenting, highlighting and Word suggestions still work. | Permissions | Done |
| 10 | Each Brand Manager gets their own Active Workflow view, like Veeva | **Brand Managers** tab: admins see a tab per Brand Manager, and each Brand Manager sees their own. | Active Workflow | Done |
| 11 | Whole Design Team acts as Content Creators; routing to Content Creator after changes isn't working | New **Design Team** role. The Content Creator stage and rework go to the whole team, who can take the work or assign it to a designer. | Roles; Active Workflow → Design Team queue | Done — member list needed |
| 12 | Print and Digital classification with detailed types under each | **Collateral classification** (Print / Digital) and **Type of collateral**. Print: LBL, LBC, Retailer Poster, VAF, Stockists Poster, In-Clinic Visibility, Out-Clinic Visibility, Dangler, and more. Digital: E-Detailer, Emailer, Social Post, WhatsApp, Video, and more. | Upload form; Admin → Document Types | Done — please confirm the list |
| 13 | Placeholder so the Design Team can upload the artwork directly | **Placeholder** tick box on the upload form: no file needed, add a brief, and it goes to the Design Team. Their upload fills it in and the owner is notified. | Upload form | Done |
| 14 | Text selection for comments; edit a comment after posting | **Highlight text** tool, and **Edit** on your own comments and replies (marked "edited"). | Review viewer | Done |
| 15 | Annotations like Adobe's, without covering the artwork | Comments live in a side panel. The page shows small numbered markers and see-through highlights only. | Review viewer | Done |
| 16 | Show the selected text in the comment box and allow replacement text | The selected words are quoted in the comment, with a **Replace with** field. Replacement text also appears in the mobile app and the "With comments" PDF. | Review viewer | Done |
| 17 | Stop asking for the decision password or a fresh login | The decision password is off by default. The signed name, time and IP are still recorded. Sessions now last 8 hours instead of 2. | Decision panel | Done |
| 18 | Confirm due-date emails; add them if missing | Overdue emails already existed (once past the due time). Added a **Due soon** email 12 hours before each task is due. Both are checked hourly and sent once per task. | Email | Done |
| 19 | Mobile app like Veeva | Flutter app for Android and iOS: sign in, Active Workflow tabs, open the creative full screen, review history, comments, record a decision. | `mobile/` | Done — publishing pending |

**Prathamesh's observation sheet**

| # | Observation | What was done | Status |
| --- | --- | --- | --- |
| 20 | Task owner chooses stakeholders beyond a fixed workflow | Any active person can be picked for a stage, not just the stage's usual people (see #7). | Done |
| 21 | Task due date may be beyond the expiry date | New **Task due date** field with no link to the expiry date. | Done |
| 22 | 48 hours per stage by default; task owner can change it | A "Due in __ hours" box for each stage, defaulting to 48. The due date of a live task can also be moved. | Done |
| 23 | Drop-down of collateral types | See #12. | Done |
| 24 | Creatives should open in full view (e.g. IN-PR-202609-BQEURQ) | The viewer opens in **Full view** (whole page fitted), with Fit width, zoom and **Fullscreen**. JPG/PNG artwork now opens in the viewer too. | Done |
| 25 | Highlight portions of the creative with comments | **Highlight area** (drag a box, works on images and scanned artwork) plus **Highlight text**. | Done |
| 26 | Jobs visible only to their stakeholders; wider repository in the library | While in workflow, a job is visible only to its owner, observers, people who have or had a task on it, the planned stakeholders (only the picked person once the owner has chosen), the Design Team if it has design work, and admins. Approved material is visible to everyone. | Done |
| 27 | Flow stuck after changes/approval (GL-VS-202609-MVWMXB) | It was waiting on the task owner with no signal. Added a "waiting on" banner, the owner's Active Workflow entry, and a "revision needed" email. Also fixed the loop-back bug and the misleading status. | Done |
| 28 | After TM/AGM, MLR goes to all three in parallel, not in sequence | The Himalaya workflows run MLR Medical, Regulatory and Legal at the same time. The progress bar now shows them stacked as "in parallel". | Done |
| 29 | Upload a revised/new document at every stage | Anyone holding a stage (except MLR), the owner and the Design Team can upload. Reviewers continue on the new version. | Done |
| 30 | No password when approving; system will use Himalaya SSO | The decision password is removed (see #17). SSO login is **deferred** for now, and the login page is unchanged. | Partly done — SSO deferred |
| 31 | Brand Managers only start, upload or assign — no Approve/Reject | Brand Managers are never given decision tasks and are blocked from recording one. | Done |
| 32 | Approved with changes at MLR/Chairperson went back to the Content Manager and restarted | It now goes back to the task owner. After the revision it moves on (after the Chairperson, it completes). See #6. | Done |
| 33 | Assign to the internal Design Team as a whole, who then assign a designer | **Design Team queue** with **Take it** / **Assign to…**. The team is notified together. | Done |
| 34 | Helpdesk support for glitches/changes; agree the TAT | In-app **Helpdesk** in the sidebar. Tickets are emailed to support, acknowledged with a first-response time (urgent 2h, high 4h, normal 8h, low 24h), and tracked by status. | Built — TAT to agree |

**Requested earlier in this round**

| # | Request | What was done | Status |
| --- | --- | --- | --- |
| 35 | Drag and drop instead of browsing for a file | A drop zone on every upload form (7 forms). | Done |
| 36 | View a Word document and mark changes with tracked changes on the platform | Word files open in an in-page editor (SuperDoc) with Track Changes and Word comments. Saving creates a new version, and the changes still open in desktop Word. No server limit on reviewers. | Done |
| 37 | View and comment on PDFs on the platform | The review viewer above, plus **⬇ With comments**, which downloads the PDF with all comments as real Acrobat notes. | Done |

## Deployment and setup

These steps are for the team deploying to the Himalaya server.

1. **Get the code and build the pages:** pull the code, then run
   `npm install && npm run build`. No new PHP packages were added.
2. **Update the database:**
   - Run `php artisan migrate`. This adds two database changes: review comments,
   and this round's workflow, task and helpdesk tables. It also fixes the stuck
   job statuses and gives existing pending tasks a due date.
   - Run `php artisan db:seed --class=HimalayaFeedbackRoundSeeder`. This adds
   the Design Team role, the Print/Digital collateral types, and the new
   Himalaya workflow versions, and points auto-assignment at them.
   - Both steps are safe to re-run.
3. **Assign roles** (Admin → Users): Design Team members, Brand Managers,
   Content Managers, TM/AGM, and MLR reviewers.
4. **Keep the scheduler and queue worker running:**
   - The scheduler (`php artisan schedule:run` every minute) sends the due-soon
     and overdue emails.
   - The queue worker sends the emails.
5. **Check `.env`:**
   - `SESSION_LIFETIME=480`
   - `HELPDESK_EMAIL=<support mailbox>`
   - Optional: `APPROVAL_REQUIRE_PASSWORD`, `DEFAULT_STAGE_DUE_HOURS`,
     `DUE_SOON_REMINDER_HOURS`, `MOBILE_TOKEN_DAYS`
6. **Build the mobile app** on a machine with internet access:
   `flutter build apk --release --dart-define=API_BASE_URL=https://<server>`.
   For iOS, run `flutter build ipa` on a Mac. Store publishing needs the signing
   keys and app icon.

## Open items — decisions needed from Himalaya

| Item | What we need | Owner |
| --- | --- | --- |
| Design Team members | Names and emails of everyone in the internal Design Team, so they get the role. Currently only Deepak plus two test users. | Himalaya Marketing |
| Collateral type list | Confirm or edit the Print and Digital lists in #12. Admins can also change them any time. | Himalaya Marketing |
| Brand Managers | Who should have the Brand Manager role, which gives them their own Active Workflow tab and no Approve/Reject. | Himalaya Marketing |
| Helpdesk TAT | Agree the support turnaround and hours, and the support mailbox address. | Globalspace + Himalaya |
| Single sign-on | Deferred. Needs Himalaya IT's Microsoft Entra ID details when you want it. | Himalaya IT |
| Mobile app publishing | Play Store / App Store accounts, or internal distribution via MDM, plus the production server address. | Himalaya IT |
| Older "Pharma Workflow 1/2" templates | These still run MLR in sequence. Retire them, or convert them to the parallel flow? | Himalaya Marketing |
| Word editor licence | The in-page Word editor (SuperDoc) is open source under AGPL. A commercial licence is available if Himalaya's policy needs one. | Globalspace |
