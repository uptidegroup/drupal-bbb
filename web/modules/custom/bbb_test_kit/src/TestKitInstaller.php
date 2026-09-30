<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit;

use Drupal\block\Entity\Block;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\registration\Entity\Registration;
use Drupal\registration\Entity\RegistrationType;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Builds and tears down the BigBlueButton test fixture through the entity API.
 *
 * Everything is created in code (never config/install YAML) so the same
 * module installs identically on Drupal 10.3+ and Drupal 11: each create
 * call inherits whatever defaults the running core provides. Every step is
 * idempotent, so build() can run again without creating duplicates.
 */
final class TestKitInstaller {

  /**
   * State key holding the generated password shared by all test users.
   */
  public const PASSWORD_STATE_KEY = 'bbb_test_kit.test_password';

  /**
   * Machine name of the node type this kit creates.
   */
  public const NODE_TYPE = 'bbb_test_kit_room';

  /**
   * Machine name of the registration type this kit creates.
   */
  public const REGISTRATION_TYPE = 'bbb_test_kit_guest';

  /**
   * Test user names, mapped to the roles each one gets.
   */
  private const USERS = [
    'bbb_test_kit_user_1' => ['bbb_test_kit_moderator'],
    'bbb_test_kit_user_2' => ['bbb_test_kit_editor'],
    'bbb_test_kit_user_3' => ['bbb_test_kit_editor', 'bbb_test_kit_demoted'],
    'bbb_test_kit_user_4' => [],
    'bbb_test_kit_user_5' => ['bbb_test_kit_recorder'],
  ];

  /**
   * Collected human-readable log of what build()/tearDown() did.
   *
   * @var string[]
   */
  private array $log = [];

  /**
   * Builds the whole fixture. Returns a log of what happened.
   *
   * @return string[]
   */
  public function build(): array {
    $this->log = [];
    $this->createContentType();
    $this->createFields();
    $this->createRegistrationType();
    $this->createDisplays();
    $this->createRecordingsView();
    $this->createRecordingsBlock();
    $this->createRestResources();
    $this->createRolesAndUsers();
    $this->createPresentations();
    $this->configureModule();
    $node = $this->createRoom();
    $this->createRegistrations($node);
    return $this->log;
  }

  /**
   * Removes what build() created. Returns a log.
   *
   * @return string[]
   */
  public function tearDown(): array {
    $this->log = [];
    $etm = \Drupal::entityTypeManager();

    // Registrations, then the room, then the type.
    foreach ($etm->getStorage('registration')->loadByProperties(['type' => self::REGISTRATION_TYPE]) as $registration) {
      $registration->delete();
    }
    foreach ($etm->getStorage('node')->loadByProperties(['type' => self::NODE_TYPE]) as $node) {
      $node->delete();
    }
    // Recordings block, View and view mode.
    $this->deleteIfExists(Block::load('bbb_test_kit_recordings'));
    $this->deleteIfExists(View::load('bbb_test_kit_recordings'));
    $this->deleteIfExists(EntityViewMode::load('node.recordings'));

    $this->deleteIfExists(NodeType::load(self::NODE_TYPE));
    $this->deleteIfExists(RegistrationType::load(self::REGISTRATION_TYPE));

    // Fields owned by this kit (leave core 'body' storage in place).
    foreach (['field_bbb_presentation', 'field_bbb_start', 'field_bbb_registration'] as $name) {
      $this->deleteIfExists(FieldStorageConfig::loadByName('node', $name));
    }
    $this->deleteIfExists(FieldStorageConfig::loadByName('registration', 'field_full_name'));

    // Test users and roles.
    foreach (array_keys(self::USERS) as $name) {
      foreach ($etm->getStorage('user')->loadByProperties(['name' => $name]) as $user) {
        $user->delete();
      }
    }
    foreach (['bbb_test_kit_moderator', 'bbb_test_kit_editor', 'bbb_test_kit_demoted', 'bbb_test_kit_recorder'] as $rid) {
      $this->deleteIfExists(Role::load($rid));
    }

    \Drupal::state()->delete(self::PASSWORD_STATE_KEY);
    \Drupal::keyValue('bigbluebutton')->delete('default_presentation');
    $this->note('Test fixture removed.');
    return $this->log;
  }

  /**
   * The shared password for the test users (generated once, kept in state).
   */
  public static function password(): string {
    $state = \Drupal::state();
    $password = $state->get(self::PASSWORD_STATE_KEY);
    if (!$password) {
      $password = 'bbb-' . bin2hex(random_bytes(6));
      $state->set(self::PASSWORD_STATE_KEY, $password);
    }
    return $password;
  }

