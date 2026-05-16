<?php

declare(strict_types=1);

namespace Drupal\signage_player\Hook;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\Element\Datetime;
use Drupal\Core\Field\FieldItemListInterface;
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
   * Implements hook_field_widget_single_element_form_alter().
   */
  #[Hook('field_widget_single_element_form_alter')]
  public function fieldWidgetSingleElementFormAlter(array &$element, FormStateInterface $form_state, array $context): void {
    $items = $context['items'] ?? NULL;

    if (!$items instanceof FieldItemListInterface) {
      return;
    }

    $entity = $items->getEntity();
    if (!$entity instanceof ParagraphInterface || $entity->bundle() !== 'playlist_item') {
      return;
    }

    $field_name = $items->getFieldDefinition()->getName();
    if ($field_name !== 'field_start_and_end_date') {
      return;
    }

    $defaults = [
      'value' => '00:00:00',
      'end_value' => '23:59:59',
    ];

    foreach ($defaults as $key => $default_time) {
      if (!isset($element[$key]) || !is_array($element[$key])) {
        continue;
      }

      $element[$key]['#signage_player_default_time'] = $default_time;
      $element[$key]['#value_callback'] = [self::class, 'datetimeValueCallback'];
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
   * Custom value callback for datetime widgets.
   *
   * Ensures a default time is supplied before Drupal core turns submitted input
   * into a DrupalDateTime object.
   */
  public static function datetimeValueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input !== FALSE && is_array($input)) {
      $date = $input['date'] ?? NULL;
      $time = $input['time'] ?? NULL;

      if (is_string($date) && trim($date) !== '') {
        if (!is_string($time) || trim($time) === '' || str_contains($time, '--')) {
          $input['time'] = $element['#signage_player_default_time'] ?? '00:00:00';
        }
      }
    }

    return Datetime::valueCallback($element, $input, $form_state);
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

      $start = self::datetimeFieldToTimestamp($paragraph, 'field_start_and_end_date', 'value', '00:00:00');
      $end = self::datetimeFieldToTimestamp($paragraph, 'field_start_and_end_date', 'end_value', '23:59:59');

      if ($start !== NULL && $end !== NULL && $end < $start) {
        $end_element = $form['field_playlist_items']['widget'][$delta]['subform']['field_start_and_end_date']['widget'][0]['end_value'] ?? NULL;

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
  public static function datetimeFieldToTimestamp(ParagraphInterface $paragraph, string $field_name, string $property = 'value', ?string $default_time = NULL): ?int {
    if (!$paragraph->hasField($field_name) || $paragraph->get($field_name)->isEmpty()) {
      return NULL;
    }

    $item = $paragraph->get($field_name)->first();
    if (!$item) {
      return NULL;
    }

    $raw = $item->getValue();
    $value = $raw[$property] ?? NULL;

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
