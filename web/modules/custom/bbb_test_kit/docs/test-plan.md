# BigBlueButton — test plan

What the kit checks. Each item names the behaviour that must always hold, so
the same test runs against the current module (some will fail) and the fixed
module (all should pass). Tracker IDs (BBB-01 … BBB-15) refer to the security
review issue list.

## Roles / users

| User | Roles | Expected meeting role |
|---|---|---|
| admin | administrator | moderator |
| bbb_test_kit_user_1 | bbb_test_kit_moderator | moderator (role hook, no edit access) |
| bbb_test_kit_user_2 | bbb_test_kit_editor | moderator (edit access) |
| bbb_test_kit_user_3 | bbb_test_kit_editor, bbb_test_kit_demoted | viewer (role hook demotes) |
| bbb_test_kit_user_4 | authenticated | viewer (also the "registered user" sample) |
| bbb_test_kit_user_5 | bbb_test_kit_recorder | viewer (+ recording perms) |
| anonymous | – | viewer, guest |

## Automated (no BBB server) — group `bigbluebutton`

- Role mapping matches the table above, including the role-alter hook.
- Meeting-info returns **no** `attendeePW` / `moderatorPW` to a viewer (BBB-02).
- Meeting-info denies a user without view access (BBB-02).
- The download endpoint rejects an off-host / non-recording URL (BBB-01).
- Unknown UUID / entity without a BBB field returns a clean 4xx, not 500 (BBB-13).
- Recording delete requires edit access to the room (BBB-09).
- Tokens in messages/logout resolve against the node only; the logout URL
  points at the feedback route.
- Registration display name resolves from `?registration=<uuid>`.

## Live (needs host + secret) — group `bigbluebutton_live`

- Host probe: API root answers, secret is accepted.
- Join link builds for each role; after the upgrade it uses `role=`, not a
  password (BBB-15).
- Meeting-info returns a filtered status payload.
- Recordings list / view / (careful) download.

## Security-fix checks (group "Security fixes")

These are **expected to FAIL on the current stable release** and flip to PASS
(▲ fixed) as each tracker fix is applied. They inspect routes, config and the
library version (deterministic, side-effect free), plus one live check:

- BBB-01: download route is bound to a recording, not a raw `?url=` (SSRF).
- BBB-04: recording view route enforces per-entity access (IDOR).
- BBB-07: meeting-end callback is not open via "access content" (needs a token).
- BBB-15: `bigbluebutton-api-php` >= 3.0 (role-based join, no stored passwords).
- BBB-02: meeting-info does not expose passwords to a viewer — *live check,
  skips unless a meeting is running in the demo room.*

The "Join link" group builds a signed join URL for the room **without creating
a meeting** (asserts `fullName` + checksum on the configured host) — the
safe automated proxy for "people can join".

## Before vs. after upgrade

Run once on the current module (record reds), upgrade the BigBlueButton module
+ `drush updb`, run again. The after-upgrade run is the acceptance gate:
everything green, and no meeting passwords stored on nodes.
