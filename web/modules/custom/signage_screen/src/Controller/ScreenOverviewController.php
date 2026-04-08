<?php

declare(strict_types=1);

namespace Drupal\signage_screen\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

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
      $location = '-';
      if (!$screen->get('field_screen_location')->isEmpty() && $screen->get('field_screen_location')->entity) {
        $location = $screen->get('field_screen_location')->entity->label();
      }

      $playlist = '-';
      if (!$screen->get('field_screen_playlist')->isEmpty() && $screen->get('field_screen_playlist')->entity) {
        $playlist = $screen->get('field_screen_playlist')->entity->label();
      }

      $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $screen->id()])->toString();
      $player_url = Url::fromUri('internal:/player/' . $screen->id())->toString();
      
      $rows[] = [
        $screen->label(),
        $location,
        $screen->isPublished() ? $this->t('Active') : $this->t('Inactive'),
        $playlist,
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
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Screen'),
        $this->t('Location'),
        $this->t('Status'),
        $this->t('Playlist'),
        $this->t('Edit'),
        $this->t('Player'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No screens found.'),
    ];
  }

}