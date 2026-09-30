<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Feedback form BBB sends participants to after a meeting.
 *
 * Room logout URL: [site:base-url]/bbb-test-kit-feedback/[node:nid]
 */
final class FeedbackForm extends FormBase {

  /**
   * Submissions allowed per client IP per hour.
   */
  private const FLOOD_LIMIT = 10;

  public function __construct(
    private readonly Connection $database,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('flood'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'bbb_test_kit_feedback';
  }

  /**
   * Title callback: "Feedback for <room title>".
   */
  public function title(NodeInterface $node): TranslatableMarkup {
    return $this->t('Feedback for @label', ['@label' => $node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    // Only BBB rooms collect feedback.
    if (!$node || $node->bundle() !== 'bbb_test_kit_room') {
      throw new NotFoundHttpException();
    }
    // The nid always comes from the route, never from the submission.
    $form_state->set('room_nid', (int) $node->id());

    $form['room_nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Room'),
      '#value' => $node->id(),
      '#disabled' => TRUE,
      '#size' => 10,
      '#description' => $this->t('Node @nid: @label', ['@nid' => $node->id(), '@label' => $node->label()]),
    ];

    $form['rating'] = [
      '#type' => 'radios',
      '#title' => $this->t('How was the meeting?'),
      '#options' => [
        5 => $this->t('5 – Excellent'),
        4 => $this->t('4 – Good'),
        3 => $this->t('3 – OK'),
        2 => $this->t('2 – Poor'),
        1 => $this->t('1 – Bad'),
      ],
      '#required' => TRUE,
    ];

    $form['comment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Comment'),
      '#rows' => 4,
      '#maxlength' => 2000,
    ];

    if ($this->currentUser()->isAnonymous()) {
      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your name'),
        '#maxlength' => 255,
      ];
      $form['mail'] = [
        '#type' => 'email',
        '#title' => $this->t('Your email'),
        '#description' => $this->t('Optional, only if you want us to reply.'),
        '#maxlength' => 254,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send feedback'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->flood->isAllowed('bbb_test_kit.feedback', self::FLOOD_LIMIT, 3600)) {
      $form_state->setErrorByName('', $this->t('You have sent a lot of feedback in a short time. Please try again later.'));
    }
    $rating = (int) $form_state->getValue('rating');
    if ($rating < 1 || $rating > 5) {
      $form_state->setErrorByName('rating', $this->t('Choose a rating from 1 to 5.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->get('room_nid');
    $anonymous = $this->currentUser()->isAnonymous();

    $this->database->insert('bbb_test_kit_feedback')
      ->fields([
        'nid' => $nid,
        'uid' => (int) $this->currentUser()->id(),
        'name' => $anonymous ? trim((string) $form_state->getValue('name')) : '',
        'mail' => $anonymous ? trim((string) $form_state->getValue('mail')) : '',
        'rating' => (int) $form_state->getValue('rating'),
        'comment' => trim((string) $form_state->getValue('comment')),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->flood->register('bbb_test_kit.feedback', 3600);

    $this->logger('bbb_test_kit')->notice('Feedback for node @nid: rating @rating.', [
      '@nid' => $nid,
      '@rating' => (int) $form_state->getValue('rating'),
    ]);
    $this->messenger()->addStatus($this->t('Thank you for your feedback.'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $nid]);
  }

}
