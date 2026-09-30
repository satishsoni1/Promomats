# VODO mobile (Flutter)

Review and act on assigned PromoMats tasks from a phone — the same workflow,
permissions and data as the web app, through its API (`routes/api.php`).

What it does:

- Sign in with the same email and password as the web app.
- **Active Workflow** tabs: items waiting for your review, revisions of your own
  jobs, and Design Team work — with due / overdue flags. Pull down to refresh.
- Open a job: see the creative full screen (PDF pages or zoomable image), where it
  is in the workflow and who has it, the review history, and all comments —
  including highlighted text and suggested replacement text from reviewers.
- Add comments, and record your decision (Approved / Approved with changes /
  Not approved, or *Submit draft* on a draft stage). Brand Managers see the job
  but can't approve or reject, as on the web.

## Run it

```bash
cd mobile
flutter pub get
# Android emulator talking to `php artisan serve` on the same PC:
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

The server address can also be changed on the sign-in screen (**Server settings**).

## Build for release

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://<your-vodo-server>
flutter build ipa --release --dart-define=API_BASE_URL=https://<your-vodo-server>   # on a Mac
```

Release builds only talk to HTTPS servers. Before publishing to the Play Store /
App Store, set the app id (`com.globalspace.vodo_mobile`), signing keys and the
launcher icon.

## Server side

- API: `routes/api.php`, `app/Http/Controllers/Api/MobileController.php`.
- Sign-in issues a bearer token (stored hashed in `api_tokens`, valid
  `MOBILE_TOKEN_DAYS`, default 30 days). Signing out revokes it.
- Files are served through 30-minute signed links, so the viewer never needs the
  token.
