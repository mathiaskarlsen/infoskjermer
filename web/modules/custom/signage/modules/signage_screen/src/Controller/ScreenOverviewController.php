<?php

declare(strict_types=1);

namespace Drupal\signage_screen\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

final class ScreenOverviewController extends ControllerBase {

  public function overview(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'screen')
      ->sort('created', 'DESC')
      ->execute();

    if (!$nids) {
      return [
        '#markup' => $this->t('No screens found.'),
      ];
    }

    $screens = $storage->loadMultiple($nids);

    $rows = [];
    foreach ($screens as $screen) {
      if (!$screen instanceof NodeInterface) {
        continue;
      }

      $location = '-';
      if ($screen->hasField('field_screen_location') && !$screen->get('field_screen_location')->isEmpty() && $screen->get('field_screen_location')->entity) {
        $location = $screen->get('field_screen_location')->entity->label();
      }

      $playlist = '-';
      if ($screen->hasField('field_screen_playlist') && !$screen->get('field_screen_playlist')->isEmpty() && $screen->get('field_screen_playlist')->entity) {
        $playlist = $screen->get('field_screen_playlist')->entity->label();
      }

      $owner = $screen->getOwner();
      $owner_label = $owner instanceof UserInterface
        ? ($owner->getDisplayName() ?: '-')
        : '-';

      $access_user_count = 0;
      if ($screen->hasField('field_screen_access_users') && !$screen->get('field_screen_access_users')->isEmpty()) {
        $access_user_count = count($screen->get('field_screen_access_users')->getValue());
      }

      $screen_group_count = $this->countScreenGroupsForScreen((int) $screen->id());
      
      $rows[] = [
        $screen->label(),
        $location,
        $screen->isPublished() ? $this->t('Active') : $this->t('Inactive'),
        $playlist,
        $screen_group_count ? $this->formatPlural($screen_group_count, '1 group', '@count groups') : '-',
        $owner_label,
        $access_user_count ? $this->formatPlural($access_user_count, '1 user', '@count users') : $this->t('None'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $screen->id()]),
          ],
        ],
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Open player'),
            '#url' => Url::fromUri('internal:/player/' . $screen->id()),
          ],
        ],
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Manage access'),
            '#url' => Url::fromRoute('signage_access.admin_screen', ['node' => $screen->id()]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Screen'),
        $this->t('Location'),
        $this->t('Status'),
        $this->t('Local playlist'),
        $this->t('Screen groups'),
        $this->t('Technical owner'),
        $this->t('Access users'),
        $this->t('Edit'),
        $this->t('Player'),
        $this->t('Access'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No screens found.'),
    ];
  }

  private function countScreenGroupsForScreen(int $screen_id): int {
    try {
      $group_ids = $this->entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'screen_group')
        ->condition('status', 1)
        ->condition('field_screen_group_screens.target_id', $screen_id)
        ->execute();
    }
    catch (\Throwable) {
      return 0;
    }

    return count($group_ids);
  }

}