  private function createContentType(): void {
    if (!NodeType::load(self::NODE_TYPE)) {
      NodeType::create([
        'type' => self::NODE_TYPE,
        'name' => 'BBB Test Kit room',
        'description' => 'A BigBlueButton video conference room (test kit).',
        'new_revision' => TRUE,
        'display_submitted' => FALSE,
      ])->save();
      $this->note('Created content type "BBB Test Kit room".');
    }
  }

  private function createFields(): void {
    // Core body field.
    $this->ensureFieldStorage('node', 'body', 'text_with_summary', ['persist_with_no_fields' => TRUE]);
    $this->ensureField('node', self::NODE_TYPE, 'body', 'Body', ['display_summary' => TRUE]);

    // BigBlueButton meeting field.
    $this->ensureFieldStorage('node', 'field_bbb', 'bigbluebutton');
    $this->ensureField('node', self::NODE_TYPE, 'field_bbb', 'BigBlueButton meeting');

    // Presentation PDF: the helper only accepts file fields limited to pdf.
    $this->ensureFieldStorage('node', 'field_bbb_presentation', 'file');
    $this->ensureField('node', self::NODE_TYPE, 'field_bbb_presentation', 'Presentation (PDF)', [
      'file_extensions' => 'pdf',
      'file_directory' => 'bbb_test_kit',
    ]);

    // Meeting start (used by the join countdown).
    $this->ensureFieldStorage('node', 'field_bbb_start', 'datetime', [], ['datetime_type' => 'datetime']);
    $this->ensureField('node', self::NODE_TYPE, 'field_bbb_start', 'Meeting start', [], 'Before this time only moderators may join; everyone else sees a countdown.');

    // Guest registration host field.
    $this->ensureFieldStorage('node', 'field_bbb_registration', 'registration');
    $this->ensureField('node', self::NODE_TYPE, 'field_bbb_registration', 'Guest registration');
  }

  private function createRegistrationType(): void {
    if (!RegistrationType::load(self::REGISTRATION_TYPE)) {
      RegistrationType::create([
        'id' => self::REGISTRATION_TYPE,
        'label' => 'BBB Test Kit guest invitation',
        'workflow' => 'registration',
        'defaultState' => 'complete',
      ])->save();
      $this->note('Created registration type "' . self::REGISTRATION_TYPE . '".');
    }
    $this->ensureFieldStorage('registration', 'field_full_name', 'string');
    $this->ensureField('registration', self::REGISTRATION_TYPE, 'field_full_name', 'Full name', [], '', TRUE);
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('registration', self::REGISTRATION_TYPE, 'default')
      ->setComponent('field_full_name', ['type' => 'string_textfield', 'weight' => -10])
      ->save();
  }

  private function createDisplays(): void {
    $displays = \Drupal::service('entity_display.repository');

    $form = $displays->getFormDisplay('node', self::NODE_TYPE, 'default');
    $form->setComponent('title', ['type' => 'string_textfield', 'weight' => -5]);
    $form->setComponent('body', ['type' => 'text_textarea_with_summary', 'weight' => 0]);
    $form->setComponent('field_bbb_start', ['type' => 'datetime_default', 'weight' => 1]);
    $form->setComponent('field_bbb_presentation', ['type' => 'file_generic', 'weight' => 3]);
    $form->setComponent('field_bbb_registration', ['type' => 'registration_type', 'weight' => 4]);
    $form->setComponent('field_bbb', ['type' => 'bigbluebutton_default', 'weight' => 5]);
    $form->save();

    $view = $displays->getViewDisplay('node', self::NODE_TYPE, 'default');
    $view->setComponent('body', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0]);
    $view->setComponent('field_bbb_start', ['type' => 'datetime_default', 'label' => 'inline', 'weight' => 1, 'settings' => ['format_type' => 'long']]);
    $view->setComponent('field_bbb_presentation', ['type' => 'file_default', 'label' => 'above', 'weight' => 2]);
    $view->setComponent('field_bbb', [
      'type' => 'bigbluebutton_default',
      'label' => 'hidden',
      'weight' => 5,
      'settings' => ['link_title' => 'Join meeting', 'link_classes' => 'button button--primary'],
    ]);
    $view->removeComponent('field_bbb_registration');
    $view->save();

