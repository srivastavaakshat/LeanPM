# Going live on Hostinger: step by step

This guide takes LeanPM from GitHub to **https://leanpm.houseofjugaad.in** on the Hostinger **Business** plan.

**Time:** about 40 minutes.
**Tools:** hPanel (hpanel.hostinger.com), GitHub (github.com/srivastavaakshat/LeanPM) and a password manager.

```
GitHub repo (code only)  ──deploy──▶  leanpm subdomain folder  ◀──  config/local.php (secrets, created once on the server)
                                              │
                                              ├── MariaDB database (organizations, tasks, comments …)
                                              └── storage/uploads (task files)
```

- **Code** comes from GitHub on every deploy.
- **Secrets** (the database password) live only in `config/local.php` on the server, never in GitHub.
- **Data** (database and uploaded files) lives only on Hostinger. Deploys never touch it.

Keep a note open for these values:

| Value | Example | From |
|---|---|---|
| Subdomain folder | `public_html/leanpm` | Step 1 |
| DB name | `u123456789_leanpm` | Step 3 |
| DB user | `u123456789_lpmuser` | Step 3 |
| DB password | long random string | Step 3 (password manager) |
| SSH command | `ssh -p 65002 u123456789@123.45.67.89` | Step 5 |

---

## Step 1: Create the subdomain

hPanel → **Websites** → **Manage** on `houseofjugaad.in` → **Domains → Subdomains**

1. Type `leanpm` and create it.
   - `houseofjugaad.in` has to be a website on your Hostinger account for this. If it's registered somewhere else, either add it as a website in hPanel, or, at your domain registrar, add an **A record** for `leanpm` pointing to your Hostinger server's IP (hPanel shows it under Websites → Dashboard).
2. Note its folder, usually `public_html/leanpm`. Use that folder wherever this guide says “the leanpm folder”.

## Step 2: PHP settings

hPanel → **Advanced → PHP Configuration** (these apply to the whole website, including the main site)

1. **PHP version:** 8.3.
2. **PHP extensions:** make sure `pdo_mysql`, `mbstring`, `fileinfo`, `dom` and `openssl` are ticked.
3. **PHP options:**
   - `upload_max_filesize` → **25M** (the per-file limit; change `upload_max_mb` in `config/local.php` to match if you pick another value)
   - `post_max_size` → **100M** (several files in one comment)
   - `memory_limit` → **256M**
   - `max_execution_time` → **60**

## Step 3: Create the database

hPanel → **Databases → Management**

1. Database name `leanpm`, username `lpmuser`, click **Generate** for the password and save it in your password manager.
2. Click **Create**, then copy the **full** names (with the `u…_` prefix) into your note. The host is always `localhost`.

## Step 4: SSL

hPanel → **Security → SSL** → install the free SSL for `leanpm.houseofjugaad.in`. Wait until it says **Active** (5–15 minutes). The app forces https by itself.

## Step 5: Turn on SSH

hPanel → **Advanced → SSH Access** → **Enable**, and copy the SSH command into your note.

## Step 6: Connect GitHub and deploy

### 6a. Read-only deploy key

1. hPanel → **Advanced → Git** → copy the **SSH key** at the top.
2. GitHub → **srivastavaakshat/LeanPM → Settings → Deploy keys → Add deploy key**. Title `Hostinger`, paste the key, leave **Allow write access** unticked.

### 6b. Empty the leanpm folder

File Manager → open the leanpm folder → delete anything inside (a new subdomain usually only has `default.php`). Hostinger's Git deploy only works into an empty folder.

### 6c. Add the repository

hPanel → **Advanced → Git → Create a new repository**

