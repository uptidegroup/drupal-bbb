# Deploying to Northflank

This project builds into a single container (Apache + PHP 8.3) from the
[`Dockerfile`](./Dockerfile). Dependencies (Drupal core, contrib, the
`bigbluebutton` dev module, and the `bigbluebutton-api-php` 3.0 library) are
installed by Composer **at build time** — they are not committed to git.

## 1. Create the service

- **Type:** Combined (build + deploy) service from the GitHub repo
  `uptidegroup/drupal-bbb`, branch `main`.
- **Build:** Dockerfile, path `./Dockerfile`, context `/`.
- **Port:** `80` (HTTP), publicly exposed. Northflank terminates TLS at the edge;
  the container reads `X-Forwarded-Proto`.

## 2. Add a database addon

Add a **MySQL** (or MariaDB) addon and map its connection details to the
service environment. Drupal reads these variables (see `docker/settings.php`):

| Variable      | Value                                  |
| ------------- | -------------------------------------- |
| `DB_HOST`     | addon host                             |
| `DB_PORT`     | `3306`                                 |
| `DB_NAME`     | database name                          |
| `DB_USER`     | database user                          |
| `DB_PASSWORD` | database password                      |

(Alternatively set a single `DATABASE_URL`, which overrides the individual vars.)

## 3. Required environment variables

| Variable                 | Required | Notes                                                            |
| ------------------------ | -------- | ---------------------------------------------------------------- |
| `HASH_SALT`              | yes      | Long random string (`openssl rand -hex 32`).                     |
| `TRUSTED_HOST_PATTERNS`  | yes      | Comma-separated regexes, e.g. `^.*\.code\.run$,^bbb\.example\.com$`. |
| `RUN_SITE_INSTALL`       | first deploy | `true` to install Drupal from committed config (idempotent).  |
| `RUN_DRUSH_DEPLOY`       | yes      | `true` to run `drush deploy` (updatedb + config import + cache) on start. |
| `BBB_HOST`               | recommended | BigBlueButton API URL, e.g. `https://your-bbb/bigbluebutton/`. |
| `BBB_SECRET`             | recommended | BigBlueButton shared secret.                                  |
| `DRUPAL_PRIVATE_FILES`   | optional | Path for private files, e.g. `/var/www/private`.                 |
| `REVERSE_PROXY_ADDRESSES`| optional | Comma-separated trusted proxy IPs.                               |

**BigBlueButton credentials** are supplied via `BBB_HOST` / `BBB_SECRET` (see
`docker/settings.php`). These are applied as runtime config overrides, so the
secret is never stored in the repo and is never overwritten by `drush config:import`.
Alternatively, leave them unset and enter host + secret in the UI at
`admin/config/system/bbb-settings` (stored in the database). The committed
`config/sync/bigbluebutton.settings.yml` intentionally ships with empty
`hostname`/`secret`.

## 4. Persistent storage

Mount a **persistent volume** at:

```
/var/www/html/web/sites/default/files
```

so uploaded files and generated assets survive restarts/redeploys. (Optionally
mount another volume for `DRUPAL_PRIVATE_FILES`.)

## 5. First deploy vs. subsequent deploys

The entrypoint (`docker/entrypoint.sh`) drives this via env vars:

- **First deploy:** set `RUN_SITE_INSTALL=true` and `RUN_DRUSH_DEPLOY=true`.
  It installs Drupal from `config/sync` (skipped automatically if already
  installed), then runs updates. Afterwards you can leave `RUN_SITE_INSTALL=true`
  — it is a no-op once installed.
- **Every deploy:** keep `RUN_DRUSH_DEPLOY=true` so schema updates (e.g.
  bigbluebutton `update_8004`) and config changes are applied automatically.

Set an admin password after the first install:

```
drush user:password admin '<new-password>'
```

(run via a Northflank shell/exec into the container).

## 6. Notes

- The `bbb_test_kit` module ships in this codebase. It creates test users with a
  shared password and exposes a test report. **Uninstall it on a public
  production site** (`drush pmu bbb_test_kit -y`) unless you specifically want the
  test harness available.
- The `bigbluebutton` module is tracked at `1.0.x-dev`; `composer install` clones
  it from drupal.org during the build (git is available in the image).
- Local development still uses ddev; none of the above affects it.
