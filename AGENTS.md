# QuizMyClass Agent Guide

## Project intent

QuizMyClass is a mobile-first, multi-tenant quiz web application for high-school
teachers. A Google-authenticated user creates a room, students collaborate on
questions while the room is in edit mode, and the owner activates an immutable
quiz revision for students to take.

The application must be easy to deploy as plain PHP on modest hosting. It does
not use a JavaScript build chain or package manager at runtime.

## Non-negotiable architecture

- Backend: PHP 8.2+ with a deliberately small HTTP API.
- Database: SQLite with WAL mode, foreign keys, short transactions, and
  parameterized queries.
- Frontend: version-pinned, locally committed Framework7 assets and its
  built-in Dom7 utilities.
- Rendering: PHP and static HTML/CSS/JavaScript; no SPA framework or bundler.
- Deployment: side-by-side development and live directories, each with its own
  secrets, writable data directory, and database.
- Public room URL: `/q/<room-slug>`, with role-aware content for the signed-in
  user.
- Admin console: a separate Apache `.htaccess`-protected directory. Access is
  not replaced by Google sign-in.
- No production dependency on a CDN or a Node build step.

## Product rules

### Identity and room roles

- Google sign-in identifies students and room creators.
- Use the Google subject as the stable user identifier. Store name and email as
  profile data, not as ownership keys.
- The user who creates a room becomes its owner/teacher.
- A separate deployment super-admin uses `.htaccess` authentication rather than
  Google sign-in.
- Domain access is controlled by an environment-level Google Workspace
  allowlist. Development may allow approved personal accounts.

### Collaboration

- In edit mode, students can create and edit only their own questions.
- The room owner can edit, reject, hide, reorder, or publish any question.
- Students may read other draft questions, but correct-answer configuration is
  visible only to the question author and room owner.
- A room can activate only when its validation rules and minimum approval
  requirements are satisfied.

### Quiz lifecycle

- Core states are `editing`, `active`, and `closed`.
- Activation creates an immutable revision of settings and questions.
- Deactivation does not delete attempts; every attempt is associated with its
  exact revision.
- Active-revision content cannot be changed in place. Start a new editable
  revision when changes are needed.

### Attempts and results

- Attempts may be limited or repeatable by room settings.
- Each submission is a separate attempt; the best score can be retained as the
  student's primary result.
- Rooms may have open/close times and per-attempt duration limits.
- Refreshing preserves an unsubmitted attempt.
- Students can see only their own attempts. Teachers can see individual
  responses, attempt history, best scores, and item analysis.
- Feedback visibility is configurable by room and may be immediate, after
  reveal, or never.

## Engineering rules

- Treat every user-supplied value as untrusted, including slugs, question
  prompts, answers, CSV content, and admin search/filter input.
- Use server-side authorization for every action. Client-side role checks only
  improve the UI and are not security controls.
- Sanitize the limited rich-text/linked-image HTML allowed in prompts with an
  explicit allowlist. Never save or render arbitrary HTML unsanitized.
- Keep secrets and environment configuration outside the public web root.
- Use CSRF protection for state-changing browser requests, secure cookies,
  output escaping, strict content validation, and session rotation on sign-in.
- Do not log OAuth tokens, secrets, or unnecessary student PII.
- Keep database migration and backup behavior explicit and testable.
- Preserve direct, inspectable PHP and JavaScript code. Avoid clever
  abstractions that make a small hosted deployment difficult to debug.

## UI rules

- Design mobile-first and keep frequent actions reachable one-handed.
- Prefer native Framework7 components and styles; add custom CSS only where a
  functional or accessibility need cannot be met by Framework7.
- Separate HTML pages are acceptable when they are clearer than an SPA route.
- Use Dom7 rather than a second client-side HTTP or UI library.
- Meet the selected v1 accessibility baseline: keyboard navigation, visible
  focus, screen-reader labels/status, WCAG AA contrast, and no color-only
  correctness signals.
- English is the v1 UI language. Keep interface copy centralized enough to
  localize later.

## Validation

Before considering a change complete:

1. Run the smallest relevant PHP lint and automated test set.
2. Exercise the affected permission boundaries with both owner and student
   accounts.
3. Verify mobile layouts at narrow viewport widths.
4. Check invalid and malicious inputs, not only the happy path.
5. Confirm that schema changes have a repeatable upgrade path.
6. Confirm that secrets and writable data remain outside the public root.

## Scope discipline

- Do not add a build tool, frontend framework, hosted database, or deployment
  service unless the requirements are explicitly changed.
- Do not add account passwords, social identity providers, file uploads, public
  leaderboards, or non-auto-graded question types during v1 without approval.
- Keep extension points clear, but implement only the currently required
  behavior.
