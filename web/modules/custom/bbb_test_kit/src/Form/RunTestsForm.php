<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Form;

use Drupal\bbb_test_kit\TestBatch;
use Drupal\bbb_test_kit\TestRunner;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Controls the BBB Test Kit report: run (batch), baseline, clear.
 */
final class RunTestsForm extends FormBase {

  public function getFormId(): string {
    return 'bbb_test_kit_run_tests';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $has_last = (bool) (new TestRunner())->lastRun();

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run all tests'),
      '#button_type' => 'primary',
      '#submit' => ['::runTests'],
    ];
    $form['actions']['baseline'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save last run as baseline'),
      '#submit' => ['::saveBaseline'],
      '#access' => $has_last,
      '#attributes' => ['title' => $this->t('Do this on the stable release, before switching to the dev version.')],
    ];
    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear baseline'),
      '#submit' => ['::clearBaseline'],
      '#access' => (bool) (new TestRunner())->baseline(),
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default submit routes to runTests().
    $this->runTests($form, $form_state);
  }

  /**
   * Starts the batch that runs every check group.
   */
  public function runTests(array &$form, FormStateInterface $form_state): void {
    batch_set(TestBatch::build());
    $form_state->setRedirect('bbb_test_kit.test_report');
  }

  /**
   * Saves the last run's statuses as the baseline.
   */
  public function saveBaseline(array &$form, FormStateInterface $form_state): void {
    $runner = new TestRunner();
    $last = $runner->lastRun();
    if (!$last) {
      $this->messenger()->addWarning($this->t('Run the tests first, then save the baseline.'));
      return;
    }
    // Persist statuses from the last run as the baseline.
    $checks = [];
    foreach ($last['groups'] as $rows) {
      foreach ($rows as $row) {
        $checks[$row['id']] = $row['status'];
      }
    }
    \Drupal::state()->set(TestRunner::STATE_BASELINE, [
      'captured' => \Drupal::time()->getRequestTime(),
      'env' => $last['env'],
      'checks' => $checks,
    ]);
    $this->messenger()->addStatus($this->t('Baseline saved from the last run (@lib). Now switch to the dev version, run drush deploy/updb, and run the tests again.', [
      '@lib' => $last['env']['library'] ?? 'unknown',
    ]));
  }

  /**
   * Clears the baseline.
   */
  public function clearBaseline(array &$form, FormStateInterface $form_state): void {
    (new TestRunner())->clearBaseline();
    $this->messenger()->addStatus($this->t('Baseline cleared.'));
  }

}
