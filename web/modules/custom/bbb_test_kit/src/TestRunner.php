<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit;

use BigBlueButton\Parameters\GetMeetingInfoParameters;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use Drupal\bigbluebutton\BBB;
use Drupal\node\NodeInterface;

/**
 * Runs every BBB Test Kit check and returns structured, grouped results.
 *
 * All checks are READ-ONLY: they never create or join meetings, so running
 * the suite is safe to repeat. The same results feed the admin report page
 * and the "drush bbb-test:run" command.
 *
 * A check row is: ['status' => pass|fail|skip, 'label' => ..., 'detail' => ...].
 */
final class TestRunner {

  public const PASS = 'pass';
  public const FAIL = 'fail';
  public const SKIP = 'skip';

  /**
   * State keys for the saved baseline and the last run.
   */
  public const STATE_BASELINE = 'bbb_test_kit.baseline';
  public const STATE_LAST_RUN = 'bbb_test_kit.last_run';

  /**
   * Group name => check method. One batch step runs one group.
   */
  private function groupMethods(): array {
    return [
      'Setup' => 'checkSetup',
      'Roles & permissions' => 'checkRoles',
      'Access gating' => 'checkAccess',
      'Guest name' => 'checkGuestName',
      'Join link' => 'checkJoinLink',
      'BBB server (live)' => 'checkServer',
      'Recordings (live)' => 'checkRecordings',
      'Security fixes (expected to fail until applied)' => 'checkSecurity',
      'Feedback' => 'checkFeedback',
    ];
  }

  /**
   * Ordered group names (used to build the batch operations).
   *
   * @return string[]
   */
  public function groupNames(): array {
    return array_keys($this->groupMethods());
  }

  /**
   * Runs a single group and returns its rows (each tagged with a stable id).
   *
   * @return array<int, array>
   */
  public function runGroup(string $name): array {
    $method = $this->groupMethods()[$name] ?? NULL;
    if (!$method) {
      return [];
    }
    $rows = $this->{$method}();
    foreach ($rows as &$row) {
      $row['id'] = $this->checkId($name, $row['label']);
    }
    return $rows;
  }

  /**
   * Runs all groups synchronously (used by drush; the page uses a batch).
   */
  public function run(): array {
    $groups = [];
    foreach ($this->groupNames() as $name) {
      $groups[$name] = $this->runGroup($name);
    }
    return $this->finalize($groups);
  }

  /**
   * Assembles a full result from already-run groups (env, totals, baseline
   * comparison, resources, examples). Shared by run() and the batch finish.
   */
  public function finalize(array $groups): array {
    $baseline = $this->baseline();
    $totals = [self::PASS => 0, self::FAIL => 0, self::SKIP => 0];
    $deltas = ['regression' => 0, 'fixed' => 0, 'changed' => 0, 'same' => 0, 'new' => 0];

    foreach ($groups as &$rows) {
      foreach ($rows as &$row) {
        $totals[$row['status']]++;
        if ($baseline) {
          $prev = $baseline['checks'][$row['id']] ?? NULL;
          $row['baseline'] = $prev;
          $row['delta'] = $this->classify($prev, $row['status']);
          $deltas[$row['delta']]++;
        }
      }
    }

    return [
      'env' => $this->environment(),
      'groups' => $groups,
      'totals' => $totals,
      'deltas' => $baseline ? $deltas : NULL,
      'baseline_meta' => $baseline ? ['captured' => $baseline['captured'], 'env' => $baseline['env']] : NULL,
      'resources' => $this->manualResources(),
      'examples' => $this->apiExamples(),
      'generated' => \Drupal::time()->getRequestTime(),
    ];
  }

