# Going live on Hostinger: step by step

This guide takes LeanPM from GitHub to **https://pm.houseofjugaad.com** on the Hostinger **Business** plan.

**Time:** about 40 minutes.
**Tools:** hPanel (hpanel.hostinger.com), GitHub (github.com/srivastavaakshat/leanpm) and a password manager.

```
GitHub repo (code only)  ──deploy──▶  pm subdomain folder  ◀──  config/local.php (secrets, created once on the server)
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
| Subdomain folder | `public_html/pm` | Step 1 |
| DB name | `u123456789_leanpm` | Step 3 |
| DB user | `u123456789_lpmuser` | Step 3 |
| DB password | long random string | Step 3 (password manager) |
| SSH command | `ssh -p 65002 u123456789@123.45.67.89` | Step 5 |

---

## Step 1: Create the subdomain

hPanel → **Websites** → **Manage** on `houseofjugaad.com` → **Domains → Subdomains**

1. Type `pm` and create it.
2. Note its folder, usually `public_html/pm`. Use that folder wherever this guide says “the pm folder”.

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

hPanel → **Security → SSL** → install the free SSL for `pm.houseofjugaad.com`. Wait until it says **Active** (5–15 minutes). The app forces https by itself.

## Step 5: Turn on SSH

hPanel → **Advanced → SSH Access** → **Enable**, and copy the SSH command into your note.

## Step 6: Connect GitHub and deploy

### 6a. Read-only deploy key

1. hPanel → **Advanced → Git** → copy the **SSH key** at the top.
2. GitHub → **srivastavaakshat/leanpm → Settings → Deploy keys → Add deploy key**. Title `Hostinger`, paste the key, leave **Allow write access** unticked.

### 6b. Empty the pm folder

File Manager → open the pm folder → delete anything inside (a new subdomain usually only has `default.php`). Hostinger's Git deploy only works into an empty folder.

### 6c. Add the repository

hPanel → **Advanced → Git → Create a new repository**

- **Repository:** `git@github.com:srivastavaakshat/leanpm.git`
- **Branch:** `main`
- **Directory:** `pm` (the subdomain's folder inside `public_html`)

Click **Create**, then **Deploy**. The pm folder should now contain `app/`, `bin/`, `config/`, `database/`, `public/`, `storage/` and `.htaccess`.

### 6d. Auto-deploy on every push (recommended)

1. hPanel → Git → **Auto deployment** on the repository row → copy the webhook URL.
2. GitHub → leanpm → **Settings → Webhooks → Add webhook**: paste it, content type `application/json`, “Just the push event”.

## Step 7: Configure and install (over SSH)

On your Mac, open Terminal and paste the SSH command from your note, then:

```bash
cd domains/houseofjugaad.com/public_html/pm
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
| `https://pm.houseofjugaad.com` | Sign-in page |
| `http://pm.houseofjugaad.com` | Redirects to https |
| `https://pm.houseofjugaad.com/config/local.php` | Forbidden |
| `https://pm.houseofjugaad.com/storage/` | Forbidden |

Sign in, then **+ New organization** → `Sportskeeda` → go to **Members** → **+ Add member** for each designer. Each new member gets a temporary password to send them; they choose their own at first sign-in.

## Step 9: @mention emails

When someone types `@Name` in a comment or a description, that person gets an email. Each person can turn this off under **Your account**.

1. **Create a mailbox.** hPanel → **Emails** → on `houseofjugaad.com` create `pm@houseofjugaad.com` and set a password.
2. **Add it to the server config.** Over SSH, run `nano config/local.php` and add the `mail` block from `config/local.example.php` with that mailbox's password. Save.
3. **Deliverability.** hPanel → Emails → `houseofjugaad.com`: make sure **SPF**, **DKIM** and **DMARC** are set up (Hostinger offers one-click fixes). Without them, Gmail may put the emails in spam.
4. **Cron job (fallback).** The app sends right away, but a cron job retries anything that failed. hPanel → **Advanced → Cron Jobs** → Custom, every 5 minutes (`*/5 * * * *`):

```bash
/usr/bin/php /home/YOUR-USER/domains/houseofjugaad.com/public_html/pm/bin/send-emails.php
```

5. **Check it.** Over SSH, run this to see whether mail is configured and whether anything is stuck:

```bash
php bin/send-emails.php --status
```

Then @mention a teammate on a test task.

Until the mailbox is configured, emails aren't sent. They're written to `storage/logs/mail.log` instead.

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
| Git deploy “directory not empty” | Empty the pm folder and deploy again. |
| Forgot the Owner password | Over SSH: `php -r 'require "app/bootstrap.php"; App\Core\Database::query("UPDATE users SET password_hash=? WHERE is_owner=1", [password_hash($argv[1], PASSWORD_DEFAULT)]);' 'NEW-PASSWORD'` |
