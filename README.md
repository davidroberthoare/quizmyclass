# QuizMyClass

QuizMyClass is a mobile-first collaborative quiz application for high-school
classrooms. Students can contribute questions to an editable room, while the
teacher who owns the room reviews the content, activates an immutable quiz
revision, and views the resulting attempts.

The first release is intentionally small and deployable: plain PHP, SQLite
with WAL, Framework7, Dom7, and no build or packaging step.

## Local foundation

Requirements are PHP 8.2 or newer with the `pdo_sqlite` and `sqlite3`
extensions. Google sign-in also requires `curl` and `openssl`. Apache needs
`mod_rewrite` and `mod_headers` for the supplied `.htaccess` rules.

Install the development environment:

```bash
php scripts/install.php
php -S 127.0.0.1:8080 -t public public/router.php
```

The installer creates the ignored `config/local.php`, generates a random
session secret, creates the writable SQLite database, and applies migrations.
Keep `config/local.php` and `data/` outside the public document root. The
included `public/` directory is the only web-exposed directory.

Operational commands:

```bash
php scripts/migrate.php
php scripts/backup.php --keep=14
php scripts/check-deployment.php
php -d zend.assertions=1 -d assert.exception=1 tests/run.php
php tests/http.php
curl http://127.0.0.1:8080/health.php
```

Google sign-in requests only the `openid email profile` scopes. Add the web
application client ID, client secret, and exact callback URL to
`config/local.php`; that file remains git-ignored. Configure `redirect_uri` to
point to `/auth/google/callback.php` at the same origin as `base_url`.

For local testing:

1. In Google Cloud Console, select or create a project and configure the
   OAuth consent screen. Select Internal if everyone uses the same Google
   Workspace; otherwise select External and add approved test users.
2. Create an OAuth 2.0 Client ID with application type **Web application**.
3. Add `http://127.0.0.1:8080` as an authorized JavaScript origin. This app
   does not use Google JavaScript libraries, but the origin is still useful
   when reviewing the web client configuration.
4. Add
   `http://127.0.0.1:8080/auth/google/callback.php` as an authorized redirect
   URI. If you use `localhost` in the browser, register and configure the
   matching `localhost` callback instead; the values must match exactly.
5. Copy the generated client ID and client secret into the `google` section
   of git-ignored `config/local.php`. Do not paste the secret into an issue,
   commit, or chat.
6. Add at least your exact email to `access.allowed_test_emails`, or a Google
   Workspace domain to `access.allowed_domains`, then run
   `php scripts/migrate.php`.
7. Start the app with
   `php -S 127.0.0.1:8080 -t public public/router.php` and choose **Sign in
   with Google**.

Access is denied unless the verified Google email matches either
`access.allowed_domains` or `access.allowed_test_emails`. Production
deployments should use the Google Workspace domain allowlist; exact test
addresses are intended for development.

The checked-in Framework7 bundle is pinned to version 8.3.4. The application
uses its bundled Dom7 utilities and requires no JavaScript package manager.

## Collaborative question editing

In an editable room, signed-in students can add and edit only their own
questions. The room owner can edit any question, approve or reject it, hide it,
and change its order. Correct answers and explanations are shown only to the
question author and room owner.

The editor supports multiple choice, multiple select, true/false, short text
with multiple accepted answers, and numeric questions with tolerance. Prompt
and explanation HTML is restricted to an explicit allowlist; linked images and
links must use HTTPS. Room and per-student question caps are configurable by
the room owner.

Question changes are audited and remain editable only while the room is in
`editing` state. Database migration `003_questions.sql` is repeatable through
`php scripts/migrate.php`.

## Core workflow

1. A Google-signed-in teacher creates a room and chooses a URL-safe slug.
2. The room is available at `/q/<room-slug>`.
3. In **edit mode**, Google-signed-in students add and edit their own
   questions. The teacher can manage every question and approve the content
   for release.
4. The teacher activates the room. Activation validates the quiz and freezes
   the current questions and settings into an immutable revision.
5. In **active mode**, students use Google sign-in to take the quiz. Timed and
   repeat attempts are supported, and every submission is kept separately.
6. The student sees permitted feedback; the teacher sees the best score,
   attempts, per-question details, and item analysis.

## Status

