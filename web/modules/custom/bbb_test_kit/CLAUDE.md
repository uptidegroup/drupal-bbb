# BigBlueButton Test Kit — notes for Claude Code

This module (`bbb_test_kit`) sets up and exercises a full BigBlueButton test
fixture. Use it to verify the BigBlueButton contrib module before and after
its security/upgrade work.

## Rules

- **Never edit `web/modules/contrib/bigbluebutton`.** It is the module under
  test and is replaced by Composer. All test tooling lives here.
- Everything is built in code (`src/TestKitInstaller.php`), not in
  `config/install`, so it stays portable across Drupal 10.3+ and 11. Add new
  fixture pieces there, keeping each step idempotent.

## Common tasks

- Install: `drush en bbb_test_kit -y` (needs the deps from README).
- Show accounts + password: `drush bbb-test:info`
- Rebuild fixture: `drush bbb-test:rebuild`
- Run all checks (page): /admin/reports/bbb-test-kit · (cli): `drush bbb-test:run`

## Layout

- `src/TestKitInstaller.php` — builds/tears down the fixture (entity API).
- `bbb_test_kit.install` — `hook_requirements`, install, uninstall, feedback table.
- `bbb_test_kit.module` — role-alter hook, form alters, recording preprocess,
  join countdown (+ AJAX), feedback extra fields.
- `src/Form/FeedbackForm.php`, `src/Controller/` — feedback + AJAX join button.
- `docs/` — test plan, results log, manual test steps.

## Status

Steps done: fixture via hook_install, recordings view+block+template, join countdown (AJAX), feedback, and a full pass/fail runner (page + drush bbb-test:run). Next: optional PHPUnit/CI wrapper and the upgrade-path test.
