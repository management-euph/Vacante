# Production deploys (CI/CD)

How the travel add-ons get from `main` to a live CS-Cart store, safely and the
same way every time. `docs/INSTALL.md` covers what the store needs and how to
install and configure the add-ons; this file covers delivering the code.

```
pull request ──► CI (PHPStan, tests, lint…) ──► merge to main
                                                    │
        Actions → Deploy → Run workflow (you choose when; production waits for approval)
                                                    │
     CI again on that commit ─► backup (files + database) ─► rsync --delete ─► clear cache ─► smoke test
```

| Piece | What it does |
|---|---|
| `.github/workflows/server-check.yml` | **Server check**: can GitHub reach the server over SSH, and does it have rsync, PHP 8.3, mysqldump, CS-Cart, write access? Changes nothing. |
| `.github/workflows/deploy.yml` | **Deploy**: CI on the exact commit, then the deploy, then the smoke test. A dry run unless *go* is ticked. |
| `scripts/deploy-addons.sh` | The deploy itself (also usable by hand). Copies only the add-ons' own folders, deletes what a release removed, backs up first. |
| `scripts/smoke-test.sh` | Storefront and admin login answer 200 with no PHP/Smarty error. |

Why rsync and not "Upload & install" or FTP: those only add or overwrite files.
A template a release deletes stays on the server and keeps rendering (the old
provider order blocks would show beside the new booking card). CS-Cart's own
SDK has no deploy command; its `addon:build_upgrade` (Upgrade Center packages)
is the alternative if SSH is ever impossible: see the end of this file.

## 1. What the server needs

The stores run on a **Hetzner VPS**; the same steps fit any Linux server with
SSH.

- **SSH login by key**, and `rsync`, `tar`, `gzip`, `mysqldump` (or
  `mariadb-dump`) and PHP **8.3+** on the command line. On a Debian/Ubuntu
  VPS: `apt install rsync` if the Server check says it is missing.
- SSH **not limited to some IP addresses** (in the Hetzner Cloud firewall or
  `ufw`): GitHub's runners use changing addresses from shared cloud ranges,
  and GitHub advises against allowing them by IP. If you must restrict it,
  see "If GitHub cannot reach the server".
- CS-Cart **4.19.1–4.21.2** (the `<max>` in each `addon.xml`), MySQL 8.0+ /
  MariaDB 10.6+, the `responsive` or `nova_theme` theme (`docs/INSTALL.md`,
  section 1). A newer CS-Cart refuses to install the add-ons until their
  `<max>` is raised, after testing that release in Docker.
- The SSH user's home directory **outside** the web root: backups are kept in
  `~/deploy-backups` (the deploy refuses a backup folder inside the store).

**Which user GitHub logs in as.** `root` works, but a key for root lets a
leaked secret do anything on the VPS. Safer: the user that owns the site's
files (a panel such as CloudPanel or Plesk creates one per site; see who owns
them with `stat -c %U /path/to/store/config.php`). It needs to write the
add-on folders and `var/cache`, and to read `config.local.php`; the Server
check verifies all of it.

## 2. Create the deploy key (once)

On your computer (PowerShell or WSL):

```bash
ssh-keygen -t ed25519 -C "github-deploy" -N "" -f deploy_key
```

- Add the content of `deploy_key.pub` to `~/.ssh/authorized_keys` of that user
  on the server.
- Check it works from your computer: `ssh -i deploy_key -p 22 user@host`.
- Optional but recommended, to pin the server: `ssh-keyscan -p 22 host`
  (its output is the secret `DEPLOY_KNOWN_HOSTS` below).

## 3. Set up GitHub (once)

**Repository secrets** — Settings → Secrets and variables → Actions →
*Repository secrets* (the names of the Hetzner tutorial; shared by every
store on the same VPS):

| Secret | Value |
|---|---|
| `ARTIFACT_SSH_KEY` | the whole content of `deploy_key` (the private key) |
| `ARTIFACT_HOST` | the VPS host name or IP |
| `ARTIFACT_USERNAME` | the user GitHub logs in as |
| `DEPLOY_KNOWN_HOSTS` | *(optional)* the `ssh-keyscan` output: pins the server. Without it the key is trusted on first use, as in the tutorial, and its fingerprint is shown in each run's summary. |

**One environment per store** — Settings → Environments → New environment.
Each holds only variables (the repository is public, and so are the workflow
logs: the host and user stay secrets, the server is reached as the alias
`store` and never named in a log):

| Environment | `DEPLOY_PATH` (variable) | `STORE_URL` | `STORE_ADMIN_URL` |
|---|---|---|---|
| `dev` | the CS-Cart folder of https://socialtrip.ro/dev/ on the VPS, e.g. `/var/www/socialtrip.ro/dev` | `https://socialtrip.ro/dev/` | `https://socialtrip.ro/dev/admin.php` (or the renamed admin script) |
| `production` (later) | the live store's folder | its URL | its admin URL |

