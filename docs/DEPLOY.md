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
| `.github/workflows/deploy.yml` | **Deploy**: CI on the exact commit, then the deploy, then the smoke test (a failed smoke test turns the run red). A dry run unless *go* is ticked. |
| `scripts/deploy-addons.sh` | The deploy itself (also usable by hand). Copies only the add-ons' own folders, deletes what a release removed, backs up first, into a backup folder of that store's own. |
| `scripts/smoke-test.sh` | Storefront and admin login answer 200 with no PHP/Smarty error. A closed storefront is looked at with its access key (`STORE_ACCESS_KEY`). |

Why rsync and not "Upload & install" or FTP: those only add or overwrite files.
A template a release deletes stays on the server and keeps rendering (the old
provider order blocks would show beside the new booking card). CS-Cart's own
SDK has no deploy command; its `addon:build_upgrade` (Upgrade Center packages)
is the alternative if SSH is ever impossible: see the end of this file.

## 1. What the server needs

The stores run on a **Hetzner managed server** (a managed vServer,
administered in konsoleH, host names `*.your-server.de`); the same steps fit
an unmanaged VPS or any Linux server with SSH. On Hetzner's managed products
(managed servers and webhosting, all in konsoleH):

- **SSH is on port 222.** Port 22 is SFTP only (it answers `SSH-2.0-mod_sftp`)
  and gives no shell, so rsync cannot run there. Set `DEPLOY_PORT` to `222`.
- SSH is for the **main FTP user** only (the user that owns `public_html`).
- Public keys are added in **konsoleH → Settings → Login data → Public SSH
  Keys** and **Public SFTP Keys** (see step 2).

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
  `~/deploy-backups`, one folder per store (the deploy refuses a backup
  folder inside the store).

**Which user GitHub logs in as.** `root` works, but a key for root lets a
leaked secret do anything on the VPS. Safer: the user that owns the site's
files (a panel such as CloudPanel or Plesk creates one per site; see who owns
them with `stat -c %U /path/to/store/config.php`). It needs to write the
add-on folders and `var/cache`, and to read `config.local.php`; the Server
check verifies all of it.

## 2. Create the deploy key (once)

A key used only by GitHub, **without a passphrase** (GitHub cannot type one),
in **OpenSSH format**. Not your own key, and not a PuTTY `.ppk` file: if you
use PuTTY, keep your key for yourself and make this one separately.

On your computer, in PowerShell (Windows has `ssh-keygen` built in):

```powershell
ssh-keygen -t ed25519 -C "github-deploy" -f "$env:USERPROFILE\.ssh\github_deploy"
```

Press **Enter twice** when it asks for a passphrase (leave it empty). It writes
two files in `C:\Users\<you>\.ssh\`:

- `github_deploy` — the **private** key. It starts with
  `-----BEGIN OPENSSH PRIVATE KEY-----`. It goes into GitHub (step 3), nowhere else.
- `github_deploy.pub` — the **public** key, one line starting with
  `ssh-ed25519`. It goes on the server.

Add the public key on the server:

- **Hetzner managed server** (konsoleH): Settings → Login data →
  *Public SSH Keys* → **Add**, paste the whole line of `github_deploy.pub`,
  save; do the same under *Public SFTP Keys* (konsoleH converts it to the
  format it needs). The SSH section serves the shell on port 222, which the
  deploy uses; the SFTP section serves port 22, and Hetzner's guide names
  only that one. A public key reveals nothing, so adding it to both is safe.
- **An unmanaged VPS**: logged in as the site user,
  ```bash
  mkdir -p ~/.ssh && chmod 700 ~/.ssh
  echo 'ssh-ed25519 AAAA...the whole line of github_deploy.pub... github-deploy' >> ~/.ssh/authorized_keys
  chmod 600 ~/.ssh/authorized_keys
  ```

Check it from your computer (port **222** on a Hetzner managed server,
usually 22 on an unmanaged VPS):

```powershell
ssh -i "$env:USERPROFILE\.ssh\github_deploy" -p 222 sociad@socialtrip.ro
```

It must open a shell **without asking for a password or passphrase**.
Optional but recommended, to pin the server:

```powershell
ssh-keyscan -p 222 socialtrip.ro
```

Scan the **SSH port** (222); any of the server's names works, the shop's
domain or the server's own name (`…your-server.de`): they reach the same
machine and the same key, and only the key is kept (the name in each line is
ignored). Its output has comment lines (`# socialtrip.ro:222 SSH-2.0-…`) and
key lines (`[socialtrip.ro]:222 ssh-ed25519 AAAA…`): the secret
`DEPLOY_KNOWN_HOSTS` below needs only the key lines (comment lines do no
harm). A scan of port 22 gives the SFTP service's key, which does not match
port 222.

