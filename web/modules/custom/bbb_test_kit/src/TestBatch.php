<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Drupal Batch callbacks that run the BBB Test Kit checks group by group.
 *
 * Running one group per batch step gives a progress bar and keeps the live
 * BBB server calls off a single long page request (no timeouts).
 */
final class TestBatch {

  use StringTranslationTrait;

  /**
   * Builds the batch definition covering every check group.
   */
  public static function build(): array {
    $operations = [];
    foreach ((new TestRunner())->groupNames() as $name) {
      $operations[] = [[self::class, 'step'], [$name]];
    }
    return [
      'title' => t('Running BBB Test Kit checks'),
      'operations' => $operations,
      'finished' => [self::class, 'finished'],
      'progress_message' => t('Checked @current of @total groups.'),
    ];
  }

  /**
   * One step: run a single group and stash its rows.
   */
  public static function step(string $name, array &$context): void {
    $context['results']['groups'][$name] = (new TestRunner())->runGroup($name);
    $context['message'] = t('Checking: @group', ['@group' => $name]);
  }

  /**
   * Assembles and stores the finished result.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $runner = new TestRunner();
    $result = $runner->finalize($results['groups'] ?? []);
    $runner->saveLastRun($result);

    $t = $result['totals'];
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addWarning(t('Some checks did not complete; results may be partial.'));
    }
    $messenger->addStatus(t('Tests finished: @pass good, @fail issues, @skip skipped.', [
      '@pass' => $t[TestRunner::PASS],
      '@fail' => $t[TestRunner::FAIL],
      '@skip' => $t[TestRunner::SKIP],
    ]));
    if (!empty($result['deltas']) && $result['deltas']['regression'] > 0) {
      $messenger->addError(t('@n check(s) REGRESSED versus the baseline — the upgrade changed behaviour that used to work.', [
        '@n' => $result['deltas']['regression'],
      ]));
    }
    elseif (!empty($result['deltas'])) {
      $messenger->addStatus(t('No regressions versus the baseline. Fixed: @f.', ['@f' => $result['deltas']['fixed']]));
    }
  }

}
