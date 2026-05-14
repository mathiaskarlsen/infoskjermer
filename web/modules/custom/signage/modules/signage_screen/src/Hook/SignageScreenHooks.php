<?php

declare(strict_types=1);

namespace Drupal\signage_screen\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\signage_screen\Service\ScreenManager;

/**
 * Hook implementations for the signage_screen module.
 */
class SignageScreenHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly ScreenManager $screenManager,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }

    if ($entity->bundle() !== 'screen') {
      return;
    }

    if (!$entity->get('field_screen_playlist')->isEmpty()) {
      return;
    }

    $this->screenManager->attachDefaultPlaylist($entity);
  }

  /**
   * Implements hook_entity_view().
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }

    if ($entity->bundle() !== 'screen') {
      return;
    }

    // Vis bare som admin-hjelp for brukere som kan redigere skjermen.
    if (!$entity->access('update')) {
      return;
    }

    $player_url = Url::fromUri('internal:/player/' . $entity->id());

    $build['signage_screen_player'] = [
      '#type' => 'details',
      '#title' => $this->t('Player'),
      '#open' => TRUE,
      '#weight' => 100,
      'open_link' => Link::fromTextAndUrl($this->t('Open player'), $player_url)->toRenderable(),
      'path' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $player_url->toString(),
        '#attributes' => ['class' => ['description']],
      ],
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for node_screen_form.
   */
  #[Hook('form_node_screen_form_alter')]
  public function formNodeScreenFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->alterScreenForm($form);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for node_screen_edit_form.
   */
  #[Hook('form_node_screen_edit_form_alter')]
  public function formNodeScreenEditFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->alterScreenForm($form);
  }

  /**
   * Shared form alterations for Screen add/edit forms.
   */
  protected function alterScreenForm(array &$form): void {
    if (isset($form['field_screen_playlist']['widget'][0]['target_id'])) {
      $form['field_screen_playlist']['widget'][0]['target_id']['#description'] = $this->t('Optional. If left empty, the system creates a default playlist automatically when the screen is first saved.');
    }
  }

}