Add `DEPLOY_PORT` to an environment only if SSH is not on port 22. For
`production`, also set **Required reviewers** (you) and **Deployment branches
and tags** → *Selected* → `main` and `v*` (tags are how you roll back).

The workflows appear under **Actions** once this pull request is merged into
`main` (GitHub only lists manual workflows that are on the default branch).

## 4. Check the server

**Actions → Server check → Run workflow** → environment `dev`.

The run summary is a checklist: SSH from GitHub, the tools, PHP version, CS-Cart
found at `DEPLOY_PATH` (with its version, compared with what the add-ons
accept), `config.local.php` readable, write access to the add-on folders and the
cache, the backup folder and free space. Fix any **MISSING** line before
deploying.

### If GitHub cannot reach the server

1. Log in from your computer with the same key (step 2). If that fails, it is
   the key, user, port or host, not GitHub.
2. If `DEPLOY_KNOWN_HOSTS` is set, compare it with a fresh `ssh-keyscan`.
3. Check the Hetzner Cloud firewall and `ufw` allow SSH from anywhere (key
   login only; password login can stay off). If SSH must stay restricted,
   run a **self-hosted runner** on an allowed machine (GitHub → Settings →
   Actions → Runners), or deploy from your computer with the same script
   (WSL): `bash scripts/deploy-addons.sh user@host:/path --go --backup --clear-cache`.

## 5. First installation

Do it on `dev` first, then the same on `production`.

1. Install CS-Cart on the server and secure the admin as CS-Cart recommends.
2. **Server check** again: everything OK.
3. **Actions → Deploy → Run workflow**: *Use workflow from* `main`,
   environment `dev`, *go* **unticked** → the run lists every file it would
   copy. Run it again with *go* ticked (on `production`, approve it).
4. Admin → Add-ons: install them **in order** and configure them
   (`docs/INSTALL.md`, sections 3–5). The deploy copies files; installing
   is a one-time admin step.

## 6. Every release

1. Merge the pull request (CI green).
2. Optionally tag it, so you can come back to it: `git tag v2026.10.10 && git push origin v2026.10.10`.
3. **Actions → Deploy → Run workflow**: `main`, `dev` first, *go* ticked;
   once it looks right there, the same with `production` (and approve). The run:
   - runs the full CI on that commit (a red CI stops here, nothing deployed);
   - backs up the add-on folders and the database to `~/deploy-backups`
     (the newest 10 of each are kept);
   - copies the release, deletes removed files, clears compiled templates;
   - smoke-tests the storefront and the admin login.
4. Open any admin page once (new language labels add themselves).

Unsure what a release will touch? Run it with *go* unticked first: the summary
lists every file to copy and every `*deleting` line.

`~/deploy-backups/deploy.log` on the server records every deploy (time,
commit, add-ons, files copied and deleted).

## 7. Rolling back

- **Code**: Deploy → *Use workflow from* the previous tag (e.g. `v2026.10.03`)
  → *go*. The same script puts that version back, deleting what the newer one
  added.
- **Database**, only if the bad release changed data: on the server,

  ```bash
  ls -t ~/deploy-backups/db-*.sql.gz | head        # pick the one taken before the release
  gunzip -c ~/deploy-backups/db-<time>-<commit>.sql.gz | mysql -u USER -p DBNAME
  ```

  The file names carry the time (UTC) and the commit deployed after them.
  Restoring a database brings back that moment's orders too: take a fresh
  backup first and restore only what you must.

## 8. Before going live

- **Rotate the credentials once committed to this repository.** The repository
  is public, and commit e3df138 removed provider credentials (Sphinx API key,
  Novoton API user/password, a Netopia sandbox signature) from the files but
  not from the history. Ask the providers for new ones and set them only in
  the store's settings.
- Keep `config.local.php`, the payment keys and API credentials out of the
  repository; the deploy never touches them (it only writes the add-ons'
  folders).
- Do not update an add-on by uninstalling and reinstalling it: uninstalling
  drops its tables, bookings included.

## Alternative: CS-Cart Upgrade Center packages

The CS-Cart SDK (`cscart/sdk`) builds Upgrade Center packages between two
versions of an add-on: `cscart-sdk addon:build_upgrade old.zip new.zip out/`,
from the zips `composer package` makes. A package lists new, changed and
deleted files, can carry migrations, and is installed from Admin → Upgrade
Center with CS-Cart's own backup and restore. It needs a `<version>` bump in
every changed `addon.xml` for each release and an upload per add-on, so it
fits stores without SSH access, not this one. It is not set up here.
