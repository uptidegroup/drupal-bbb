# BigBlueButton Test Kit (`bbb_test_kit`)

A self-contained test fixture and test harness for the
[BigBlueButton](https://www.drupal.org/project/bigbluebutton) module. Install
it on a clean site, add a BBB host and secret, and it builds a complete,
realistic setup you can test against — the same way before and after
upgrading the BigBlueButton module.

> **Test/dev sites only.** It creates users with a known password and a
> publicly reachable test room. Never enable it on production.

## Requirements

- Drupal 10.3+ (PHP 8.2+) or Drupal 11 (PHP 8.3+)
- A reachable BigBlueButton server (host URL + shared secret)
- Composer (to pull the dependencies)

## Install

```bash
composer require drupal/bigbluebutton drupal/registration drupal/token drupal/restui
drush en bbb_test_kit -y
```

Then set the BBB **host** and **secret** at
`/admin/config/system/bbb-settings`, and see the accounts:

```bash
drush bbb-test:info
```

## Run the tests

- **In the browser:** go to **Reports → BBB Test Kit results**
  (`/admin/reports/bbb-test-kit`) and click **Run all tests**. The checks run
  through the Drupal Batch API (progress bar) and the page shows a green/red
  list, example API responses, every test user, and a ready-to-click join link
  per registered guest.
- **In the terminal / CI:** `drush bbb-test:run` prints the same report and
  exits non-zero if anything failed (or regressed — see below).

Live checks (host probe, meeting info, recordings) auto-skip when no BBB host
is set. A few browser-only checks stay in [`docs/manual-tests.md`](docs/manual-tests.md).

## Verifying a module upgrade (the main use case)

A site normally runs the **stable** BigBlueButton release, then upgrades to the
**dev** version. This kit tells you whether everything still works afterwards:

1. Install the **stable** `bigbluebutton` release + this kit; set host + secret.
2. Capture the baseline of how it behaves now:
   - Browser: **Run all tests**, then **Save last run as baseline**.
   - CLI: `drush bbb-test:run` then `drush bbb-test:baseline`.
3. Switch to the dev version and deploy:
   ```bash
   composer require 'drupal/bigbluebutton:1.x-dev'
   drush deploy   # or: drush updb -y && drush cim -y && drush cr
   ```
4. Run the tests again (**Run all tests** / `drush bbb-test:run`). Each check is
   compared to the baseline and flagged:
   - **▼ regression** — worked on stable, fails now (the upgrade broke it).
   - **▲ fixed** — failed on stable, passes now (an issue was fixed).
   - unchanged otherwise.

**0 regressions = the module still works like the old one did.** The CLI exits
non-zero if there is any failure *or* regression, so it also gates CI.

Everything is built in `hook_install()` through the entity API — there is no
exported configuration, so the module installs identically on Drupal 10.3+
and Drupal 11.

## What it creates

- Content type **BBB Test Kit room** (`bbb_test_kit_room`) with a body, a
  BigBlueButton field, a meeting-start date, a PDF presentation field, and a
  guest-registration field. (Fields attached to this content type keep short
  names; only site-global names are prefixed.)
- A published **test room** with tokens in its messages and logout URL,
  auto-record on, and guest policy "Ask moderator".
- **Test users** `bbb_test_kit_user_1` … `bbb_test_kit_user_5` (one shared
  password) covering every meeting role, plus a role-alter hook that
  promotes/demotes users. Run `drush bbb-test:info` for the mapping.
- Roles `bbb_test_kit_moderator`, `bbb_test_kit_editor`,
  `bbb_test_kit_demoted`, `bbb_test_kit_recorder`.
- A **registration type** `bbb_test_kit_guest` with two sample registrations.
- Both BigBlueButton **REST resources**, enabled for GET/JSON.
- Default and per-room **presentation PDFs**.
- A post-meeting **feedback form** at `/bbb-test-kit-feedback/{node}` and a
  report at `/admin/reports/bbb-test-kit-feedback`.

## Rebuild / remove

```bash
drush bbb-test:rebuild   # tear down and build again
drush pmu bbb_test_kit -y  # remove everything the kit created
```

## Testing before vs. after the module upgrade

1. Install the kit against the **current** BigBlueButton module and run the
   tests — some security checks are expected to fail.
2. `composer require` the **fixed** version, `drush updb -y`, and run the
   tests again. The after-upgrade run should be all green.

See [`docs/test-plan.md`](docs/test-plan.md) for the full plan, and
[`docs/manual-tests.md`](docs/manual-tests.md) for the few checks that need a
real browser and BBB server.
