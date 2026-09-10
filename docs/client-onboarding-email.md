# Client review email — draft

> Fill in the bracketed placeholders (URL, client-admin name, your signature) before sending.
> Send over a channel the client expects. This is a demo / UAT environment, not production.

---

**To:** [client admin]
**Subject:** VODO approval system — users, workflows & functionality for your review

Hi [name],

The VODO document-approval environment is set up and ready for your review. Below is
the full list of accounts created, the approval workflows configured, and a short
summary of what the system does — including what's new in this build.

**Please try it out and reply with any corrections or suggestions** — names, email
addresses, role assignments, workflow stage order / approvers, anything.

---

## 1. Sign in

**[https://your-vodo-instance.example.com]**

Every seeded account uses the password **`welcome`**. The Himalaya PromoMats and
Scientific Publications team members are prompted to set their own password on first
login; please change the admin and demo passwords too.

---

## 2. Administrator

| Name | Email | Role |
|---|---|---|
| System Admin | `admin@globalspace.in` | Admin — full configuration: users, roles, workflows, workflow rules, brands, document types, claims, mail / AI / archiving / cold-storage settings, audit logs |

Change this password immediately after your first login (top-right menu → Profile).

**Appointing a department admin:** give a person the **“Department Admin”** role and
set their **Department**. They can then open the admin area but manage only the
**Users, Workflows, Workflow Rules and dashboards for that one department** — never
system settings, roles, brands or claims, and they can't create other admins.
Everyone else has no admin access at all.

---

## 3. First steps (about 5 minutes)

1. Sign in as **System Admin** and change the password.
2. Open **Admin → Users** and check the accounts in section 4 below.
3. Open **Admin → Workflows** and check the two Scientific Publications workflows in
   section 5.
4. Sign in as `owner@promomats.test` (password `welcome`), upload any file, pick a
   workflow, and submit it.
5. Sign in as the next approver in that chain and record a decision — watch the
   stage-progress bar move.
6. The in-app **Help** and **Help → Client Demo Script** pages have a full
   walkthrough with realistic data.

---

## 4. Users created

### 4a. Demo role accounts
One login per approval role, for walking a document end to end. `@promomats.test`,
password `welcome`. These are placeholders, not real people.

| Name | Email | Role |
|---|---|---|
| Olivia Owner | `owner@promomats.test` | Document Owner |
| Carl Contentmanager | `contentmanager@promomats.test` | Content Manager |
| Tara TmAgm | `tmagm@promomats.test` | TM/AGM Marketing |
| Raj Regulatory1 | `reg1@promomats.test` | Regulatory Level 1 |
| Rina Regulatory2 | `reg2@promomats.test` | Regulatory Level 2 |
| Leo Legal1 | `legal1@promomats.test` | Legal Level 1 |
| Lena Legal2 | `legal2@promomats.test` | Legal Level 2 |
| Ravi RnD | `rnd@promomats.test` | R&D |
| Chandra Chairperson | `chairperson@promomats.test` | Chairperson's Office |
| Diya DesignInternal | `designinternal@promomats.test` | Design (Internal) |
| Cody ContentCreator | `contentcreator@promomats.test` | Content Creator (Int/Ext) |
| Amy ContentAgency | `contentagency@promomats.test` | Content Agency |

### 4b. Himalaya PromoMats team
Real people from the PromoMats brief, wired into the PromoMats workflows. Password
`welcome`, forced change on first login.

| Name | Email | Role(s) |
|---|---|---|
| Monika | `monika.pant@himalayawellness.com` | Document Owner |
| Rathna | `rathna.b@himalayawellness.com` | Content Manager |
| Jalba | `jalba.rc@himalayawellness.com` | Content Manager |
| Deepak | `deepak.db@himalayawellness.com` | Content Creator |
| Dhanu | `dhanu@himalayawellness.com` | Brand Manager, Regulatory |
| Bhupender | `bhupender.singh@himalayawellness.com` | TM/AGM |
| Prathamesh | `prathamesh.pandurkar@himalayawellness.com` | TM/AGM |
| Dr Hemanth | `dr.hemanth@himalayawellness.com` | TM/AGM |
| Ann | `ann.francis@himalayawellness.com` | Medical |
| Akansha | `akansha.thakur@himalayawellness.com` | Medical |
| Smriti | `smriti.singh@himalayawellness.com` | Regulatory |
| Aditi | `aditi.dasgupta@himalayawellness.com` | Agency, Legal |
| Kounnteya | `kounnteya.maurya@himalayawellness.com` | Legal |
| Devansh | `devansh.parikh@himalayawellness.com` | Chairperson |
| Rengarajan | `rengarajan.p@himalayawellness.com` | Chairperson |

### 4c. Scientific Publications team — **new**
Wired into the two new Scientific Publications workflows. Password `welcome`, forced
change on first login.

| Name | Email | Role(s) |
|---|---|---|
| Dr Anna Chackanackuzhy | `dr.anna.c@himalayawellness.com` | Content Developer, Project Lead |
| Dr Priyanka R | `dr.priyanka.r@himalayawellness.com` | Content Developer, Project Lead |
| Shruthi V Kumar | `shruthi.kumar@himalayawell.com` | Content Reviewer, Project Lead |
| Dr Chaitra G | `dr.chaitra.g@himalayawellness.com` | Content Reviewer, Project Lead |
| Harika GS | `harika.gs@himalayawellness.com` | Copy Editor, Proofing Editor, Project Lead |
| Shruthi Murali | `shruthi.murali@himalayawellness.com` | Copy Editor, Proofing Editor, Project Lead |
| Dayanand Rao | `dayanand.rao@himalayawellness.com` | Graphic Designer |
| Santhosh G | `santosh.g@himalayawellness.com` | Graphic Designer |
| Monesh NP | `monesh.np@himalayawellness.com` | Graphic Designer |
| Dr Jayashree Keshav | `dr.jayashree@himalayawellness.com` | AGM – Scientific Publications, Line Manager |
| Dr Vijendra | `dr.vijendra@himalayawellness.com` | Head – Regulatory Affairs |
| Julie Buragohain | `julie.buragohain@himalayawellness.com` | Head – Legal |
| Swaroop Mahesh | `swaroop.mahesh@himalayawellness.com` | Approver – Legal |

> **One address to confirm:** Shruthi V Kumar is on **`himalayawell.com`**, everyone
> else on `himalayawellness.com` — that's what the source document showed. Tell us if
> it should be corrected.

---

## 5. Workflows configured

| # | Workflow | Stages | Status |
|---|---|---|---|
| 1 | Pharma Workflow 1 (new promotional material) | 10 | existing |
| 2 | Pharma Workflow 2 (agency-originated) | 11 | existing |
| 3 | Pharma Workflow 3 (fast path — single TM/AGM sign-off) | 1 | existing |
| 4 | PromoMats Workflow (Word / Video / PPT) | Content Manager → TM/AGM → parallel MLR (Medical / Regulatory / Legal) → Chairperson | existing |
| 5 | PromoMats Workflow (PDF / JPG / GIF) | as above, PDF path | existing |
| 6 | **Scientific Publications – Workflow 1 (Full Proof Cycle)** | 15 — Content Review → Copy Editing → Proof Zero → Proof One → Proof Two (Project Lead ∥ AGM) → Proof Three (Project Lead ∥ Proofreading) → Proof Four review (Regulatory ∥ Legal ∥ Document Owner) → Proof Four Feedback → Proof Five → Check Pre-MP → Check MP / Approved for Distribution | **ADDED** |
| 7 | **Scientific Publications – Workflow 2 (Line Manager)** | 1 — single Line Manager approval before production | **ADDED** |

Workflows 6 and 7 are tagged to a **“Scientific Publications” department**, so a
department admin for that team can maintain them without touching anything else.

The 12 Scientific Publications titles (Probe, Capsule, Himalaya Livline, Himalaya
Infoline, Pediritz, Alloveda, Alloveda Hindi, Evecare, Asian Journal of Obstetrics &
Gynecology Practice, Confido, All About Pets, Vet Info-H) are loaded as **Projects**
so each title's documents group together.

---

## 6. New in this build

- **Scientific Publications team** — its own people (section 4c), its own two
  workflows (section 5), and its titles as Projects.
- **Department admins** — scoped administration, as described in section 2.
- **Per-document flow customisation** — on workflows where the admin enables it, the
  person uploading a document can send any stage to one named person, and can add /
  remove / reorder stages **for that one document** without changing the shared
  workflow.
- **Editing access toggle** — a document owner can open editing of a document to
  anyone in their department (metadata + new versions). Deciding to submit a version
  into the workflow always stays with the owner.

---

## 7. What the system does (baseline)

- **Upload → workflow → sign-off.** Any file type / size. Each document runs an
  admin-configured chain of stages; at each stage the approver records **Approved /
  Approved with Changes / Not Approved** (a comment is required for anything but a
  clean approve). AwC / NA parks the document with the owner to upload a revised
  version and resubmit — the workflow resumes at the stage that sent it back, not
  from the start.
- **Parallel stages.** Stages like MLR (Medical / Regulatory / Legal) or SciPub's
  Proof Four review run concurrently; the document waits for all of them.
- **Versioning & audit.** Every upload is an immutable version with full history;
  every decision is permanently logged with an electronic signature. A one-click
  **Document History Report** is print-ready for inspection.
- **Comments & PDF annotation.** A general thread plus click-to-pin comments on a PDF
  page preview, with replies and notifications.
- **Claims & Content Modules.** Reusable pre-approved statements with references and
  “Where Used”; bundle several into a module and insert in one action.
- **Library, search, dashboards.** Status / category filters, combined document +
  claim search, approval-rate and aging / expiry dashboards, overdue-approval flags
  with reminder emails.
- **Optional AI** (off until an API key is added): compliance pre-check, claim
  suggestions, revision drafts, natural-language search.

---

## 8. Please review and reply with

1. Any wrong or missing **names, emails, or role assignments** in section 4.
2. Whether **Workflow 1 / Workflow 2** stage order and approvers match how the
   Scientific Publications team actually works (section 5).
3. The **Shruthi V Kumar** email-domain question in section 4c.
4. Who should be the **department admin(s)** for each team.
5. Anything else you'd like changed or added.

---

## 9. Notes

- Outbound email is off until **Admin → Mail Settings** is configured — until then
  notification emails are logged, not sent. First-login password prompts still work.
- This is a demo / UAT build. For production the named accounts should move back to
  reset-link-only activation (no shared password).

Thanks — looking forward to your feedback.

Best regards,
[your name]
[title / company]
