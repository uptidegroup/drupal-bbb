<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Controller;

use Drupal\bbb_test_kit\TestRunner;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Green/red results page for the BBB Test Kit, with baseline comparison.
 *
 * The checks run via the Drupal Batch API (see RunTestsForm / TestBatch);
 * this page shows the last stored run and how it compares to the baseline.
 */
final class TestReportController extends ControllerBase {

  private const DELTA_BADGE = [
    'regression' => ['▼ regression', 'bbb-delta-regression'],
    'fixed' => ['▲ fixed', 'bbb-delta-fixed'],
    'changed' => ['changed', 'bbb-delta-changed'],
    'new' => ['new', 'bbb-delta-new'],
  ];

  public function report(): array {
    $runner = new TestRunner();
    $build['#attached']['library'][] = 'bbb_test_kit/report';
    $build['#cache'] = ['max-age' => 0];

    $build['controls'] = \Drupal::formBuilder()->getForm('\Drupal\bbb_test_kit\Form\RunTestsForm');

    // Baseline banner.
    if ($baseline = $runner->baseline()) {
      $build['baseline'] = [
        '#markup' => $this->t('Baseline captured @date on library @lib. Post-upgrade runs are compared against it.', [
          '@date' => \Drupal::service('date.formatter')->format($baseline['captured'], 'short'),
          '@lib' => $baseline['env']['library'] ?? 'unknown',
        ]),
        '#prefix' => '<p class="bbb-baseline-note">',
        '#suffix' => '</p>',
      ];
    }
    else {
      $build['baseline'] = [
        '#markup' => $this->t('No baseline yet. On the <strong>stable</strong> release, run the tests then "Save last run as baseline" — before switching to the dev version.'),
        '#prefix' => '<p class="bbb-baseline-note">',
        '#suffix' => '</p>',
      ];
    }

    $result = $runner->lastRun();
    if (!$result) {
      $build['empty'] = [
        '#markup' => $this->t('No results yet — click <em>Run all tests</em>.'),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      return $build;
    }

    $env = $result['env'];
    $t = $result['totals'];
    $labels = [
      TestRunner::PASS => $this->t('Good'),
      TestRunner::FAIL => $this->t('Issue'),
      TestRunner::SKIP => $this->t('Skipped'),
    ];

    $build['env'] = [
      '#markup' => $this->t('Last run @when — Drupal @d · PHP @p · library @l · BBB @b', [
        '@when' => \Drupal::service('date.formatter')->format($result['generated'] ?? \Drupal::time()->getRequestTime(), 'short'),
        '@d' => $env['drupal'],
        '@p' => $env['php'],
        '@l' => $env['library'],
        '@b' => $env['bbb_configured'] ? $this->t('configured') : $this->t('not configured — live checks skipped'),
      ]),
      '#prefix' => '<p class="bbb-test-env">',
      '#suffix' => '</p>',
    ];

    // Totals + regression banner.
    $totals_markup = $this->t('<span class="bbb-good">@pass good</span>, <span class="bbb-fail">@fail issues</span>, <span class="bbb-skip">@skip skipped</span>', [
      '@pass' => $t['pass'], '@fail' => $t['fail'], '@skip' => $t['skip'],
    ]);
    $build['totals'] = ['#markup' => $totals_markup, '#prefix' => '<p class="bbb-test-totals">', '#suffix' => '</p>'];

    if (!empty($result['deltas'])) {
      $d = $result['deltas'];
      $class = $d['regression'] > 0 ? 'bbb-regressions' : 'bbb-no-regressions';
      $msg = $d['regression'] > 0
        ? $this->t('⚠ @r regression(s) vs baseline — behaviour that worked before the upgrade now fails. Fixed: @f.', ['@r' => $d['regression'], '@f' => $d['fixed']])
        : $this->t('✓ No regressions vs baseline. Fixed: @f · changed: @c.', ['@f' => $d['fixed'], '@c' => $d['changed']]);
      $build['deltas'] = ['#markup' => $msg, '#prefix' => '<p class="bbb-delta-summary ' . $class . '">', '#suffix' => '</p>'];
    }

    foreach ($result['groups'] as $group => $rows) {
      $items = [];
      foreach ($rows as $row) {
        $delta = '';
        if (!empty($row['delta']) && isset(self::DELTA_BADGE[$row['delta']])) {
          [$text, $cls] = self::DELTA_BADGE[$row['delta']];
          $delta = ' <span class="bbb-delta ' . $cls . '">' . $text . '</span>';
        }
        $items[] = [
          '#markup' => '<span class="bbb-badge bbb-' . $row['status'] . '">' . $labels[$row['status']] . '</span> '
            . '<span class="bbb-test-label">' . htmlspecialchars($row['label']) . '</span>' . $delta
            . ($row['detail'] !== '' ? ' <span class="bbb-test-detail">— ' . htmlspecialchars($row['detail']) . '</span>' : ''),
          '#wrapper_attributes' => ['class' => ['bbb-row', 'bbb-row--' . $row['status']]],
        ];
      }
      $build['group_' . md5($group)] = [
        '#type' => 'details',
        '#title' => $group,
        '#open' => TRUE,
        'list' => ['#theme' => 'item_list', '#items' => $items],
      ];
    }

    // Example API responses.
    $ex_items = [];
    foreach ($result['examples'] ?? [] as $ex) {
      $ex_items[] = [
        '#markup' => '<p class="bbb-api-label"><code>' . htmlspecialchars($ex['label']) . '</code></p>'
          . '<pre class="bbb-api-body">' . htmlspecialchars($ex['body']) . '</pre>',
      ];
    }
    if ($ex_items) {
      $build['examples'] = [
        '#type' => 'details',
        '#title' => $this->t('Example API responses'),
        '#open' => FALSE,
        'note' => [
          '#markup' => $this->t('Live, read-only responses from this site (passwords masked). The join-link call is shown as a shape only, since calling it creates a meeting.'),
          '#prefix' => '<p><em>', '#suffix' => '</em></p>',
        ],
        'list' => ['#theme' => 'item_list', '#items' => $ex_items],
      ];
    }

    // Manual test links.
    $res = $result['resources'];
    $user_items = [];
    foreach ($res['users'] as $u) {
      $user_items[] = ['#markup' => '<code>' . $u['name'] . '</code> — ' . htmlspecialchars($u['info'])];
    }
    $guest_items = [];
    foreach ($res['guests'] as $g) {
      $guest_items[] = [
        '#markup' => Link::fromTextAndUrl($g['name'], Url::fromUri($g['url']))->toString() . ' — ' . htmlspecialchars($g['info']),
      ];
    }
    $build['manual'] = [
      '#type' => 'details',
      '#title' => $this->t('Manual test links'),
      '#open' => TRUE,
      'password' => [
        '#markup' => $this->t('All test users share the password <code>@p</code>.', ['@p' => $res['password']]),
        '#prefix' => '<p>', '#suffix' => '</p>',
      ],
      'users' => ['#theme' => 'item_list', '#title' => $this->t('Test users'), '#items' => $user_items],
      'guests' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Registered-guest join links (open in a private window)'),
        '#items' => $guest_items ?: [['#markup' => $this->t('No sample registrations.')]],
      ],
      'note' => [
        '#markup' => $this->t("Browser-only checks are listed in the module's docs/manual-tests.md."),
        '#prefix' => '<p><em>', '#suffix' => '</em></p>',
      ],
    ];

    return $build;
  }

}
