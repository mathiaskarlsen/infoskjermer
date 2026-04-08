<?php

declare(strict_types=1);

namespace Drupal\signage_screen\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

final class ScreenManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  public function attachDefaultPlaylist(NodeInterface $screen): void {
    if ($screen->bundle() !== 'screen') {
      return;
    }

    if (!$screen->get('field_screen_playlist')->isEmpty()) {
      return;
    }

    $playlist_storage = $this->entityTypeManager->getStorage('node');

    $playlist = $playlist_storage->create([
      'type' => 'playlist',
      'title' => $screen->label() . ' - Default Playlist',
      'status' => NodeInterface::PUBLISHED,
      'uid' => $screen->getOwnerId(),
    ]);
    $playlist->save();

    $screen->set('field_screen_playlist', ['target_id' => $playlist->id()]);

    // Unngå ekstra revision hvis revisions er slått på.
    if (method_exists($screen, 'setNewRevision')) {
      $screen->setNewRevision(FALSE);
    }

    $screen->save();

    $this->logger->notice(
      'Created default playlist @playlist for screen @screen.',
      [
        '@playlist' => $playlist->label(),
        '@screen' => $screen->label(),
      ]
    );
  }

  public function getPlayerPath(NodeInterface $screen): string {
    return '/player/' . $screen->id();
  }

}