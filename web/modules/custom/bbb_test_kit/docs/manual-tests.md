# BigBlueButton — manual checks

The automated suite covers responses and access. These few need a real
browser and a reachable BBB server, so a person runs them once per release.

Prerequisite: `drush bbb-test:info` for the room URL and the password.

1. **Join as moderator** — log in as `bbb_test_kit_user_2`, open the room,
   Join. You enter BBB as a moderator and can record.
2. **Join as viewer** — log in as `bbb_test_kit_user_4`, Join. You enter as a
   viewer.
3. **Guest by registration** — open the room in a private window with
   `?registration=<Gina's uuid>` (from `drush bbb-test:info` /
   `/admin/people/registrations`); the name in BBB is "Gina Guest".
4. **Countdown** — set *Meeting start* a few minutes ahead. As a guest the
   Join button is hidden behind a live countdown and appears (via AJAX) at
   zero; moderators can join immediately.
5. **After the meeting** — leaving BBB lands you on
   `/bbb-test-kit-feedback/{nid}`; submit and check
   `/admin/reports/bbb-test-kit-feedback`.
6. **Recordings** — after a recorded meeting is processed, the room shows the
   recording styles; check view, download and delete (delete is permanent).

Record the outcome in `test-report.md`.