    // A dedicated "recordings" view mode: only field_bbb, rendered with the
    // recordings formatter (links). A Views block renders the node in this
    // view mode on its own page (see createRecordingsView()).
    if (!EntityViewMode::load('node.recordings')) {
      EntityViewMode::create([
        'id' => 'node.recordings',
        'targetEntityType' => 'node',
        'label' => 'BBB recordings',
      ])->save();
    }
    // Build the display directly with only field_bbb visible. Base fields
    // that carry default display options (uid/title/created, the node links)
    // must be listed in 'hidden', or EntityDisplayBase::init() re-adds them
    // on every save.
    if (!EntityViewDisplay::load('node.' . self::NODE_TYPE . '.recordings')) {
      EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => self::NODE_TYPE,
        'mode' => 'recordings',
        'status' => TRUE,
        'content' => [
          'field_bbb' => [
            'type' => 'bigbluebutton_recordings',
            'label' => 'hidden',
            'weight' => 0,
            'region' => 'content',
            'settings' => ['display_options' => 'links', 'supported_formats' => ['video' => 'video']],
            'third_party_settings' => [],
          ],
        ],
        'hidden' => [
          'uid' => TRUE,
          'title' => TRUE,
          'created' => TRUE,
          'links' => TRUE,
          'langcode' => TRUE,
          'body' => TRUE,
          'field_bbb_start' => TRUE,
          'field_bbb_presentation' => TRUE,
          'field_bbb_registration' => TRUE,
        ],
      ])->save();
    }
  }

  /**
   * A view-mode View of the room's recordings, exposed as a block.
   *
   * Renders the node in the "recordings" view mode (field_bbb only), so the
   * recordings formatter shows one or more recordings. Contextual filter:
   * Content ID from the URL. Access: the module's recording permission, so
   * only users allowed to see recordings get the block.
   */
  private function createRecordingsView(): void {
    if (View::load('bbb_test_kit_recordings')) {
      return;
    }
    $options = [
      'title' => 'Meeting recordings',
      'fields' => [],
      'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access bigbluebutton recording']],
      'cache' => ['type' => 'none', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => FALSE]],
      'style' => ['type' => 'default', 'options' => ['row_class' => '', 'default_row_class' => TRUE]],
      'row' => ['type' => 'entity:node', 'options' => ['view_mode' => 'recordings']],
      'filters' => [
        'status' => [
          'id' => 'status', 'table' => 'node_field_data', 'field' => 'status',
          'plugin_id' => 'boolean', 'value' => '1', 'group' => 1, 'entity_type' => 'node', 'entity_field' => 'status',
        ],
        'type' => [
          'id' => 'type', 'table' => 'node_field_data', 'field' => 'type',
          'plugin_id' => 'bundle', 'value' => [self::NODE_TYPE => self::NODE_TYPE],
          'entity_type' => 'node', 'entity_field' => 'type',
        ],
      ],
      'arguments' => [
        'nid' => [
          'id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'plugin_id' => 'node_nid',
          'entity_type' => 'node', 'entity_field' => 'nid',
          // 'default' = "Provide default value", which activates
          // default_argument_type below (Content ID from the URL). 'empty'
          // would ignore the default and just show empty text.
          'default_action' => 'default',
          'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
          'default_argument_type' => 'node',
          'default_argument_options' => [],
          'specify_validation' => TRUE,
          'validate' => ['type' => 'entity:node', 'fail' => 'empty'],
          'validate_options' => ['bundles' => [self::NODE_TYPE => self::NODE_TYPE], 'access' => FALSE, 'operation' => 'view', 'multiple' => 0],
          'break_phrase' => FALSE,
        ],
      ],
      'display_extenders' => [],
    ];

    View::create([
      'id' => 'bbb_test_kit_recordings',
      'label' => 'BBB Test Kit recordings',
      'module' => 'views',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'description' => 'Recordings of a BBB Test Kit room, rendered in the recordings view mode.',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => $options,
        ],
        'block_1' => [
          'id' => 'block_1',
          'display_title' => 'Recordings block',
          'display_plugin' => 'block',
          'position' => 1,
          'display_options' => [
            'display_extenders' => [],
            'block_description' => 'BBB Test Kit: meeting recordings',
          ],
        ],
      ],
    ])->save();
    $this->note('Created recordings View (block: views_block:bbb_test_kit_recordings-block_1).');
  }

  /**
   * Places the recordings View block in the theme's content region.
   *
   * The content region exists in every theme, so this is safe without
   * knowing the client's theme. Visible only on BBB Test Kit room nodes.
   */
  private function createRecordingsBlock(): void {
    $theme = \Drupal::config('system.theme')->get('default');
    $id = 'bbb_test_kit_recordings';
    if (Block::load($id)) {
      return;
    }
    Block::create([
      'id' => $id,
      'theme' => $theme,
      'region' => 'content',
      'weight' => 10,
      'plugin' => 'views_block:bbb_test_kit_recordings-block_1',
      'settings' => [
        'id' => 'views_block:bbb_test_kit_recordings-block_1',
        'label' => 'Meeting recordings',
        'label_display' => 'visible',
        'views_label' => '',
      ],
      'visibility' => [
        'entity_bundle:node' => [
          'id' => 'entity_bundle:node',
          'bundles' => [self::NODE_TYPE => self::NODE_TYPE],
          'negate' => FALSE,
          'context_mapping' => ['node' => '@node.node_route_context:node'],
        ],
      ],
    ])->save();
    $this->note("Placed recordings block in the '$theme' content region.");
  }

  private function createRestResources(): void {
    foreach ([
      'bigbluebutton_join_meeting_link_rest_resource',
      'bigbluebutton_meeting_info_rest_resource',
    ] as $id) {
      if (!RestResourceConfig::load($id)) {
        RestResourceConfig::create([
          'id' => $id,
          'plugin_id' => $id,
          'granularity' => 'resource',
          'configuration' => [
            'methods' => ['GET'],
            'formats' => ['json'],
            'authentication' => ['cookie'],
          ],
        ])->save();
        $this->note("Enabled REST resource: $id");
      }
    }
  }

  private function createRolesAndUsers(): void {
    $edit = self::NODE_TYPE;
    $roles = [
      'bbb_test_kit_moderator' => ['BBB Test Kit: moderator (via role hook)', []],
      'bbb_test_kit_editor' => ['BBB Test Kit: editor', ["create $edit content", "edit any $edit content", 'access content overview']],
      'bbb_test_kit_demoted' => ['BBB Test Kit: demoted (never moderator)', []],
      'bbb_test_kit_recorder' => ['BBB Test Kit: recordings access', [
        'access bigbluebutton recording',
        'access bigbluebutton video download',
        'delete bigbluebutton recording',
      ]],
    ];
    foreach ($roles as $rid => [$label, $perms]) {
      $role = Role::load($rid) ?? Role::create(['id' => $rid, 'label' => $label]);
      foreach ($perms as $perm) {
        $role->grantPermission($perm);
      }
      $role->save();
    }

    $this->grantPermissions('anonymous', [
      'access content',
      'restful get bigbluebutton_join_meeting_link_rest_resource',
    ]);
    $this->grantPermissions('authenticated', [
      'access content',
      'restful get bigbluebutton_join_meeting_link_rest_resource',
      'restful get bigbluebutton_meeting_info_rest_resource',
    ]);

    $password = self::password();
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    foreach (self::USERS as $name => $user_roles) {
      $existing = $storage->loadByProperties(['name' => $name]);
      $account = $existing ? reset($existing) : User::create(['name' => $name, 'mail' => "$name@example.com"]);
      $account->setPassword($password);
      foreach ($user_roles as $rid) {
        $account->addRole($rid);
      }
      $account->activate()->save();
    }
    $this->note('Test users ready (password via "drush bbb-test:info"): ' . implode(', ', array_keys(self::USERS)));
  }

  private function createPresentations(): void {
    $fs = \Drupal::service('file_system');
    $dir = 'public://bbb_test_kit';
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $room = $this->savePdf("$dir/room-presentation.pdf", 'BBB Test Kit room', 'Room presentation from field_bbb_presentation');
    $default = $this->savePdf("$dir/default-presentation.pdf", 'BBB default presentation', 'Used when a room has no presentation of its own');
    \Drupal::keyValue('bigbluebutton')->set('default_presentation', [$default->id()]);
    \Drupal::state()->set('bbb_test_kit.room_presentation_fid', $room->id());
  }

  private function configureModule(): void {
    // One display-name config resolves to a registered guest's name (via
    // ?registration=<uuid>) or the account name; the other token is cleared.
    \Drupal::configFactory()->getEditable('bigbluebutton.settings')
      ->set('user_display_name', '[registration:field_full_name][user:display-name]')
      ->save();
  }

  private function createRoom(): Node {
    $existing = \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['type' => self::NODE_TYPE, 'title' => 'BBB Test Kit demo room']);
    $node = $existing ? reset($existing) : Node::create(['type' => self::NODE_TYPE, 'title' => 'BBB Test Kit demo room', 'uid' => 1]);
    $node->setPublished();

    $room_fid = \Drupal::state()->get('bbb_test_kit.room_presentation_fid');
    if ($room_fid) {
      $node->set('field_bbb_presentation', ['target_id' => $room_fid, 'display' => 1]);
    }
    $node->set('field_bbb_registration', ['registration_type' => self::REGISTRATION_TYPE]);
    $node->set('field_bbb_start', (new DrupalDateTime('+10 minutes', 'UTC'))->format('Y-m-d\TH:i:s'));
    $node->set('field_bbb', [
      'enabled' => 1,
      'welcome' => 'Welcome to [node:title] on [site:name]! Room [node:nid].',
      'moderator_only_message' => 'Moderators of [node:title]: auto-record is on. Room page: [node:url]',
      'logout_url' => '[site:base-url]/bbb-test-kit-feedback/[node:nid]',
      'guest_policy' => 'ASK_MODERATOR',
      'record' => 1,
      'mute_on_start' => 1,
      'presentation_source' => 'field_bbb_presentation',
    ]);
    $node->set('body', [
      'format' => 'basic_html',
      'value' => '<p>Test room created by the BigBlueButton Test Kit. Do not use for real meetings.</p>',
    ]);
    $node->save();
    $this->note('Test room ready: /node/' . $node->id() . ' (uuid ' . $node->uuid() . ').');
    return $node;
  }

  private function createRegistrations(Node $node): void {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    // user_4 is the plain viewer used for the "registered user" sample.
    $viewer = $storage->loadByProperties(['name' => 'bbb_test_kit_user_4']);
    $viewer = $viewer ? reset($viewer) : NULL;

    // Open the room for registration.
    $host = $node->get('field_bbb_registration')->createHostEntity();
    $settings = $host->getSettings();
    $settings->set('status', TRUE)->set('capacity', 0)->set('maximum_spaces', 1)->save();

    $rows = [
      ['field_full_name' => 'Viola Viewer (registered)', 'user_uid' => $viewer?->id() ?? 0],
      ['field_full_name' => 'Gina Guest', 'anon_mail' => 'gina.guest@example.com'],
    ];
    $reg_storage = \Drupal::entityTypeManager()->getStorage('registration');
    foreach ($rows as $values) {
      $existing = $reg_storage->loadByProperties([
        'entity_type_id' => 'node',
        'entity_id' => $node->id(),
        'field_full_name' => $values['field_full_name'],
      ]);
      $registration = $existing ? reset($existing) : Registration::create($values + [
        'type' => self::REGISTRATION_TYPE,
        'workflow' => 'registration',
        'entity_type_id' => 'node',
        'entity_id' => $node->id(),
        'state' => 'complete',
        'count' => 1,
      ]);
      $registration->save();
      $this->note('Registration "' . $values['field_full_name'] . '": ?registration=' . $registration->uuid());
    }
  }

  // ---------------------------------------------------------------------------
  // Helpers.
  // ---------------------------------------------------------------------------

  private function ensureFieldStorage(string $entity_type, string $name, string $type, array $settings = [], array $type_settings = []): void {
    if (!FieldStorageConfig::loadByName($entity_type, $name)) {
      $values = [
        'field_name' => $name,
        'entity_type' => $entity_type,
        'type' => $type,
      ];
      $merged = $settings + $type_settings;
      if ($merged) {
        $values['settings'] = $merged;
      }
      FieldStorageConfig::create($values)->save();
    }
  }

  private function ensureField(string $entity_type, string $bundle, string $name, string $label, array $settings = [], string $description = '', bool $required = FALSE): void {
    if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $label,
        'description' => $description,
        'required' => $required,
        'settings' => $settings,
      ])->save();
    }
  }

  private function grantPermissions(string $rid, array $perms): void {
    if ($role = Role::load($rid)) {
      foreach ($perms as $perm) {
        $role->grantPermission($perm);
      }
      $role->save();
    }
  }

  private function savePdf(string $uri, string $title, string $subtitle): File {
    file_put_contents($uri, $this->pdf($title, $subtitle));
    $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = $existing ? reset($existing) : File::create(['uri' => $uri, 'uid' => 1]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Builds a minimal one-page PDF with a title and subtitle.
   */
  private function pdf(string $title, string $subtitle): string {
    $esc = static fn (string $s): string => strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    $stream = 'BT /F1 28 Tf 72 460 Td (' . $esc($title) . ') Tj ET' . "\n"
      . 'BT /F1 16 Tf 72 420 Td (' . $esc($subtitle) . ') Tj ET';
    $objects = [
      '<< /Type /Catalog /Pages 2 0 R >>',
      '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
      '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
      '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
      '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
      $offsets[] = strlen($pdf);
      $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
      $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
  }

  private function deleteIfExists($entity): void {
    if ($entity) {
      $entity->delete();
    }
  }

  private function note(string $message): void {
    $this->log[] = $message;
  }

}
