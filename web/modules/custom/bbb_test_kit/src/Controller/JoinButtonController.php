<?php

declare(strict_types=1);

namespace Drupal\bbb_test_kit\Controller;

use Drupal\bigbluebutton\Form\BigBlueButtonDynamicFormFactory;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns a room's join form over Drupal AJAX once the countdown ends.
 *
 * The server decides: bbb_test_kit_form_alter() only includes the button
 * when field_bbb_start has passed (or the user moderates); otherwise the
 * response is a fresh countdown.
 */
final class JoinButtonController extends ControllerBase {

  public function __construct(
    private readonly BigBlueButtonDynamicFormFactory $formFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('bigbluebutton.dynamic_form_factory'));
  }

  /**
   * Replaces the countdown wrapper with the current join form.
   */
  public function button(NodeInterface $node, Request $request): AjaxResponse {
    if ($node->bundle() !== 'bbb_test_kit_room' || !$node->hasField('field_bbb')) {
      throw new NotFoundHttpException();
    }

    // Same settings the "BBB Default" formatter passes on the room page.
    $component = $this->entityTypeManager()
      ->getStorage('entity_view_display')
      ->load('node.bbb_test_kit_room.default')
      ?->getComponent('field_bbb') ?? [];
    $settings = [
      'link_title' => $component['settings']['link_title'] ?? 'Join meeting',
      'link_classes' => $component['settings']['link_classes'] ?? '',
      'entity' => $node,
      'bbb' => ['enabled' => $node->get('field_bbb')->enabled],
    ];
    $form = $this->formBuilder()->getForm($this->formFactory->create($node, $settings));

    // The form must post to the room page, keeping its query string
    // (e.g. ?registration=<uuid> for the display name), not to this route.
    $query = array_diff_key($request->query->all(), array_flip(['_wrapper_format', 'ajax_form', '_wrapper']));
    $form['#action'] = $node->toUrl('canonical', ['query' => $query])->toString();

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#bbb-test-kit-join-' . $node->id(), $form));
    return $response;
  }

}
