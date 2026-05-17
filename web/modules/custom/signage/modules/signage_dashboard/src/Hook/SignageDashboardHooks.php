<?php

declare(strict_types=1);

namespace Drupal\signage_dashboard\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for the signage_dashboard module.
 */
class SignageDashboardHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for user_login_form.
   */
  #[Hook('form_user_login_form_alter')]
  public function formUserLoginFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($this->requestStack->getCurrentRequest()?->query->has('destination')) {
      return;
    }

    $form['#submit'][] = [self::class, 'userLoginRedirectSubmit'];
  }

  /**
   * Redirect users to the dashboard after a normal login.
   */
  public static function userLoginRedirectSubmit(array &$form, FormStateInterface $form_state): void {
    if (\Drupal::request()->query->has('destination')) {
      return;
    }

    $form_state->setRedirect('signage_dashboard.page');
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $target_forms = [
      'node_slide_form',
      'node_slide_edit_form',
      'node_playlist_form',
      'node_playlist_edit_form',
      'node_screen_group_form',
      'node_screen_group_edit_form',
    ];

    if (!in_array($form_id, $target_forms, TRUE) || $this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    if (in_array($form_id, ['node_screen_group_form', 'node_screen_group_edit_form'], TRUE)) {
      $form['#validate'][] = [self::class, 'validateScreenGroupScreens'];
    }

    $intro_texts = [
      'node_slide_form' => 'Innhold er det som vises på infoskjermen, for eksempel bilde, tekst eller video.',
      'node_slide_edit_form' => 'Innhold er det som vises på infoskjermen, for eksempel bilde, tekst eller video.',
      'node_playlist_form' => 'En spilleliste bestemmer hvilket innhold som skal vises, og i hvilken rekkefølge.',
      'node_playlist_edit_form' => 'En spilleliste bestemmer hvilket innhold som skal vises, og i hvilken rekkefølge.',
      'node_screen_group_form' => 'En skjermgruppe lar deg vise samme spilleliste på flere skjermer. Lokalt innhold på en skjerm vises før felles innhold fra skjermgrupper.',
      'node_screen_group_edit_form' => 'En skjermgruppe lar deg vise samme spilleliste på flere skjermer. Lokalt innhold på en skjerm vises før felles innhold fra skjermgrupper.',
    ];

    $form_titles = [
      'node_slide_form' => 'Opprett innhold',
      'node_slide_edit_form' => 'Rediger innhold',
      'node_playlist_form' => 'Opprett spilleliste',
      'node_playlist_edit_form' => 'Rediger spilleliste',
      'node_screen_group_form' => 'Opprett skjermgruppe',
      'node_screen_group_edit_form' => 'Rediger skjermgruppe',
    ];

    if (isset($form_titles[$form_id])) {
      $form['#title'] = $form_titles[$form_id];
    }

    if (isset($intro_texts[$form_id])) {
      $form['signage_dashboard_help'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $intro_texts[$form_id] . '</p>',
        '#weight' => -100,
      ];
    }

    $field_labels = [
      'title' => 'Tittel',
      'field_playlist_items' => 'Innhold i spilleliste',
      'field_screen_group_screens' => 'Skjermer',
      'field_screen_group_playlist' => 'Spilleliste',
      'field_slide_type' => 'Innholdstype',
      'field_slide_media' => 'Media',
      'field_slide_body' => 'Tekst',
    ];

    foreach ($field_labels as $field_name => $label) {
      self::setFormElementTitle($form, $field_name, $label);
    }

    foreach (['field_playlist_items', 'field_screen_group_screens', 'field_screen_group_playlist'] as $field_name) {
      if (isset($form[$field_name]['#description'])) {
        unset($form[$field_name]['#description']);
      }
      if (isset($form[$field_name]['widget']['#description'])) {
        unset($form[$field_name]['widget']['#description']);
      }
    }

    foreach (['revision_information', 'revision', 'revision_log', 'uid', 'created', 'path'] as $element) {
      if (isset($form[$element])) {
        $form[$element]['#access'] = FALSE;
      }
    }

    if (isset($form['actions']['preview'])) {
      $form['actions']['preview']['#access'] = FALSE;
    }
  }

  /**
   * Relabels common node form widget structures if present.
   */
  public static function setFormElementTitle(array &$form, string $field_name, string $label): void {
    if (!isset($form[$field_name]) || !is_array($form[$field_name])) {
      return;
    }

    $form[$field_name]['#title'] = $label;

    if (isset($form[$field_name]['widget']['#title'])) {
      $form[$field_name]['widget']['#title'] = $label;
    }

    if (isset($form[$field_name]['widget'][0]['#title'])) {
      $form[$field_name]['widget'][0]['#title'] = $label;
    }

    foreach (['value', 'target_id'] as $child) {
      if (isset($form[$field_name]['widget'][0][$child]['#title'])) {
        $form[$field_name]['widget'][0][$child]['#title'] = $label;
      }
    }
  }

  /**
   * Validates that each screen appears only once in a screen group.
   */
  public static function validateScreenGroupScreens(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (!self::isSaveTrigger($trigger)) {
      return;
    }

    $screens = $form_state->getValue('field_screen_group_screens');
    if (!is_array($screens)) {
      return;
    }

    $seen = [];
    foreach ($screens as $item) {
      if (!is_array($item)) {
        continue;
      }

      $target_id = self::extractTargetId($item['target_id'] ?? NULL);
      if ($target_id === NULL) {
        continue;
      }

      if (isset($seen[$target_id])) {
        $form_state->setErrorByName(
          'field_screen_group_screens',
          t('Samme skjerm kan ikke legges til flere ganger i samme skjermgruppe.')
        );
        return;
      }

      $seen[$target_id] = TRUE;
    }
  }

  /**
   * Checks whether validation was triggered by the node save button.
   */
  public static function isSaveTrigger(mixed $trigger): bool {
    if (!is_array($trigger)) {
      return FALSE;
    }

    $parents = $trigger['#parents'] ?? [];
    if (is_array($parents) && $parents === ['actions', 'submit']) {
      return TRUE;
    }

    $array_parents = $trigger['#array_parents'] ?? [];
    return is_array($array_parents) && $array_parents === ['actions', 'submit'];
  }

  /**
   * Extracts an entity target ID from submitted entity reference values.
   */
  public static function extractTargetId(mixed $value): ?int {
    if (is_array($value)) {
      $value = $value['target_id'] ?? NULL;
    }

    if ($value === NULL || $value === '') {
      return NULL;
    }

    if (is_numeric($value)) {
      return (int) $value;
    }

    if (is_string($value) && preg_match('/\((\d+)\)$/', trim($value), $matches)) {
      return (int) $matches[1];
    }

    return NULL;
  }

}
