<?php

declare(strict_types=1);

namespace Drupal\signage_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for the signage_access module.
 *
 * Controls user access to screen nodes via a dedicated admin UI and
 * a per-node "allowed users" entity reference field.
 */
class SignageAccessHooks {

  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_access() for node entities.
   *
   * Grants screen view access to owners and assigned users, while keeping
   * screen editing owner/admin-only. Also grants access to local playlists
   * attached directly to an accessible screen.
   *
   * Admins bypass this via Drupal core — we return neutral() for them.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, $op, AccountInterface $account): AccessResultInterface {
    if (!in_array($op, ['view', 'update'])) {
      return AccessResult::neutral();
    }

    if ($node->bundle() === 'screen') {
      if (
        $account->hasPermission('administer nodes') ||
        $account->hasPermission('edit any screen content')
      ) {
        return AccessResult::neutral();
      }

      $is_owner = (int) $node->getOwnerId() === (int) $account->id();

      if ($op === 'view' && ($is_owner || $this->accountHasScreenClaim($node, $account))) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($node);
      }

      if ($op === 'update' && $is_owner) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($node);
      }

      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    if ($node->bundle() === 'playlist') {
      return $this->playlistNodeAccess($node, $account);
    }

    return AccessResult::neutral();
  }

  /**
   * Grants playlist access through assigned screens that reference it directly.
   */
  private function playlistNodeAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if (
      $account->hasPermission('administer nodes') ||
      $account->hasPermission('edit any playlist content')
    ) {
      return AccessResult::neutral();
    }

    $screens = [];
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $screen_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'screen')
        ->condition('status', 1)
        ->condition('field_screen_playlist.target_id', (int) $node->id())
        ->execute();

      if ($screen_ids) {
        $screens = $storage->loadMultiple($screen_ids);
      }
    }
    catch (\Throwable) {
      $screens = [];
    }

    $matching_screens = [];
    $has_access = FALSE;

    foreach ($screens as $screen) {
      if (!$screen instanceof NodeInterface || $screen->bundle() !== 'screen') {
        continue;
      }

      if (!$this->screenReferencesPlaylist($screen, (int) $node->id())) {
        continue;
      }

      $matching_screens[] = $screen;

      if ($this->accountHasScreenClaim($screen, $account)) {
        $has_access = TRUE;
      }
    }

    $result = ($has_access ? AccessResult::allowed() : AccessResult::neutral())
      ->cachePerUser()
      ->addCacheableDependency($node);

    foreach ($matching_screens as $screen) {
      $result->addCacheableDependency($screen);
    }

    return $result;
  }

  /**
   * Checks that a screen still directly references the playlist.
   */
  private function screenReferencesPlaylist(NodeInterface $screen, int $playlist_id): bool {
    if (!$screen->hasField('field_screen_playlist') || $screen->get('field_screen_playlist')->isEmpty()) {
      return FALSE;
    }

    foreach ($screen->get('field_screen_playlist')->getValue() as $ref) {
      if ((int) ($ref['target_id'] ?? 0) === $playlist_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks whether an account is assigned to or owns a screen.
   */
  private function accountHasScreenClaim(NodeInterface $screen, AccountInterface $account): bool {
    if ((int) $screen->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    if (!$screen->hasField('field_screen_access_users')) {
      return FALSE;
    }

    foreach ($screen->get('field_screen_access_users')->getValue() as $ref) {
      if ((int) ($ref['target_id'] ?? 0) === (int) $account->id()) {
        return TRUE;
      }
    }

    return FALSE;
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

    if (!$this->currentUser->hasPermission('administer signage access') && isset($form['field_screen_access_users'])) {
      $form['field_screen_access_users']['#access'] = FALSE;
    }
  }

}
