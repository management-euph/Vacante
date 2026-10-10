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

## 1. What the production server needs

- Linux hosting with **SSH login by key**, and `rsync`, `tar`, `gzip`,
  `mysqldump` (or `mariadb-dump`) and PHP **8.3+** on the command line.
  A VPS has all of this; on shared hosting ask for SSH and check with the
  Server check (step 4).
- SSH **not limited to some IP addresses**: GitHub's runners use changing
  addresses from shared cloud ranges, and GitHub advises against allowing
  them by IP. If the hosting insists, see "If GitHub cannot reach the server".
- CS-Cart **4.19.1–4.20.x** installed, MySQL 8.0+ / MariaDB 10.6+, the
  `responsive` or `nova_theme` theme (`docs/INSTALL.md`, section 1).
- The deploy user's home directory **outside** the web root: backups are kept
  in `~/deploy-backups` (the deploy refuses a backup folder inside the store).

## 2. Create the deploy key (once)

On your computer (PowerShell or WSL):

```bash
ssh-keygen -t ed25519 -C "github-deploy" -N "" -f deploy_key
```

- Add the content of `deploy_key.pub` to `~/.ssh/authorized_keys` of the deploy
  user on the server (most hosting panels have an "SSH keys" page for this).
- Check it works from your computer: `ssh -i deploy_key -p PORT user@host`.
- Get the server's host key: `ssh-keyscan -p PORT host` (compare the fingerprint
  with the one your hosting shows, if it does).

## 3. Create the GitHub environment (once)

Repository → **Settings → Environments → New environment** → `production`:

- **Required reviewers**: you. Every production deploy then waits for your
  approval.
- **Deployment branches and tags**: *Selected branches and tags* → `main` and
  `v*` (tags are how you roll back).
- **Environment secrets** (masked in logs; the repository is public, and so
  are its workflow logs):

  | Secret | Value |
  |---|---|
  | `DEPLOY_SSH_KEY` | the whole content of `deploy_key` (the private key) |
  | `DEPLOY_KNOWN_HOSTS` | the `ssh-keyscan` output |
  | `DEPLOY_TARGET` | `user@host:/absolute/path/to/cscart` |

- **Environment variables**:

  | Variable | Value |
  |---|---|
  | `DEPLOY_PORT` | the SSH port, only if it is not 22 |
  | `STORE_URL` | `https://your-shop.ro` (smoke test) |
  | `STORE_ADMIN_URL` | the admin login page, e.g. `https://your-shop.ro/admin.php` (or your renamed admin script) |

Then delete `deploy_key` from your computer, or keep it somewhere safe. A
`staging` environment, for a test copy of the store on a subdomain, is set up
the same way with its own secrets.

## 4. Check the server

**Actions → Server check → Run workflow** → environment `production`.

The run summary is a checklist: SSH from GitHub, the tools, PHP version, CS-Cart
found (with its version), write access to the add-on folders, the backup folder
and free space. Fix any **MISSING** line before deploying. "No CS-Cart at the
store path yet" is expected until CS-Cart is installed (step 5).

### If GitHub cannot reach the server

1. Log in from your computer with the same key (step 2). If that fails, it is
   the key, user, port or host, not GitHub.
2. Compare `DEPLOY_KNOWN_HOSTS` with a fresh `ssh-keyscan`.
3. Ask the hosting whether SSH is restricted by IP address. If it is, either
   run a **self-hosted runner** on a machine the hosting allows (GitHub →
   Settings → Actions → Runners), or deploy from your computer with the same
   script (WSL): `bash scripts/deploy-addons.sh user@host:/path --go --backup --clear-cache`.

## 5. First installation

1. Install CS-Cart on the server and secure the admin as CS-Cart recommends.
2. **Server check** again: everything OK.
3. **Actions → Deploy → Run workflow**: *Use workflow from* `main`,
   environment `production`, *go* **unticked** → the run lists every file it
   would copy. Run it again with *go* ticked, and approve it.
4. Admin → Add-ons: install them **in order** and configure them
   (`docs/INSTALL.md`, sections 3–5). The deploy copies files; installing
   is a one-time admin step.

## 6. Every release

1. Merge the pull request (CI green).
2. Optionally tag it, so you can come back to it: `git tag v2026.10.10 && git push origin v2026.10.10`.
3. **Actions → Deploy → Run workflow**: `main`, `production`, *go* ticked →
   approve. The run:
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