  /**
   * A stable id for a check, from its group and label (details excluded).
   */
  private function checkId(string $group, string $label): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($group . '__' . $label)), '_');
  }

  /**
   * Classifies a check against its baseline status.
   */
  private function classify(?string $prev, string $cur): string {
    if ($prev === NULL) {
      return 'new';
    }
    if ($prev === $cur) {
      return 'same';
    }
    if ($prev === self::FAIL && $cur === self::PASS) {
      return 'fixed';
    }
    if ($prev === self::PASS && $cur === self::FAIL) {
      return 'regression';
    }
    return 'changed';
  }

  /**
   * The saved baseline, or NULL.
   */
  public function baseline(): ?array {
    return \Drupal::state()->get(self::STATE_BASELINE);
  }

  /**
   * Captures the current results (statuses only) as the baseline.
   *
   * Run this on the STABLE release before the composer switch to the dev
   * version, so the post-upgrade run can be compared against it.
   */
  public function captureBaseline(): array {
    $result = $this->run();
    $checks = [];
    foreach ($result['groups'] as $rows) {
      foreach ($rows as $row) {
        $checks[$row['id']] = $row['status'];
      }
    }
    $baseline = [
      'captured' => \Drupal::time()->getRequestTime(),
      'env' => $result['env'],
      'checks' => $checks,
    ];
    \Drupal::state()->set(self::STATE_BASELINE, $baseline);
    return $baseline;
  }

  /**
   * Forgets the baseline.
   */
  public function clearBaseline(): void {
    \Drupal::state()->delete(self::STATE_BASELINE);
  }

  /**
   * Stores / reads the last run (so the page can show it without re-running).
   */
  public function saveLastRun(array $result): void {
    \Drupal::state()->set(self::STATE_LAST_RUN, $result);
  }

  public function lastRun(): ?array {
    return \Drupal::state()->get(self::STATE_LAST_RUN);
  }

  /**
   * Real, read-only response examples from the BBB API calls, for reference.
   *
   * Passwords are masked. Never generates a join link here (that would create
   * a meeting); the join-link shape is described instead.
   *
   * @return array<int, array{label: string, body: string}>
   */
  public function apiExamples(): array {
    $out = [];
    $room = $this->room();
    $bbb = $this->bbb();

    if ($room) {
      try {
        $data = \Drupal::service('bigbluebutton.helper')->getMeetingInfo('node', $room->uuid());
        $out[] = [
          'label' => 'GET /api/bigbluebutton/meeting-info/node/' . $room->uuid() . '?_format=json',
          'body' => $this->mask(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        ];
      }
      catch (\Throwable $e) {
        $out[] = ['label' => 'meeting-info', 'body' => 'Error: ' . $e->getMessage()];
      }
    }

    if ($bbb && $room) {
      try {
        $params = new GetRecordingsParameters();
        $params->setMeetingID($room->uuid());
        $xml = $bbb->getRecordings($params)->getRawXml();
        $recs = [];
        foreach ($xml->recordings->recording ?? [] as $rec) {
          $formats = [];
          foreach ($rec->playback->format ?? [] as $fmt) {
            $formats[] = ['type' => (string) $fmt->type, 'url' => (string) $fmt->url];
          }
          $recs[] = [
            'recordID' => (string) $rec->recordID,
            'meetingID' => (string) $rec->meetingID,
            'state' => (string) $rec->state,
            'formats' => $formats,
          ];
        }
        $out[] = [
          'label' => 'getRecordings (meetingID = ' . $room->uuid() . ')',
          'body' => json_encode(['returncode' => (string) $xml->returncode, 'recordings' => $recs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
      }
      catch (\Throwable $e) {
        $out[] = ['label' => 'getRecordings', 'body' => 'Error: ' . $e->getMessage()];
      }
    }

    if ($bbb) {
      try {
        $xml = $bbb->getApiVersion()->getRawXml();
        $out[] = [
          'label' => 'getApiVersion (server root)',
          'body' => json_encode([
            'returncode' => (string) ($xml->returncode ?? ''),
            'apiVersion' => (string) ($xml->apiVersion ?? $xml->version ?? ''),
            'bbbVersion' => (string) ($xml->bbbVersion ?? ''),
          ], JSON_PRETTY_PRINT),
        ];
      }
      catch (\Throwable $e) {
        $out[] = ['label' => 'getApiVersion', 'body' => 'Error: ' . $e->getMessage()];
      }
    }

    // The join-link endpoint is not called here because it would create a
    // meeting; show its shape instead.
    $out[] = [
      'label' => 'GET /api/bigbluebutton/join-meeting-link/node/{uuid}?_format=json (shape — creates a meeting when called live)',
      'body' => json_encode([
        'link' => 'https://<bbb-host>/bigbluebutton/api/join?meetingID=<uuid>&fullName=<name>&role=VIEWER|MODERATOR&redirect=true&checksum=<...>',
        'role' => 'viewer|moderator',
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ];

    return $out;
  }

  /**
   * Masks meeting passwords in a response body.
   */
  private function mask(string $body): string {
    return preg_replace('/("(?:attendeePW|moderatorPW)":\s*")[^"]{3}[^"]*/', '$1***(masked)', $body) ?? $body;
  }

  /**
   * Environment banner data.
   */
  public function environment(): array {
    $lib = 'unknown';
    if (class_exists(\Composer\InstalledVersions::class)) {
      try {
        $lib = \Composer\InstalledVersions::getPrettyVersion('bigbluebutton/bigbluebutton-api-php') ?? 'unknown';
      }
      catch (\Throwable) {
      }
    }
    $config = \Drupal::config('bigbluebutton.settings');
    return [
      'drupal' => \Drupal::VERSION,
      'php' => PHP_VERSION,
      'library' => $lib,
      'bbb_configured' => (bool) ($config->get('hostname') && $config->get('secret')),
    ];
  }

  private function row(string $status, string $label, string $detail = ''): array {
    return ['status' => $status, 'label' => $label, 'detail' => $detail];
  }

  private function bool(bool $ok, string $label, string $okDetail = '', string $failDetail = ''): array {
    return $this->row($ok ? self::PASS : self::FAIL, $label, $ok ? $okDetail : $failDetail);
  }

  /**
   * The demo room, or NULL if the fixture is missing.
   */
  private function room(): ?NodeInterface {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['type' => TestKitInstaller::NODE_TYPE]);
    return $nodes ? reset($nodes) : NULL;
  }

  private function checkSetup(): array {
    $etm = \Drupal::entityTypeManager();
    $rows = [];
    $rows[] = $this->bool((bool) $etm->getStorage('node_type')->load(TestKitInstaller::NODE_TYPE),
      'Content type "BBB Test Kit room" exists');
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', TestKitInstaller::NODE_TYPE);
    foreach (['field_bbb', 'field_bbb_start', 'field_bbb_presentation', 'field_bbb_registration'] as $f) {
      $rows[] = $this->bool(isset($fields[$f]), "Field $f attached");
    }
    $rows[] = $this->bool((bool) \Drupal\views\Entity\View::load('bbb_test_kit_recordings'), 'Recordings View exists');
    $rows[] = $this->bool((bool) \Drupal\block\Entity\Block::load('bbb_test_kit_recordings'), 'Recordings block placed');
    foreach (['bigbluebutton_join_meeting_link_rest_resource', 'bigbluebutton_meeting_info_rest_resource'] as $r) {
      $rows[] = $this->bool((bool) \Drupal\rest\Entity\RestResourceConfig::load($r), "REST resource enabled: $r");
    }
    $rows[] = $this->bool((bool) $this->room(), 'Demo room exists',
      $this->room() ? '/node/' . $this->room()->id() : '');
    return $rows;
  }

  private function checkRoles(): array {
    $rows = [];
    foreach (['bbb_test_kit_moderator', 'bbb_test_kit_editor', 'bbb_test_kit_demoted', 'bbb_test_kit_recorder'] as $rid) {
      $rows[] = $this->bool((bool) \Drupal\user\Entity\Role::load($rid), "Role $rid exists");
    }
    // The role-alter hook: resolve the meeting role for representative users.
    $room = $this->room();
    if (!$room) {
      $rows[] = $this->row(self::SKIP, 'Role hook resolution', 'No demo room.');
      return $rows;
    }
    $expected = [
      'bbb_test_kit_user_1' => 'moderator',
      'bbb_test_kit_user_3' => 'viewer',
      'bbb_test_kit_user_4' => 'viewer',
    ];
    $us = \Drupal::entityTypeManager()->getStorage('user');
    foreach ($expected as $name => $want) {
      $u = $us->loadByProperties(['name' => $name]);
      $u = $u ? reset($u) : NULL;
      if (!$u) {
        $rows[] = $this->row(self::SKIP, "Role hook: $name", 'User missing.');
        continue;
      }
      $role = $room->access('update', $u) ? 'moderator' : ($room->access('view', $u) ? 'viewer' : NULL);
      \Drupal::moduleHandler()->alter('bigbluebutton_meeting_role', $role, $room, $u);
      $rows[] = $this->bool($role === $want, "Role hook: $name → $want",
        "resolved: $role", "resolved: " . ($role ?? 'none'));
    }
    return $rows;
  }

  private function checkAccess(): array {
    $rows = [];
    $view = \Drupal\views\Entity\View::load('bbb_test_kit_recordings');
    if (!$view) {
      return [$this->row(self::SKIP, 'Recordings block access', 'View missing.')];
    }
    $us = \Drupal::entityTypeManager()->getStorage('user');
    foreach (['bbb_test_kit_user_5' => TRUE, 'bbb_test_kit_user_4' => FALSE] as $name => $shouldSee) {
      $u = $us->loadByProperties(['name' => $name]);
      $u = $u ? reset($u) : NULL;
      if (!$u) {
        $rows[] = $this->row(self::SKIP, "Block access: $name", 'User missing.');
        continue;
      }
      $exe = \Drupal\views\Views::getView('bbb_test_kit_recordings');
      $exe->setDisplay('block_1');
      $can = (bool) $exe->access('block_1', $u);
      $rows[] = $this->bool($can === $shouldSee,
        "Recordings block visible to $name: " . ($shouldSee ? 'yes' : 'no'),
        'as expected', 'got: ' . ($can ? 'yes' : 'no'));
    }
    return $rows;
  }

  private function checkGuestName(): array {
    $format = (string) \Drupal::config('bigbluebutton.settings')->get('user_display_name');
    if ($format === '') {
      return [$this->row(self::SKIP, 'Guest name from ?registration=', 'user_display_name not configured.')];
    }
    $token = \Drupal::token();
    $rows = [];
    $regs = \Drupal::entityTypeManager()->getStorage('registration')
      ->loadByProperties(['type' => TestKitInstaller::REGISTRATION_TYPE]);
    if (!$regs) {
      return [$this->row(self::SKIP, 'Guest name from ?registration=', 'No sample registrations.')];
    }
    foreach ($regs as $reg) {
      $name = (string) ($reg->get('field_full_name')->value ?? '');
      $resolved = trim(strip_tags($token->replace($format, ['registration' => $reg], ['clear' => TRUE])));
      $rows[] = $this->bool($resolved === $name && $name !== '',
        "Registration UUID resolves to \"$name\"",
        "join link would use fullName=\"$resolved\"",
        "resolved: \"$resolved\"");
    }
    return $rows;
  }

  private function bbb(): ?BBB {
    $c = \Drupal::config('bigbluebutton.settings');
    if (!$c->get('hostname') || !$c->get('secret')) {
      return NULL;
    }
    return new BBB($c->get('secret'), $c->get('hostname'));
  }

  private function checkServer(): array {
    $bbb = $this->bbb();
    if (!$bbb) {
      return [$this->row(self::SKIP, 'BBB server reachable', 'No host/secret set.')];
    }
    $rows = [];
    try {
      $xml = $bbb->getApiVersion()->getRawXml();
      $ok = (string) ($xml->returncode ?? '') === 'SUCCESS';
      $rows[] = $this->bool($ok, 'BBB API answers',
        'apiVersion ' . (string) ($xml->apiVersion ?? $xml->version ?? '?') . ', bbbVersion ' . (string) ($xml->bbbVersion ?? '?'));
    }
    catch (\Throwable $e) {
      $rows[] = $this->row(self::FAIL, 'BBB API answers', $e->getMessage());
      return $rows;
    }
    try {
      $ok = (string) $bbb->getMeetings()->getRawXml()->returncode === 'SUCCESS';
      $rows[] = $this->bool($ok, 'Secret accepted (signed call)', 'getMeetings SUCCESS', 'checksum rejected');
    }
    catch (\Throwable $e) {
      $rows[] = $this->row(self::FAIL, 'Secret accepted (signed call)', $e->getMessage());
    }
    $room = $this->room();
    if ($room) {
      try {
        $info = $bbb->getMeetingInfo(new GetMeetingInfoParameters($room->uuid(), ''))->getRawXml();
        $rc = (string) ($info->returncode ?? '');
        $rows[] = $this->row(self::PASS, 'Meeting-info for demo room',
          $rc === 'SUCCESS' ? 'running' : 'idle (no meeting) — normal');
      }
      catch (\Throwable $e) {
        $rows[] = $this->row(self::FAIL, 'Meeting-info for demo room', $e->getMessage());
      }
    }
    return $rows;
  }

  private function checkRecordings(): array {
    $bbb = $this->bbb();
    $room = $this->room();
    if (!$bbb || !$room) {
      return [$this->row(self::SKIP, 'Recordings for demo room', 'No server or room.')];
    }
    try {
      $params = new GetRecordingsParameters();
      $params->setMeetingID($room->uuid());
      $xml = $bbb->getRecordings($params)->getRawXml();
      $count = isset($xml->recordings->recording) ? count($xml->recordings->recording) : 0;
      $formats = [];
      foreach ($xml->recordings->recording ?? [] as $rec) {
        foreach ($rec->playback->format ?? [] as $fmt) {
          $formats[(string) $fmt->type] = TRUE;
        }
      }
      return [$this->row(self::PASS, 'Recordings for demo room',
        $count === 0
          ? 'none yet (record a meeting in this room to populate)'
          : "$count recording(s); formats: " . implode(', ', array_keys($formats)))];
    }
    catch (\Throwable $e) {
      return [$this->row(self::FAIL, 'Recordings for demo room', $e->getMessage())];
    }
  }

  /**
   * Loads a test user by name.
   */
  private function userByName(string $name): ?\Drupal\user\UserInterface {
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    return $users ? reset($users) : NULL;
  }

  /**
   * Safe join-link assertion: builds a signed join URL WITHOUT creating a
   * meeting (getJoinMeetingURL only signs a URL; it makes no server call).
   */
  private function checkJoinLink(): array {
    $bbb = $this->bbb();
    $room = $this->room();
    if (!$bbb || !$room) {
      return [$this->row(self::SKIP, 'Signed join link builds', 'No server/room.')];
    }
    try {
      // Third arg is a password (2.x) or role|password (3.x); a string works
      // in both. This does not create or join a meeting.
      $params = new JoinMeetingParameters($room->uuid(), 'Test User', 'x');
      $url = $bbb->getJoinMeetingURL($params);
      $host = (string) parse_url((string) \Drupal::config('bigbluebutton.settings')->get('hostname'), PHP_URL_HOST);
      $ok = str_contains($url, 'checksum=')
        && stripos($url, 'fullName=') !== FALSE
        && ($host === '' || str_contains($url, $host));
      return [$this->bool($ok, 'Signed join link builds for the room (no meeting created)',
        'URL carries fullName + checksum on host ' . $host,
        'unexpected join URL: ' . substr($url, 0, 80))];
    }
    catch (\Throwable $e) {
      return [$this->row(self::FAIL, 'Signed join link builds', $e->getMessage())];
    }
  }

  /**
   * Security-fix checks. These FAIL on the current release and flip to PASS
   * as each tracker fix is applied. They inspect routes, config and the
   * library version (deterministic, side-effect free) plus one live check.
   */
  private function checkSecurity(): array {
    $rows = [];
    $routes = \Drupal::service('router.route_provider');

    // BBB-01: recording download endpoint no longer takes a raw ?url=.
    try {
      $path = $routes->getRouteByName('bigbluebutton.download_recording')->getPath();
      $rows[] = $this->bool(str_contains($path, '{recording_id}'),
        'BBB-01: recording download endpoint hardened (SSRF)',
        'route bound to a recording: ' . $path,
        'still accepts an arbitrary ?url= — route: ' . $path);
    }
    catch (\Throwable) {
      $rows[] = $this->row(self::SKIP, 'BBB-01: download endpoint', 'route not found');
    }

    // BBB-04: recording view route enforces per-entity access.
    try {
      $route = $routes->getRouteByName('bigbluebutton.view_recording');
      $rows[] = $this->bool($route->hasRequirement('_bigbluebutton_entity_access'),
        'BBB-04: recording view enforces entity access',
        'entity access check present',
        'only a global permission gates recording view (IDOR)');
    }
    catch (\Throwable) {
      $rows[] = $this->row(self::SKIP, 'BBB-04: recording view route', 'route not found');
    }

    // BBB-07: meeting-end callback is not open to everyone with "access content".
    try {
      $route = $routes->getRouteByName('bigbluebutton.meeting_end');
      $perm = $route->getRequirement('_permission');
      $rows[] = $this->bool($perm !== 'access content',
        'BBB-07: meeting-end callback secured',
        'no longer public via "access content"',
        'callback open to any user with "access content" (no token)');
    }
    catch (\Throwable) {
      $rows[] = $this->row(self::SKIP, 'BBB-07: meeting-end route', 'route not found');
    }

    // BBB-15: library upgraded to role-based join.
    $lib = $this->environment()['library'];
    $clean = preg_replace('/[^0-9.].*$/', '', $lib);
    $libOk = $lib !== 'unknown' && $clean !== '' && version_compare($clean, '3.0', '>=');
    $rows[] = $this->bool($libOk,
      'BBB-15: bigbluebutton-api-php >= 3.0 (role-based join)',
      'library ' . $lib,
      'library ' . $lib . ' still uses meeting passwords');

    // BBB-02: meeting-info must not expose passwords to a viewer.
    $rows[] = $this->checkMeetingInfoPasswords();

    // BBB-06: meeting passwords must not be hidden inputs in the edit form.
    $rows[] = $this->checkWidgetPasswords();

    // BBB-10: the secret field must be a password field, not plain text.
    $rows[] = $this->checkSecretField();

    // BBB-12: the host check must be hardened (probeBBBServer + timeouts).
    $rows[] = $this->bool(
      method_exists('\Drupal\bigbluebutton\BigBlueButtonHelper', 'probeBBBServer'),
      'BBB-12: BBB host check hardened (timeouts / verification)',
      'probeBBBServer() present',
      'isValidExternalURL() still does an unbounded GET');

    // BBB-13: an unknown / non-BBB entity must be handled, not a PHP error.
    $rows[] = $this->checkErrorHandling();

    // BBB-14: the presentation upload must use the D11 validator plugin.
    $rows[] = $this->checkPresentationValidator();

    // BBB-08: passwords must not be dumped to the log.
    $rows[] = $this->checkLogLeak();

    // Issues that can only be confirmed by code review — shown so the client
    // knows they exist and must be checked, not silently omitted.
    foreach ([
      'BBB-03: join display name restricted to owned/verified registrations',
      'BBB-05: access + enabled checked before a meeting is created or the entity saved',
      'BBB-09: recording delete requires edit access to the meeting entity',
      'BBB-11: logout URL and message tokens validated',
    ] as $label) {
      $rows[] = $this->row(self::SKIP, $label, 'verify by code review / manual test');
    }

    return $rows;
  }

  /**
   * BBB-06: builds the room edit form and checks the password widget type.
   */
  private function checkWidgetPasswords(): array {
    $room = $this->room();
    if (!$room) {
      return $this->row(self::SKIP, 'BBB-06: meeting passwords not exposed in the edit form', 'No room.');
    }
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo(\Drupal\user\Entity\User::load(1));
    try {
      $form = \Drupal::service('entity.form_builder')->getForm($room, 'default');
      $type = $form['field_bbb']['widget'][0]['moderator_pw']['#type'] ?? NULL;
      // 'hidden' renders into the page HTML (vulnerable); 'value' does not.
      $ok = $type !== NULL && $type !== 'hidden';
      return $this->bool($ok, 'BBB-06: meeting passwords not exposed in the edit form',
        'moderator_pw is #type ' . ($type ?? 'absent'),
        'moderator_pw is a hidden input in the page HTML');
    }
    catch (\Throwable $e) {
      return $this->row(self::SKIP, 'BBB-06: meeting passwords not exposed in the edit form', $e->getMessage());
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * BBB-10: the settings-form secret field must be a password field.
   */
  private function checkSecretField(): array {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo(\Drupal\user\Entity\User::load(1));
    try {
      $form = \Drupal::formBuilder()->getForm('\Drupal\bigbluebutton\Form\SettingsForm');
      $type = $form['bbb_settings']['secret']['#type'] ?? NULL;
      $ok = $type === 'password';
      return $this->bool($ok, 'BBB-10: secret entered as a password field',
        'secret is #type password',
        'secret is a plain textfield prefilled with the value');
    }
    catch (\Throwable $e) {
      return $this->row(self::SKIP, 'BBB-10: secret entered as a password field', $e->getMessage());
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * BBB-13: a non-BBB entity must produce a handled exception, not a PHP error.
   */
  private function checkErrorHandling(): array {
    try {
      // The admin user has no BBB field; the current code calls a method on
      // FALSE here (a \Error), the fixed code throws an HTTP exception.
      \Drupal::service('bigbluebutton.helper')->getMeetingInfo('user', \Drupal\user\Entity\User::load(1)->uuid());
      return $this->row(self::PASS, 'BBB-13: unknown/non-BBB entity handled cleanly', 'no error thrown');
    }
    catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
      return $this->row(self::PASS, 'BBB-13: unknown/non-BBB entity handled cleanly',
        'handled with HTTP ' . $e->getStatusCode());
    }
    catch (\Error | \TypeError $e) {
      return $this->row(self::FAIL, 'BBB-13: unknown/non-BBB entity handled cleanly',
        'PHP error (500): ' . $e->getMessage());
    }
    catch (\Throwable $e) {
      return $this->row(self::PASS, 'BBB-13: unknown/non-BBB entity handled cleanly',
        'handled: ' . get_class($e));
    }
  }

  /**
   * BBB-14: the default-presentation upload must use the D11 validator plugin.
   */
  private function checkPresentationValidator(): array {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo(\Drupal\user\Entity\User::load(1));
    try {
      $form = \Drupal::formBuilder()->getForm('\Drupal\bigbluebutton\Form\DefaultPresentationForm');
      $validators = $form['default_presentation']['#upload_validators'] ?? [];
      $ok = isset($validators['FileExtension']) && !isset($validators['file_validate_extensions']);
      return $this->bool($ok, 'BBB-14: presentation upload uses the Drupal 11 validator',
        'FileExtension constraint used',
        'uses file_validate_extensions (removed in Drupal 11)');
    }
    catch (\Throwable $e) {
      return $this->row(self::SKIP, 'BBB-14: presentation upload uses the Drupal 11 validator', $e->getMessage());
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * BBB-08: the module must not dump BBB responses (with passwords) to the log.
   */
  private function checkLogLeak(): array {
    if (!\Drupal::moduleHandler()->moduleExists('dblog')) {
      return $this->row(self::SKIP, 'BBB-08: no password dumps in the log', 'dblog not enabled');
    }
    try {
      $found = (bool) \Drupal::database()->select('watchdog', 'w')
        ->condition('type', 'bigbluebutton')
        ->condition('message', '%<pre>%', 'LIKE')
        ->countQuery()->execute()->fetchField();
      return $this->bool(!$found, 'BBB-08: no password dumps in the log',
        'no <pre> response dumps found',
        'BBB responses dumped to watchdog (clear old rows after fixing)');
    }
    catch (\Throwable $e) {
      return $this->row(self::SKIP, 'BBB-08: no password dumps in the log', $e->getMessage());
    }
  }

  /**
   * Live BBB-02 check: does meeting-info leak passwords to a viewer?
   *
   * Only observable while a meeting is running (idle responses carry no
   * passwords regardless), so it SKIPs otherwise.
   */
  private function checkMeetingInfoPasswords(): array {
    $bbb = $this->bbb();
    $room = $this->room();
    if (!$bbb || !$room) {
      return $this->row(self::SKIP, 'BBB-02: meeting-info hides passwords from viewers', 'No server/room.');
    }
    try {
      $info = $bbb->getMeetingInfo(new GetMeetingInfoParameters($room->uuid(), ''))->getRawXml();
      if ((string) ($info->returncode ?? '') !== 'SUCCESS') {
        return $this->row(self::SKIP, 'BBB-02: meeting-info hides passwords from viewers',
          'no running meeting — start one in the demo room to test this');
      }
      $viewer = $this->userByName('bbb_test_kit_user_4');
      if (!$viewer) {
        return $this->row(self::SKIP, 'BBB-02: meeting-info hides passwords from viewers', 'viewer user missing');
      }
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($viewer);
      try {
        $data = \Drupal::service('bigbluebutton.helper')->getMeetingInfo('node', $room->uuid());
      }
      finally {
        $switcher->switchBack();
      }
      $json = json_encode($data);
      $leak = str_contains($json, 'attendeePW') || str_contains($json, 'moderatorPW');
      return $this->bool(!$leak,
        'BBB-02: meeting-info hides passwords from viewers',
        'no passwords in the viewer response',
        'passwords exposed to a plain viewer');
    }
    catch (\Throwable $e) {
      return $this->row(self::SKIP, 'BBB-02: meeting-info hides passwords from viewers', $e->getMessage());
    }
  }

  private function checkFeedback(): array {
    $ok = \Drupal::database()->schema()->tableExists('bbb_test_kit_feedback');
    return [$this->bool($ok, 'Feedback table exists', 'bbb_test_kit_feedback')];
  }

  /**
   * Ready-to-use manual test links: test users and per-guest join links.
   */
  public function manualResources(): array {
    $room = $this->room();
    $res = [
      'password' => TestKitInstaller::password(),
      'room_url' => $room ? $room->toUrl()->toString() : NULL,
      'users' => [
        ['name' => 'bbb_test_kit_user_1', 'info' => 'Moderator via role hook (no edit access)'],
        ['name' => 'bbb_test_kit_user_2', 'info' => 'Moderator (edit access)'],
        ['name' => 'bbb_test_kit_user_3', 'info' => 'Viewer (role hook demotes the editor)'],
        ['name' => 'bbb_test_kit_user_4', 'info' => 'Viewer'],
        ['name' => 'bbb_test_kit_user_5', 'info' => 'Viewer + recording view/download/delete'],
      ],
      'guests' => [],
    ];
    if ($room) {
      $regs = \Drupal::entityTypeManager()->getStorage('registration')
        ->loadByProperties(['type' => TestKitInstaller::REGISTRATION_TYPE]);
      foreach ($regs as $reg) {
        $name = (string) ($reg->get('field_full_name')->value ?? 'Guest');
        $who = $reg->getUser() ? 'registered user ' . $reg->getUser()->getAccountName() : 'guest ' . (string) $reg->get('anon_mail')->value;
        $res['guests'][] = [
          'name' => $name,
          'info' => $who . ' — open in a private window, then Join; the meeting should show "' . $name . '"',
          'url' => $room->toUrl('canonical', ['query' => ['registration' => $reg->uuid()], 'absolute' => TRUE])->toString(),
        ];
      }
    }
    return $res;
  }

}
