# Deploying QuizMyClass

QuizMyClass is plain PHP plus SQLite. There is no build step and no package manager: you copy
the files, write one config file, run two scripts, and point Apache at `public/`.

## 1. Requirements

- PHP 8.2 or newer with `pdo_sqlite`, `sqlite3`, `curl`, `openssl`, `mbstring`, `dom`, `libxml`
- Apache 2.4 with `mod_rewrite`, `mod_headers`, and `AllowOverride All` for the site (so `.htaccess` works)
- HTTPS (Let's Encrypt is fine). Google sign-in and secure cookies require it
- Shell access for the scripts below (SSH, or your host's cron/terminal tool)

## 2. Layout: one codebase per environment

Run development and live as **two separate directories**, each with its own config, database,
sessions and backups. They share nothing, so a mistake in dev cannot touch live data.

```
/home/you/quizmyclass-dev/     DocumentRoot -> quizmyclass-dev/public    (dev.quiz.example.org)
/home/you/quizmyclass-live/    DocumentRoot -> quizmyclass-live/public   (quiz.example.org)
/home/you/quizmyclass-secrets/.htpasswd   admin password file (outside both)
```

Inside each:

```
app/  config/  data/  scripts/  tests/  docs/      <- never served
public/                                            <- the only web-exposed folder
```

**The DocumentRoot must be `public/`.** If your host forces the DocumentRoot to be the project
folder, the root `.htaccess` blocks downloads of code, SQL and databases as a safety net, but you
should still ask your host to point at `public/`, because the app's routing assumes it.

Apache virtual host sketch:

```apache
<VirtualHost *:443>
    ServerName quiz.example.org
    DocumentRoot /home/you/quizmyclass-live/public
    <Directory /home/you/quizmyclass-live/public>
        AllowOverride All
        Require all granted
    </Directory>
    # SSLCertificateFile / SSLCertificateKeyFile ... (certbot manages these)
    # If TLS ends at a proxy and PHP does not see HTTPS, add:
    # Header always set Strict-Transport-Security "max-age=15552000"
</VirtualHost>
```

## 3. Google sign-in

1. In Google Cloud Console create a project and an **OAuth consent screen**.
   - Live (school Workspace): choose **Internal**, so only your domain can ever sign in.
   - Dev with personal accounts: choose **External** in Testing mode and add each tester.
2. Create an **OAuth client ID**, type *Web application*.
3. Add the **exact** redirect URI for that environment:
   `https://quiz.example.org/auth/google/callback.php`
   Dev and live need separate clients (or at least separate redirect URIs).
4. Copy the client ID and secret into that environment's `config/local.php`. Never commit them
   or paste them into chat or tickets.

The app asks Google only for `openid email profile`, requires a verified email, and then checks
the address against your allowlist.

## 4. First deployment

```bash
# copy the project to the server (git clone, or rsync without .git, data/, config/local.php)
cd /home/you/quizmyclass-live
cp config/local.example.php config/local.php
chmod 600 config/local.php
php -r 'echo bin2hex(random_bytes(32)), "\n";'    # paste into session_secret
$EDITOR config/local.php                           # environment, base_url, Google, allowed_domains, timezone

php scripts/migrate.php                            # creates data/quizmyclass.sqlite3
php scripts/check-deployment.php                   # must end with "Ready"
```

Make sure the web server user can write `data/` (and only the owner can read it):

```bash
chmod 700 data
chown -R www-data:www-data data     # use your host's PHP user; skip if PHP runs as you
```

### Admin console

```bash
php scripts/admin-password.php            # prints a line like  admin:$2y$10$...
mkdir -p /home/you/quizmyclass-secrets
$EDITOR /home/you/quizmyclass-secrets/.htpasswd       # paste the line
chmod 600 /home/you/quizmyclass-secrets/.htpasswd
```

Then edit `public/admin/.htaccess` and replace the `AuthUserFile` placeholder with
`/home/you/quizmyclass-secrets/.htpasswd`. Until you do, `/admin/` returns an error, which is the
safe state. Visit `https://quiz.example.org/admin/` and sign in with the Apache credentials
(this is separate from Google).

### Verify

- `https://quiz.example.org/health` returns `{"status":"ok"}`
- `https://quiz.example.org/config/local.php`, `/data/...`, `/app/...` all return 403 or 404
- Sign in with a Workspace account, create a quiz, add a question, activate, and take it
  from a second account

## 5. Updating

```bash
cd /home/you/quizmyclass-live
php scripts/backup.php                   # always back up first
# deploy new files (git pull / rsync) -- keep config/local.php and data/ untouched
php scripts/migrate.php
php scripts/check-deployment.php
```

If you forget `migrate.php`, every page shows a "needs migration" message instead of crashing, and
`/admin/` flags the pending migration. Migrations only ever add; they never drop student data.

**Rollback:** restore the previous files and, if a migration ran, restore the backup taken above
(`cp data/backups/quizmyclass-YYYYMMDD-HHMMSS.sqlite3 data/quizmyclass.sqlite3` while the site is
idle). Backups are created with SQLite's `VACUUM INTO`, which is consistent even while the site runs.

## 6. Backups

Schedule a daily backup that keeps the newest 14 copies:

```cron
15 2 * * *  cd /home/you/quizmyclass-live && /usr/bin/php scripts/backup.php --keep=14 >> data/backup.log 2>&1
```

Backups contain student names and emails. They live in `data/backups/` (mode 700, files 600).
**A backup on the same disk is not a disaster backup**: periodically copy the folder somewhere
else (another machine, or your school's storage) using a method you trust.

## 7. Development environment

```bash
cp config/local.example.php config/local.php     # set environment => development, debug => true
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public public/router.php
```

For the admin console under `php -S` (which ignores `.htaccess`), run
`php scripts/admin-password.php` and add the `'admin' => ['dev_password_hash' => ...]` line it
prints; sign in as `admin`. This fallback is ignored unless `environment` is `development`.

## 8. Testing

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/run.php   # logic, database, grading, lifecycle
php tests/http.php                                             # permissions over real HTTP, throwaway DB
```

Run both before every release. `tests/http.php` starts its own temporary server and database and
never touches your real data.

## 9. Security checklist

- [ ] `php scripts/check-deployment.php` ends with **Ready** and no unexpected warnings
- [ ] DocumentRoot is `public/`; `config/`, `data/`, `app/` are not reachable by URL
- [ ] `environment` is `production`, `debug` is `false`, `base_url` is HTTPS
- [ ] Google consent screen is **Internal** and `allowed_domains` lists your domain
- [ ] `config/local.php` is `chmod 600` and in no repository or chat
- [ ] Admin password file is outside the web root; the `.htaccess` path is set
- [ ] Daily backups run, and an off-machine copy exists
- [ ] Session cookies are `Secure; HttpOnly; SameSite=Lax` (automatic when `base_url` is HTTPS)

## 10. Known limits (by design for v1)

- **Prompt images are external links.** Anyone viewing a question loads the image from the linked
  site, which can reveal their IP address to that site. Students can author prompts, so a teacher
  should review images when approving questions. There is no file upload.
- **No sign-in or request rate limiting.** Google handles authentication; the app limits attempts
  per student and answers per attempt. Put a rate limit at the web server or CDN if you need one.
- **Students are not notified.** There is no email; share quiz links yourself.
- **One SQLite file.** It is designed for classroom-sized loads (a few classes at once). It has
  not been load-tested, so try a realistic rehearsal before a high-stakes quiz, and it is not built
  for many busy schools on one server.
- **Closing or reopening a quiz submits attempts in progress** as they stand.
