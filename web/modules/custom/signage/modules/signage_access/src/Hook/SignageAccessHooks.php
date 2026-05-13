<?php

declare(strict_types=1);

namespace Drupal\signage_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for the signage_access module.
 *
 * Controls user access to screen nodes via a dedicated admin UI and
 * a per-node "allowed users" entity reference field.
 */
class SignageAccessHooks {

  public function __construct(
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_access() for node entities.
   *
   * Grants access to 'screen' nodes if:
   *  1. The user is the original author (owner), or
   *  2. The user is listed in field_screen_access_users.
   *
   * Admins with 'administer nodes' or 'edit any screen content' bypass this
   * automatically via Drupal core — we return neutral() for them.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, $op, AccountInterface $account): AccessResultInterface {
    if ($node->bundle() !== 'screen') {
      return AccessResult::neutral();
    }

    // Only intercept view and update operations.
    if (!in_array($op, ['view', 'update'])) {
      return AccessResult::neutral();
    }

    // Let core/admin permissions handle admins.
    if (
      $account->hasPermission('administer nodes') ||
      $account->hasPermission('edit any screen content')
    ) {
      return AccessResult::neutral();
    }

    // Grant access to the screen's author.
    if ($node->getOwnerId() == $account->id()) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    // Grant access if the user is in the allowed users field.
    if ($node->hasField('field_screen_access_users')) {
      foreach ($node->get('field_screen_access_users')->getValue() as $ref) {
        if ((int) $ref['target_id'] === (int) $account->id()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($node);
        }
      }
    }

    // Explicitly deny — this user has no claim to this screen.
    return AccessResult::forbidden()
      ->cachePerUser()
      ->addCacheableDependency($node);
  }

  /**
   * Implements hook_form_alter().
   *
   * Hides the field_screen_access_users field on the node edit form for
   * non-admins — access should only be managed via the dedicated admin page.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!in_array($form_id, ['node_screen_form', 'node_screen_edit_form'])) {
      return;
    }

    if (!$this->currentUser->hasPermission('administer signage access')) {
      $form['field_screen_access_users']['#access'] = FALSE;
    }
  }

}
