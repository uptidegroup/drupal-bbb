<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Drush\Commands;

use Drupal\bbb_test_kit\TestKitInstaller;
use Drupal\bbb_test_kit\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the BigBlueButton Test Kit.
 */
final class BbbTestKitCommands extends DrushCommands {

  /**
   * Show the test users, their shared password, and key URLs.
   */
  #[CLI\Command(name: 'bbb-test:info', aliases: ['bbbti'])]
  public function info(): void {
    $this->io()->section('BigBlueButton Test Kit');
    $this->io()->writeln('Shared password for all test users: <info>' . TestKitInstaller::password() . '</info>');
    $this->io()->listing([
      'bbb_test_kit_user_1 – moderator via the role hook (no edit access)',
      'bbb_test_kit_user_2 – moderator (edit access)',
      'bbb_test_kit_user_3 – viewer (role hook demotes the editor)',
      'bbb_test_kit_user_4 – viewer (also the registered-user sample)',
      'bbb_test_kit_user_5 – viewer + recording view/download/delete',
    ]);

    $nodes = \Drupal::entityTypeManager()->getStorage('node')
      ->loadByProperties(['type' => TestKitInstaller::NODE_TYPE]);
    foreach ($nodes as $node) {
      $this->io()->writeln('Room: /node/' . $node->id() . '  (uuid ' . $node->uuid() . ')');
    }
  }

  /**
   * Run every BBB Test Kit check and print a pass/fail report.
   *
   * Exits non-zero if any check failed, so it works in CI too.
   */
  #[CLI\Command(name: 'bbb-test:run', aliases: ['bbbt'])]
  public function run(): int {
    $result = (new TestRunner())->run();
    $env = $result['env'];
    $this->io()->title('BBB Test Kit results');
    $this->io()->writeln(sprintf('Drupal %s · PHP %s · library %s · BBB %s',
      $env['drupal'], $env['php'], $env['library'],
      $env['bbb_configured'] ? 'configured' : 'NOT configured (live checks skipped)'));

    $icon = ['pass' => '<info>PASS</info>', 'fail' => '<error>FAIL</error>', 'skip' => '<comment>SKIP</comment>'];
    foreach ($result['groups'] as $group => $rows) {
      $this->io()->section($group);
      foreach ($rows as $row) {
        $line = $icon[$row['status']] . '  ' . $row['label'];
        if ($row['detail'] !== '') {
          $line .= ' — ' . $row['detail'];
        }
        $this->io()->writeln($line);
      }
    }

    // Persist so the report page shows the same run.
    (new TestRunner())->saveLastRun($result);

    $t = $result['totals'];
    $this->io()->newLine();
    $this->io()->writeln(sprintf('Totals: <info>%d passed</info>, <error>%d failed</error>, <comment>%d skipped</comment>.',
      $t['pass'], $t['fail'], $t['skip']));

    $regressions = 0;
    if (!empty($result['deltas'])) {
      $d = $result['deltas'];
      $regressions = $d['regression'];
      $meta = $result['baseline_meta'];
      $this->io()->writeln(sprintf('Vs baseline (library %s): <error>%d regression(s)</error>, <info>%d fixed</info>, %d changed.',
        $meta['env']['library'] ?? '?', $d['regression'], $d['fixed'], $d['changed']));
      if ($regressions > 0) {
        $this->io()->error('REGRESSIONS: behaviour that worked on the baseline now fails after the upgrade.');
      }
    }
    else {
      $this->io()->writeln('<comment>No baseline saved — run "drush bbb-test:baseline" on the stable release first.</comment>');
    }

    $res = $result['resources'];
    $this->io()->section('Manual test links');
    $this->io()->writeln('Test users (password: <info>' . $res['password'] . '</info>):');
    foreach ($res['users'] as $u) {
      $this->io()->writeln("  {$u['name']} — {$u['info']}");
    }
    if ($res['guests']) {
      $this->io()->writeln('Registered-guest join links (open in a private window):');
      foreach ($res['guests'] as $g) {
        $this->io()->writeln("  {$g['name']}: {$g['url']}");
        $this->io()->writeln("    {$g['info']}");
      }
    }
    $this->io()->writeln('Browser-only checks: see docs/manual-tests.md');

    // Fail the command on any failure, or on a regression vs the baseline.
    return ($t['fail'] > 0 || $regressions > 0) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Save the current results as the baseline (run on the STABLE release).
   */
  #[CLI\Command(name: 'bbb-test:baseline', aliases: ['bbbtb'])]
  public function baseline(): void {
    $b = (new TestRunner())->captureBaseline();
    $this->io()->success(sprintf('Baseline saved (library %s, %d checks). Now switch to the dev version, run "drush deploy" / updb, then "drush bbb-test:run".',
      $b['env']['library'] ?? '?', count($b['checks'])));
  }

  /**
   * Rebuild the test fixture (tear down, then build again).
   */
  #[CLI\Command(name: 'bbb-test:rebuild', aliases: ['bbbtr'])]
  public function rebuild(): void {
    $installer = new TestKitInstaller();
    $installer->tearDown();
    foreach ($installer->build() as $line) {
      $this->io()->writeln($line);
    }
    $this->io()->success('Test fixture rebuilt.');
  }

}