| Area | State |
| --- | --- |
| Sign-in, quizzes (rooms), roles | Done |
| Collaborative question editing and moderation | Done |
| Quiz settings, activation, immutable revisions, close/reopen | Done |
| Taking a quiz: attempts, timer, resume, shuffling, grading, feedback | Done |
| Teacher results: students, attempts, item analysis, CSV, print, reset | Done |
| Super-admin console (`.htaccess`-protected) | Done |
| Deployment guide and hardening pass | Done (see `docs/DEPLOYMENT.md`) |

User-facing text says "quiz"; the database and code still use `rooms` internally.

## Super-admin

The console lives at `/admin/` and is protected by Apache Basic auth, not Google
sign-in. It shows deployment status, searches every quiz and user, lets you
inspect a quiz's settings, versions, questions and audit trail, deactivate or
delete a quiz (deleting requires typing its link), and block or unblock users.
Every action is written to the audit log. Student attempt contents are not shown
there, only aggregate counts.

Setup on Apache:

1. Run `php scripts/admin-password.php` and choose a password of 12+ characters.
2. Save the printed line in a `.htpasswd` file **outside** the web root.
3. Put that file's absolute path in `AuthUserFile` in `public/admin/.htaccess`.

Until step 3 is done Apache returns a 500 error for `/admin/`, so the console
stays closed. The application also refuses requests that Apache has not
authenticated. For local development with `php -S` (which ignores `.htaccess`),
add `'admin' => ['dev_password_hash' => '...']` to `config/local.php` using the
hash the script prints, then sign in as user `admin`. That fallback only works
when `environment` is `development`.

After updating the code, run `php scripts/migrate.php`. Until you do, every page
answers with a clear "needs migration" message instead of a database error.

## Version 1 scope

### Users and access

- Google sign-in for room creators, teachers, and students.
- Google Workspace domain allowlist, with approved test accounts in
  development.
- One canonical room URL whose behavior depends on the signed-in user's role.
- Room ownership for the user who creates a room.
- Deployment-wide super-admin console protected by Apache `.htaccess`; it
  does not require Google sign-in.

### Question collaboration

- Multiple-choice questions with one answer.
- Multiple-select questions.
- True/false questions.
- Short-text auto-graded questions with multiple accepted answers.
- Numeric auto-graded questions with optional tolerance.
- Rich-text prompt formatting, color, emphasis, and linked images through a
  sanitized HTML allowlist.
- Optional explanation and configurable points.
- Student ownership rules, teacher moderation, rejection/hiding, and ordering.
- Configurable per-student question cap and room question-count limits.

No user file-upload flow is planned for version 1. Prompt images are links to
external images rather than files stored by QuizMyClass.

### Quiz configuration

- Editing, active, and closed lifecycle states.
- Open and close dates.
- Per-attempt duration.
- Configurable maximum attempts.
- Highest-score primary result.
- Optional randomization of question and answer order.
- Configurable score, correctness, correct-answer, and explanation feedback.
- Immutable quiz revisions on activation.

### Results

- Student-only attempt visibility.
- Teacher views for live counts, best scores, attempt history, and responses.
- Per-question item analysis.
- CSV and print-friendly result exports.
- Controlled result reset operations with audit records.

### Super-admin

- Search and inspect rooms.
- View room settings, content, and aggregate activity.
- Deactivate or delete rooms.
- Moderate rooms and users.
- Inspect deployment-level state.

## Architecture outline

### Frontend

- Mobile-first Framework7 interface.
- Framework7 and Dom7 JavaScript committed locally at a pinned version.
- Small page-specific scripts that call the PHP API.
- Separate HTML/PHP pages where they provide a clearer flow than a strict SPA.
- Native Framework7 styling preferred over custom CSS.

Primary screens:

- Room creation and sign-in
- Room landing
- Collaborative question browser/editor
- Teacher question moderation and room settings
- Active quiz and resumed attempt
- Student attempt feedback
- Teacher results and exports
- Super-admin console

### Backend

- PHP 8.2+ API endpoints organized by resource and permission.
- Server-side Google token/session validation.
- Role and ownership authorization on every protected operation.
- CSRF protection for browser-driven mutations.
- Explicit validation and JSON error responses.
- Small service/repository boundaries where they reduce duplication without
  obscuring deployment and debugging.

Expected resource areas:

