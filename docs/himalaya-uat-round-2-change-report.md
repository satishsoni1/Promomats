# VODO PromoMats — Change Report: Himalaya UAT Round 2 ("Fine Tuning Required")

23 November 2026 · Globalspace

## Summary

19 of the 20 items are built. The **mobile app replica** is not part of this
round; it needs its own scope (see Open items).

Two problems the team reported came from jobs running on the older **Pharma
Workflow 1 / 2** templates:

- **MLR still ran in sequence (MLR #3).** Those templates never had parallel MLR.
- **The stage read "R&D Sign-off" (MLR #4).** That name only existed in those
  templates.

Both templates now have new versions with parallel MLR. The Himalaya PromoMats
workflows are revised too.

The flow for every new job:

```
Brand Manager creates the job (Word draft, or a placeholder) + attaches references
        │  picks the person for each stage
        ▼
1. Draft Alignment – Content Manager   ⇄  Brand Manager   (to and fro until aligned)
        │  aligned → back to the Brand Manager (nothing moves on by itself)
        ▼
   Brand Manager assigns the creative → Content Agency  or  Internal Design Team
        │  their upload comes back to the Brand Manager to confirm
        ▼
   Brand Manager starts the review
2. Content Manager Review
3. TM/AGM Approval
4. MLR — R&D ║ Regulatory ║ Legal       ← at the same time, only the people picked
5. Chairperson Approval (offline)       ← Content Manager uploads the signed copy
        ▼
Approved for Production → (Brand Manager) Release for Distribution
```

What each decision does now:

| Decision | What happens next |
| --- | --- |
| Approved | Next stage. |
| Approved with changes | **Next stage directly.** The Brand Manager sees the requested changes. It goes back to the Brand Manager only from the draft stage, or from the final stage. |
| Not approved | Back to the Brand Manager. They upload the revision, or assign it to the Content Agency or Design Team. After the Brand Manager resubmits, it goes **straight back to the stage that rejected it**. |

**Testing:** 106 automated tests pass, 10 of them new for this round. The web
build compiles.

## Traceability — every item

**MLR Flow**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Brand Managers and Job Owners are the same | The "Document Owner" role is merged into **Brand Manager**. Existing Document Owners become Brand Managers automatically. The screens now say "Brand Manager" instead of "task owner". | Done |
| 2 | MLR went to several people even when one was picked | Two gaps. First, within MLR, a track with nobody ticked was sent to *every* reviewer in that role. Now, once the Brand Manager picks someone for any MLR track, the tracks left unticked are skipped. Second, the Brand Manager can no longer be picked as a reviewer on their own job. | Done |
| 3 | Parallel MLR not starting; still sequential | Jobs were on Pharma Workflow 1/2, which ran MLR in sequence. New versions run Regulatory L1/L2, Legal L1/L2 and R&D at the same time. The Himalaya workflows run R&D, Regulatory and Legal at the same time. | Done |
| 4 | "R&D Sign-off" should be "R&D"; not the final authority | Renamed **R&D**. It is one of the MLR stakeholders, not a sign-off stage. | Done |
| 5 | Brand Managers only start, upload or assign; makers can't be checkers | Brand Managers, and whoever owns a job, never get Approve/Reject on it. This is blocked when picking stakeholders, when reassigning, and in the workflow itself. Their buttons now read "Send to Content Manager", "Start workflow", "Assign to…" and "Resubmit". | Done |

**Comments**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Comments visible before the review is submitted | A reviewer's comments are **drafts**: only they can see them, marked "draft — only you". They are published when the reviewer submits their review, or when the stage moves on. This applies on the web, the mobile app, the history report and the "with comments" PDF. | Done |
| 2 | Approved with changes went back to the Brand Manager | It now moves to the next MLR level directly. Only Not approved goes back. | Done |
| 3 | After Not approved: Brand Manager uploads, or assigns to the Content Agency; agency upload confirmed by the Brand Manager; back to the rejecting level | The revise panel offers **Upload it myself**, **Content Agency** or **Internal Design Team**. The agency gets its own queue in Active Workflow. Their upload comes back to the Brand Manager to check. The Brand Manager resubmits, and it returns to the stage that rejected it. | Done |
| 4 | Lock the document for a reviewer once they've submitted | After submitting, a reviewer cannot add or edit comments, and the Word editor is read-only for them, while the job is with others. It unlocks if the job comes back to them. | Done |

**Chairperson**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Chairperson is offline; Content Manager uploads the file to close the workflow | The Chairperson stage is an **offline sign-off** held by the Content Manager. They must upload the copy received from the Chairperson to record an approval. The uploaded copy becomes the signed version. | Done |
| 2 | "Approved for Production" only after the Chairperson | The workflow ends **Approved for Production**. That status can no longer be set by hand. Afterwards the Brand Manager can **Release for Distribution**. | Done |

**Mobile application**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Replica of the web version | Not this round (see Open items). The app's server side already follows the new rules: draft comments, the comment lock, and blocking the offline sign-off on the phone (it needs a file upload, so it is done on the web). | Not started |

**Provisions & Features**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Attach reference documents while uploading | **Reference documents** field (several files) on the upload form. | Done |
| 2 | Track changes for all stakeholders | Every stakeholder reviews Word files with tracked changes. The Brand Manager can also **accept or reject** those changes in the browser. | Done |
| 3 | Draft to and fro between Brand Manager and Content Manager; then the Content Agency; then back to the Brand Manager, who triggers MLR | **Draft Alignment** stage: "Needs changes" goes back to the Brand Manager and returns to the Content Manager after the revision. "Draft aligned" returns the job to the Brand Manager, who assigns the creative and then starts the review. Placeholders no longer go anywhere unless the Brand Manager chooses. | Done |
| 4 | Annotations in Word documents | A **highlighter** in the Word review toolbar, alongside comments. | Done |
| 5 | All jobs visible in the Library | New **Library → Jobs** tab, with All / In progress / Approved filters and search. | Done — see Open items |

**Internal Design Team**

| # | Feedback | What was done | Status |
| --- | --- | --- | --- |
| 1 | Same to-and-fro, then the Internal Design Team | Same flow as Provisions #3. After the draft is aligned, the Brand Manager picks **Internal Design Team** instead of the Content Agency. | Done |
| 2 | After Content Manager approval it went straight to the Design Team | It now always comes back to the Brand Manager first. The automatic "Content Creator" draft stage is removed from the new workflow versions. | Done |

## Deployment

1. Pull the code, then run `npm install && npm run build`. There are no new PHP
   packages.
2. Run `php artisan migrate`. This does four things:
   - adds the draft-aligned status, draft comments and the completion status;
   - re-routes Approved with changes / Not approved on **all existing
     workflows**, including jobs already in flight;
   - merges Document Owner into Brand Manager;
   - marks every existing comment as published.
3. Run `php artisan db:seed --class=HimalayaUatRoundTwoSeeder`. This creates new
   versions of both Himalaya PromoMats workflows and Pharma Workflow 1/2, and
   points auto-assignment at them. Jobs already in progress stay on the version
   they started with. Safe to re-run.
4. In Admin → Users, give the **Content Agency** role to the agency users.

## Open items — decisions needed from Himalaya

| Item | Question |
| --- | --- |
| Mobile replica | The current app covers review, approve and comment. A full replica of the web (upload, stakeholder picking, the draft loop, agency work, the library) is a separate build. Which screens are needed for travel first? |
| Library visibility | Library → Jobs shows every job the person is a stakeholder on, plus all approved material. This keeps the round 1 rule that in-progress jobs are visible only to their stakeholders. Should *everyone* see every in-progress job instead? |
| Approved with changes at the Chairperson | At the final stage it still goes back to the Brand Manager, so the changes are made before production. Confirm, or should it close straight away? |
| Track changes on PDFs | PDFs can't carry Word-style tracked changes. On PDFs, reviewers use highlight plus **Replace with** instead. |
| Word suggestions and drafts | A reviewer's *platform* comments are drafts until they submit. Tracked changes saved inside the Word file become a new version straight away. |
| Content Agency members | Names and emails of the agency users who should get the role. |
