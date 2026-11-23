# VODO mobile (Flutter)

The VODO PromoMats web app on a phone — the same workflow, permissions and data,
through its API (`routes/api.php`). Every action runs the web app's own controller,
so the phone follows exactly the same rules as the browser.

What it does:

- **Splash and sign-in**: VODO branding, the same email and password as the web,
  *Forgot password* (opens the web reset page) and *Server settings*.
- **Active Workflow**, with the web's tabs:
  - **Needs my action**: approvals, your jobs that are back with you, and team work.
  - **My jobs**: who holds each job and when it's due.
  - **Design Team** and **Content Agency** queues: take, assign, upload.
  - **Brand Managers**: one view per Brand Manager.
- **New job**, the web upload form:
  - Print / Digital collateral types.
  - Workflow suggested from Brand + Type.
  - The stakeholder and due hours for each stage. Unticked MLR tracks are skipped.
  - Dates.
  - A placeholder, and who prepares the creative.
  - The file and reference documents.
- **The job page**:
  - The stepper: parallel MLR, skipped tracks, and stages back with the Brand Manager.
  - The creative, shown full screen with numbered comment pins.
  - **Comment mode**: tap the page to pin a comment.
  - **Decisions**: draft alignment, and the offline Chairperson sign-off with the signed copy.
  - **Revise & resubmit**: do it yourself, or send it to the Content Agency or Design Team.
  - **Draft aligned**: assign the creative, then start the review.
  - **Stakeholders**: reassign people and change due dates.
  - Versions (upload new) and reference documents (attach).
  - Review history.
  - **Comments**: drafts until submitted, locked once submitted; edit, reply and resolve.
  - Lifecycle, e.g. *Release for Distribution*.
- **Library → Jobs**, with search and All / In progress / Approved filters.
- **Notifications** (with an unread badge), **Helpdesk** tickets, and **More**
  (profile, open the web version, sign out).

Word files: in-browser tracked changes, comments and highlights use the web editor.
The app opens the job on the web, or opens the file in Word on the phone so you can
upload the result as a new version.

## Run it

```bash
cd mobile
flutter pub get
# Android emulator talking to `php artisan serve` on the same PC:
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

The server address can also be changed on the sign-in screen (**Server settings**).

## Branding (logo, app icon, splash)

`assets/branding/` holds PNGs rendered from the web logo
(`public/images/vodo-logo.svg`). The app icon and native launch screens are
generated from them:

```bash
dart run flutter_launcher_icons        # Android + iOS app icons (adaptive on Android)
dart run flutter_native_splash:create  # Android (incl. 12+) + iOS launch screens
```

After the native launch screen, `SplashScreen` animates the logo, checks the saved
session, and opens Active Workflow, or sign-in if the session has expired.

## Build for release

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://<your-vodo-server>
flutter build ipa --release --dart-define=API_BASE_URL=https://<your-vodo-server>   # on a Mac
```

Release builds only talk to HTTPS servers. Before publishing to the Play Store or
App Store, set the app id (`com.globalspace.vodo_mobile`) and the signing keys.

## Server side

- **Reads:** `app/Http/Controllers/Api/MobileController.php` (session, Active
  Workflow, the job page) and `MobileCatalogController.php` (New job options,
  Library, notifications, helpdesk).
- **Actions:** `MobileWorkflowController.php`. It runs the web controllers, and
  `Concerns/RelaysWebActions` turns their redirects into JSON.
  `App\Http\Middleware\UseArraySession` gives each request a throwaway session.
- **Sign-in:** issues a bearer token, stored hashed in `api_tokens` and valid for
  `MOBILE_TOKEN_DAYS` (default 30 days). Signing out revokes it.
- **Files:** creatives and reference documents are served through 30-minute signed
  links, so the viewer never needs the token.
- **Tests:** `tests/Feature/MobileApiTest.php` and `MobileApiParityTest.php`
  (server), and `mobile/test/` (screens).