## 3. Set up GitHub (once)

**Repository secrets** — Settings → Secrets and variables → Actions →
*Repository secrets* (the names of the Hetzner tutorial; shared by every
store on the same VPS):

| Secret | Value |
|---|---|
| `ARTIFACT_SSH_KEY` | the whole content of `github_deploy` (the private key: open it in Notepad, copy from `-----BEGIN` to `-----END…-----`) |
| `ARTIFACT_HOST` | the server's name or IP, without `https://`, user or port: the shop's domain (`socialtrip.ro`) or the server's own name from konsoleH (`…your-server.de`). Both reach the same machine; the server's own name keeps working if the domain ever moves elsewhere. |
| `ARTIFACT_USERNAME` | the user GitHub logs in as |
| `DEPLOY_KNOWN_HOSTS` | *(optional)* the key lines of `ssh-keyscan -p 222 …` (step 2): pins the server. Without it the key is trusted on first use, as in the tutorial, and its fingerprint is shown in each run's summary. |

**One environment per store** — Settings → Environments → New environment.
The settings below are **variables**: in the environment, *Environment
variables* → **Add environment variable** (the workflows read these as
variables, and one saved under *Environment secrets* is reported as
misplaced). The repository is public, and so are the workflow logs: the host
and user stay secrets, the server is reached as the alias `store` and never
named in a log.

| Environment | `DEPLOY_PATH` | `STORE_URL` | `STORE_ADMIN_URL` |
|---|---|---|---|
| `dev` | the CS-Cart folder of https://socialtrip.ro/dev/ on the server (where `config.php` is), e.g. `/usr/home/sociad/public_html/dev` | `https://socialtrip.ro/dev/` | `https://socialtrip.ro/dev/admin.php` (or the renamed admin script) |
| `production` | the live store's CS-Cart folder on the same server (where its `config.php` is) | its address, `https://<live domain>/` | its admin script, `https://<live domain>/admin.php` (or the renamed one) |

Add `DEPLOY_PORT` to an environment when SSH is not on port 22: **`222` on
a Hetzner managed server** (in both environments: it is one server).

**Environment secrets**, only where they apply — in the environment,
*Environment secrets* → **Add environment secret**:

| Secret | When |
|---|---|
| `STORE_ACCESS_KEY` | While the storefront is closed (Administration → Stores → the storefront → status *Closed*): its *Access key to temporarily closed store*. The smoke test sends it as `store_access_key`, as CS-Cart allows, and checks the storefront behind the closed page. Without it a closed storefront fails the smoke test: CS-Cart's error page looks like the closed one, so a closed store is never taken for a healthy one. `dev` needs it for as long as it stays closed, `production` until it opens. |
| `ARTIFACT_USERNAME`, `ARTIFACT_SSH_KEY` | Only for a store whose files belong to another SSH user than the repository secrets' (see "Production on the same server" below). An environment secret overrides the repository secret of the same name, for that environment only. |

**Protect `production`**, in its environment page:

- **Required reviewers**: you. Each production deploy then waits, after CI,
  until you press *Review deployments → Approve*. Leave **Prevent
  self-review** unticked while you are the only reviewer: it would stop you
  approving your own runs.