- **Repository:** `git@github.com:srivastavaakshat/LeanPM.git`
- **Branch:** `main`
- **Directory:** `leanpm` (the subdomain's folder inside `public_html`)

Click **Create**, then **Deploy**. The leanpm folder should now contain `app/`, `bin/`, `config/`, `database/`, `public/`, `storage/` and `.htaccess`.

### 6d. Auto-deploy on every push (recommended)

1. hPanel → Git → **Auto deployment** on the repository row → copy the webhook URL.
2. GitHub → leanpm → **Settings → Webhooks → Add webhook**: paste it, content type `application/json`, “Just the push event”.

## Step 7: Configure and install (over SSH)

On your Mac, open Terminal and paste the SSH command from your note, then:

```bash
cd domains/houseofjugaad.in/public_html/leanpm
```

(Run `ls` if the path differs; you want the folder that contains `bin/`.)

Create the config file from the example:

```bash
cp config/local.example.php config/local.php
```

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

```bash
nano config/local.php
```

Paste the random string as `app_key`, and fill in the database name, user and password from your note. Save with **Ctrl+O, Enter**, then exit with **Ctrl+X**.

Create the tables and your Owner account:

```bash
php bin/install.php --owner-email=design@huntingcube.com --owner-name="Akshat Srivastava"
```

It prints your Owner password **once**. Save it in your password manager.

Make sure uploads are writable:

```bash
chmod -R 775 storage
```

## Step 8: Check it

| Open | Expect |
|---|---|
| `https://leanpm.houseofjugaad.in` | Sign-in page |
| `http://leanpm.houseofjugaad.in` | Redirects to https |
| `https://leanpm.houseofjugaad.in/config/local.php` | Forbidden |
| `https://leanpm.houseofjugaad.in/storage/` | Forbidden |

Sign in with the Owner account. You land on **Manage LeanPM**. Create your organization there, and enter **your own email** as the person who runs it if you want to work in it yourself (owners aren't added to organizations automatically).

Sign in, then **+ New organization** → `Sportskeeda` → go to **Members** → **+ Add member** for each designer. Each new member gets a temporary password to send them; they choose their own at first sign-in.

## Step 9: Email (invites, password resets, @mentions)

LeanPM sends these emails:
- **Invites:** when you add someone under Members, they get a link to set their password.
- **Password resets:** when you click **Reset password** on a member, or someone uses **Forgot your password?** on the sign-in page, they get a link to choose a new one.
- **@mentions:** when someone is tagged in a comment or description, they get an email. Each person can turn this off under **Your account**.

Until a mailbox is set up, nothing is sent. The Members page then shows the invite or reset link for you to copy and send yourself.

1. **Create a mailbox.** hPanel → **Emails** → on `houseofjugaad.in` create `leanpm@houseofjugaad.in` and set a password.
2. **Add it to the server config.** Over SSH, run `nano config/local.php` and add the `mail` block from `config/local.example.php` with that mailbox's password. Save.
3. **Deliverability.** hPanel → Emails → `houseofjugaad.in`: make sure **SPF**, **DKIM** and **DMARC** are set up (Hostinger offers one-click fixes). Without them, Gmail may put the emails in spam.
4. **Cron job (fallback).** The app sends right away, but a cron job retries anything that failed. hPanel → **Advanced → Cron Jobs** → Custom, every 5 minutes (`*/5 * * * *`):

```bash
/usr/bin/php /home/YOUR-USER/domains/houseofjugaad.in/public_html/leanpm/bin/send-emails.php
```

5. **Check it.** Over SSH, run this to see whether mail is configured and whether anything is stuck:

```bash
php bin/send-emails.php --status
```

Then @mention a teammate on a test task.

Until the mailbox is configured, emails aren't sent. They're written to `storage/logs/mail.log` instead.

## Step 10: Sign in with Google (optional)

This lets members sign in with their Google account. It only works for people you've already added under Members, and their Google email must match the email you added them with. Nobody can create an account by signing in with Google.

1. Go to **console.cloud.google.com**, signed in as yourself. At the top, create a project called `LeanPM`.
2. **APIs & Services → OAuth consent screen** (Google Auth Platform → Branding/Audience):
   - App name `LeanPM`. Support email: yours.
   - **Audience:** *External*. Then **Publish app**, so it works for everyone you add, not only test users. The app only asks for name and email, so Google doesn't need to review it.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID:**
   - Application type: **Web application**, name `LeanPM`.
   - **Authorized redirect URIs:** add
     `https://leanpm.houseofjugaad.in/auth/google/callback`
     (and `http://localhost:8090/auth/google/callback` if you also want it on your Mac).
   - Click **Create**, then copy the **Client ID** and **Client secret**.
4. Over SSH, run `nano config/local.php` and add the `google` block from `config/local.example.php` with those two values. Save.
   - To allow only company emails, set `'allowed_domains' => ['sportskeeda.com']`.
5. Open the sign-in page. A **Sign in with Google** button now appears.
   - Existing members can also connect Google under **Your account**. This is needed if their Google email differs from their LeanPM email.

---

## Everyday updates

1. Change code locally → commit and push in GitHub Desktop → the site updates (auto-deploy), or click **Deploy** in hPanel → Git.
2. If the change adds a file in `database/migrations/`, run `php bin/migrate.php` over SSH (or paste the file into phpMyAdmin → SQL).
3. Deploys never overwrite `config/local.php`, the database or `storage/uploads`.

## Backups

- hPanel → **Files → Backups** (daily on Business) covers files and the database.
- Any time: **Export reports → All time** gives you every task and comment as CSV.

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page / “Something went wrong” | Read `storage/logs/php-error.log` in File Manager. |
| 500 right after deploy | `config/local.php` missing or has a typo. |
| “Access denied for user” | DB name/user/password don't match Step 3 (use the full `u…_` names). |
| Uploads fail or say “too large” | Raise `upload_max_filesize` / `post_max_size` (Step 2); `chmod -R 775 storage`. |
| Git deploy “directory not empty” | Empty the leanpm folder and deploy again. |
| Forgot the Owner password | Over SSH: `php -r 'require "app/bootstrap.php"; App\Core\Database::query("UPDATE users SET password_hash=? WHERE email=?", [password_hash($argv[1], PASSWORD_DEFAULT), $argv[2]]);' 'NEW-PASSWORD' 'you@example.com'` (or use **Forgot your password?** once email works) |
