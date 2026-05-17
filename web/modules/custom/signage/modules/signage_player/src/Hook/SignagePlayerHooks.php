<?php

declare(strict_types=1);

namespace Drupal\signage_player\Hook;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Hook implementations for the signage_player module.
 */
class SignagePlayerHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme($existing, $type, $theme, $path): array {
    return [
      'signage_player_screen' => [
        'variables' => [
          'playback' => [],
        ],
        'template' => 'player-screen',
      ],
      'page__screen' => [
        'template' => 'page--screen',
        'base hook' => 'page',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for page templates.
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions, array $variables): void {
    if ($this->routeMatch->getRouteName() === 'signage_player.screen') {
      $suggestions[] = 'page__screen';
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node_form.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $entity = $form_state->getFormObject()->getEntity();

    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'playlist') {
      return;
    }

    $form['#validate'][] = [self::class, 'validatePlaylistSchedule'];
  }

  /**
   * Validate scheduling for playlist items.
   */
  public static function validatePlaylistSchedule(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->getFormObject()->getEntity();

    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'playlist') {
      return;
    }

    if (!$entity->hasField('field_playlist_items') || $entity->get('field_playlist_items')->isEmpty()) {
      return;
    }

    foreach ($entity->get('field_playlist_items')->referencedEntities() as $delta => $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'playlist_item') {
        continue;
      }

      $start = self::datetimeFieldToTimestamp($paragraph, 'field_start_at', '00:00:00');
      $end = self::datetimeFieldToTimestamp($paragraph, 'field_end_at', '23:59:59');

      if ($start !== NULL && $end !== NULL && $end < $start) {
        $end_element = $form['field_playlist_items']['widget'][$delta]['subform']['field_end_at']['widget'][0]['value'] ?? NULL;

        if (is_array($end_element)) {
          $form_state->setError(
            $end_element,
            t('End time must be later than start time.')
          );
        }
        else {
          $form_state->setErrorByName(
            'field_playlist_items',
            t('Playlist item @item has an end time earlier than its start time.', [
              '@item' => $delta + 1,
            ])
          );
        }
      }

      if ($paragraph->hasField('field_sort_order') && !$paragraph->get('field_sort_order')->isEmpty()) {
        $sort_order = (int) $paragraph->get('field_sort_order')->value;

        if ($sort_order < 1) {
          $sort_element = $form['field_playlist_items']['widget'][$delta]['subform']['field_sort_order']['widget'][0]['value'] ?? NULL;

          if (is_array($sort_element)) {
            $form_state->setError(
              $sort_element,
              t('Sort order must be at least 1.')
            );
          }
          else {
            $form_state->setErrorByName(
              'field_playlist_items',
              t('Playlist item @item must have a sort order of at least 1.', [
                '@item' => $delta + 1,
              ])
            );
          }
        }
      }
    }
  }

  /**
   * Convert a paragraph datetime field property to a timestamp.
   */
  public static function datetimeFieldToTimestamp(ParagraphInterface $paragraph, string $field_name, ?string $default_time = NULL): ?int {
    if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
      return NULL;
    }

    $item = $paragraph->get($field_name)->first();
    if (!$item) {
      return NULL;
    }

    $value = $item->getValue()['value'] ?? $paragraph->get($field_name)->value ?? NULL;
    if (is_array($value)) {
      $date = $value['date'] ?? NULL;
      $time = $value['time'] ?? NULL;

      if (!is_string($date) || trim($date) === '') {
        return NULL;
      }

      if (!is_string($time) || trim($time) === '' || str_contains($time, '--')) {
        if ($default_time === NULL) {
          return NULL;
        }

        $time = $default_time;
      }

      if (strlen($time) === 5) {
        $time .= ':00';
      }

      $value = $date . 'T' . $time;
    }

    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }

    try {
      $date = new DrupalDateTime($value, new \DateTimeZone('UTC'));
      return $date->getTimestamp();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

}