- **Deployment branches and tags** → *Selected branches and tags* → add the
  branch `main` and the tag rule `v*` (tags are how you roll back). A run
  from any other branch is refused before it reaches the server.

The workflows appear under **Actions** once this pull request is merged into
`main` (GitHub only lists manual workflows that are on the default branch).

### Production on the same server, another domain

The live store sits on the same server as `dev`, with its own domain and
folder. The repository secrets already reach that server, and its host key
(`DEPLOY_KNOWN_HOSTS`) is the same. What differs is the folder, the
addresses, and perhaps the SSH user: see who owns the live store's files
(the third column of `ls -l /path/to/the/live/store/config.php`), or whether
konsoleH lists its domain under the same account as `dev`.

- **The same user as `ARTIFACT_USERNAME`** (the domain was added to the same
  konsoleH account): nothing more to set. The deploy keeps each store's
  backups and log in a folder of its own under `~/deploy-backups`, so dev
  deploys never rotate out the live store's backups.
- **Another user** (the domain is a konsoleH account of its own, with its own
  main FTP user): add the line of `github_deploy.pub` to that account too
  (step 2: Settings → Login data → Public SSH Keys and Public SFTP Keys),
  check that `ssh -i … -p 222 <that user>@<server>` logs in, and save that
  user as the environment secret `ARTIFACT_USERNAME` of `production`.

## 4. Check the server

**Actions → Server check → Run workflow** → environment `dev` (and
`production`, once it exists).

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
   copy. Run it again with *go* ticked (on `production`, approve it; while
   its storefront stays closed, set its `STORE_ACCESS_KEY` first, section 3).
4. Admin → Add-ons → **Downloaded add-ons**: install them **in order** and configure them
   (`docs/INSTALL.md`, sections 3–5). The deploy copies files; installing
   is a one-time admin step.

## 6. Every release

1. Merge the pull request (CI green).
2. Tag it, so dev and production get the very same commit and you can come
   back to it: `git tag v2026.10.10 && git push origin v2026.10.10`.
3. **Actions → Deploy → Run workflow**: *Use workflow from* the tag, `dev`
   first, *go* ticked; once it looks right there, the same tag on
   `production` (and approve). From the tag rather than `main`, production
   gets what dev ran even if more was merged in between. The run:
   - runs the full CI on that commit (a red CI stops here, nothing deployed);
   - backs up the add-on folders and the database into that store's folder,
     `~/deploy-backups/<store>/` (the newest 10 of each are kept, per store;
     the run summary names the files);
   - copies the release, deletes removed files, clears compiled templates;
   - smoke-tests the storefront and the admin login. A failure turns the run
     red: the release is on the store by then, so look at it and roll back
     (section 7) if it is broken.
4. Open any admin page once (new language labels add themselves).

Unsure what a release will touch? Run it with *go* unticked first: the summary
lists every file to copy and every `*deleting` line.

`~/deploy-backups/<store>/deploy.log` on the server records every deploy to
that store (time, commit, add-ons, files copied and deleted). `<store>` is
the store folder's name and a code made from its path, such as
`dev-1a2b3c4d`; each run's log shows it on its `backups` line.

## 7. Rolling back

- **Code**: Deploy → *Use workflow from* the previous tag (e.g. `v2026.10.03`)
  → *go*. The same script puts that version back, deleting what the newer one
  added.
- **Database**, only if the bad release changed data: on the server,

  ```bash
  ls -t ~/deploy-backups/*/db-*.sql.gz | head      # one folder per store: pick that store's, taken before the release
  gunzip -c ~/deploy-backups/<store>/db-<time>-<commit>.sql.gz | mysql -u USER -p DBNAME
  ```

  The folder is the store (the deploy run's summary names the files it took);
  the file names carry the time (UTC) and the commit deployed after them.
  Backups from before the stores had folders of their own lie directly in
  `~/deploy-backups`: all of them are dev's.
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
