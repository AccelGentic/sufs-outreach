# Working on this repository

## Delivering changes: git bundles, not `git push`

**`git push` to this repository is always blocked** and will stay that
way. The remote rejects it with a 403 and a message about the Claude
GitHub App not being installed for the AccelGentic organization. Don't
treat it as a transient failure, don't retry it with backoff, and don't
stop and ask what to do about it -- it is the expected outcome.

Deliver every change as a **git bundle** instead. The maintainer applies
it to their own clone and pushes from there.

```bash
# 1. Commit to the designated branch as normal.

# 2. Build a bundle of the whole branch (complete history -- it is small
#    and it applies to any clone, with no prerequisite commits to miss).
git bundle create /path/to/scratch/<short-name>.bundle <branch>

# 3. Verify it actually applies, from the current origin/main, before
#    handing it over. Never skip this.
#
#    Fast-forward the local main first: cloning "." resolves origin/main
#    in the clone to this repo's LOCAL main, so a stale local ref makes
#    the verification diff silently wrong (it will show already-merged
#    commits as if they were new).
git branch -f main origin/main
git clone -q . /tmp/verify -n
cd /tmp/verify && git checkout -q origin/main
git fetch -q /path/to/scratch/<short-name>.bundle <branch>:check
git checkout -q check && git log --oneline -1 && git diff --stat origin/main..HEAD
```

Then send it with `SendUserFile` and include the command to apply it:

```bash
git fetch /path/to/<name>.bundle <branch>:<branch>
git checkout <branch>
```

Send the individual changed file alongside the bundle when it's
something the maintainer may want to drop straight onto a server (an
Apache config, a standalone script) rather than apply through git.

## Starting a session

The maintainer applies each bundle to `main` and pushes it themselves,
then deletes the feature branch. So a previously "open" branch is
usually already merged upstream.

1. `git fetch --all --prune`
2. Check whether the last session's work is already in `origin/main`.
3. If it is, restart the designated branch from the new base rather than
   stacking on merged history:
   `git checkout -B <branch> origin/main`

## Commits

Author commits as `Claude <noreply@anthropic.com>` -- a stop hook flags
anything else as unverified. Use
`git -c user.email=noreply@anthropic.com -c user.name=Claude commit ...`
so the maintainer's own git identity isn't changed.

Never put a model name or identifier in a commit message, code comment,
or anything else committed to the repository.

## What this environment can and cannot run

- **No MariaDB/MySQL**, and `apt-get install` is blocked. To exercise
  code that touches the database, copy the app to the scratchpad and
  swap `includes/db.php` for a SQLite PDO. Queries using MySQL-only
  syntax (`NOW() - INTERVAL 1 HOUR`, `ENUM`, `ON UPDATE
  CURRENT_TIMESTAMP`) need patching **in the scratch copy only** -- never
  in the repo.
- **No Apache**, so `apache2ctl configtest` is unavailable. Validate
  vhost changes by inspection instead: block nesting, directive contexts
  (`<IfModule>` is context-transparent), and version-gated directives
  against 2.4.58 (what Ubuntu 24.04 ships). Say plainly in the summary
  that configtest was not run.
- **Chromium and Playwright are available**
  (`executablePath: '/opt/pw-browsers/chromium'`). Use them for anything
  visual -- measure the layout, don't eyeball the CSS. This has already
  caught a real grid bug that reading the stylesheet did not.
- **Egress is restricted.** `cloudflare.com` is blocked, among others.
  Prefer designs that fetch external data on the target server at
  install time over lists baked into the repo.

## Repository shape

- `public/` is the only web-served directory; `config.php`, `includes/`,
  `sql/` and `scripts/` sit outside the document root deliberately.
- `config.php` is gitignored and holds the DB password, mail
  credentials and the admin password hash. Never commit it.
- `public/admin/` is the password-protected template editor; the
  visitor-facing pages are the four PHP files at the root of `public/`.
