<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists feedback left on /bbb-test-kit-feedback/{node}.
 */
final class FeedbackReportController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the feedback table.
   */
  public function list(): array {
    $query = $this->database->select('bbb_test_kit_feedback', 'f')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $records = $query->fields('f')->orderBy('created', 'DESC')->execute()->fetchAll();

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_unique(array_column($records, 'nid')));
    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple(array_unique(array_filter(array_column($records, 'uid'))));

    $rows = [];
    foreach ($records as $record) {
      $node = $nodes[$record->nid] ?? NULL;
      if ($record->uid && isset($users[$record->uid])) {
        $who = $users[$record->uid]->toLink()->toString();
      }
      else {
        $who = $this->t('Guest: @name @mail', [
          '@name' => $record->name !== '' ? $record->name : $this->t('(no name)'),
          '@mail' => $record->mail !== '' ? '<' . $record->mail . '>' : '',
        ]);
      }
      $rows[] = [
        $this->dateFormatter->format((int) $record->created, 'short'),
        $node ? $node->toLink()->toString() : $this->t('Deleted node @nid', ['@nid' => $record->nid]),
        $who,
        str_repeat('★', (int) $record->rating) . str_repeat('☆', 5 - (int) $record->rating) . ' (' . $record->rating . ')',
        // Plain string: Twig escapes it.
        (string) $record->comment,
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Date'), $this->t('Room'), $this->t('From'), $this->t('Rating'), $this->t('Comment')],
        '#rows' => $rows,
        '#empty' => $this->t('No feedback yet.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