- Session and Google authentication
- Rooms and slugs
- Room settings and activation/revisions
- Questions and moderation
- Attempts, answers, grading, and feedback
- Results and exports
- Super-admin operations
- Audit records

### Data

SQLite runs in WAL mode with foreign keys enabled. The database is stored in a
writable directory outside the public web root.

Logical entities will include:

- Users
- Rooms and memberships/roles
- Questions and question revisions
- Quiz settings and activation snapshots
- Attempts and individual responses
- Audit events
- Result resets and reveal state

All timestamps are stored in UTC and rendered using the deployment's
configured time zone. Every attempt points to the exact active quiz revision
used to create it.

## Security and privacy baseline

- HTTPS in development preview where practical and always in live.
- HTTP-only, secure, same-site session cookies.
- Google OAuth credentials and session secrets isolated per environment.
- `.htaccess`-protected super-admin area, separate from Google auth.
- Parameterized SQL only.
- Output escaping at every template boundary.
- Sanitized, explicitly allowlisted rich text and linked-image markup.
- CSRF protection, request validation, and server-side authorization.
- No OAuth tokens or unnecessary student PII in logs.
- No public leaderboard.
- Auditing of activation, moderation, settings, result resets, and super-admin
  actions.

## Deployment model

Development and live installations use separate folders, configuration/secrets,
databases, and writable data directories. Each environment is expected to
contain:

```text
public/             # Apache/HTTPS document root
app/                # PHP application code, outside public root
config/             # Environment-specific configuration, outside public root
data/               # SQLite database and backups, outside public root
```

The exact structure will be finalized before implementation. Production does
not require Node, npm, a frontend compiler, or a PHP package manager.

## Roadmap

### Phase 0: Final specification

- Finish the interview for question behavior, grading edge cases, privacy,
  retention, and acceptance criteria.
- Record API endpoint and database invariants.
- Confirm the canonical development/live URL and Google OAuth redirect setup.

### Phase 1: Deployable foundation

- Establish the public/app/config/data layout.
- Add environment loading and per-environment secrets.
- Initialize SQLite with WAL, foreign keys, migrations, and backup hooks.
- Add the Framework7/ Dom7 vendor assets and a mobile shell.
- Add health checks and development-only diagnostics.

### Phase 2: Identity and rooms

- Implement Google sign-in and server-side session handling.
- Implement allowed-domain enforcement and profile synchronization.
- Add room creation, slug collision checking, and role-aware room routing.
- Add audit events for room changes.

### Phase 3: Collaborative editing

- Implement question CRUD and student ownership authorization.
- Add sanitized rich text and linked images.
- Implement teacher moderation, approval, hiding, and ordering.
- Add room limits and complete question validation.

### Phase 4: Activation and revisioning

- Implement teacher room settings.
- Add quiz validation and immutable activation snapshots.
- Add editing/active/closed transitions and reopening with new revisions.
- Prevent mutations to activated content.

### Phase 5: Taking a quiz

- Implement resumable attempts, deadlines, timers, and maximum attempts.
- Add optional question/answer randomization.
- Implement all auto-grading rules.
- Record answers and attempts by quiz revision.
- Add configurable feedback and result reveal behavior.

### Phase 6: Results and exports

- Add student attempt history and feedback screens.
- Add teacher best-score, attempt-detail, and item-analysis views.
- Add CSV and print output.
- Add audited result reset controls.

### Phase 7: Super-admin and operations

- Add the `.htaccess`-protected administration area.
- Add room search, inspection, moderation, deactivation, and deletion.
- Add database backup/restore documentation and retention controls.
- Add production deployment and upgrade instructions.

### Phase 8: Acceptance and release

- Test multi-user permissions and cross-room isolation.
- Verify timer and concurrency behavior on the target host.
- Run accessibility and mobile viewport checks.
- Exercise malformed/rich-text inputs and result privacy.
- Complete a clean dev-to-live deployment rehearsal.

## Project status

The deployable foundation is implemented: environment configuration, SQLite
WAL and foreign-key enforcement, repeatable migrations, consistent backups,
local health checks, and an accessible mobile shell are in place. Google
sign-in, allowlisted identity, room creation, role-aware routing, and complete
question collaboration are implemented. Activation, immutable revisions,
attempts, and results remain to be implemented.
