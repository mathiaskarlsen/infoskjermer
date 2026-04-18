<?php

declare(strict_types=1);

namespace Drupal\signage_share\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;

final class ShareManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  public function createShare(int $slideId, int $recipientUid, string $message = ''): void {
    $this->database->insert('signage_share')
      ->fields([
        'slide_id' => $slideId,
        'sender_uid' => (int) $this->currentUser->id(),
        'recipient_uid' => $recipientUid,
        'message' => $message,
        'created' => $this->time->getCurrentTime(),
        'status' => 'new',
      ])
      ->execute();
  }

  public function loadReceivedShares(int $uid, array $statuses = ['new', 'seen', 'copied']): array {
    $query = $this->database->select('signage_share', 's')
      ->fields('s')
      ->condition('recipient_uid', $uid)
      ->condition('status', $statuses, 'IN')
      ->orderBy('created', 'DESC');

    return $query->execute()->fetchAllAssoc('id');
  }

  public function markSeen(int $shareId): void {
    $this->database->update('signage_share')
      ->fields(['status' => 'seen'])
      ->condition('id', $shareId)
      ->execute();
  }

  public function archive(int $shareId): void {
    $this->database->update('signage_share')
      ->fields(['status' => 'archived'])
      ->condition('id', $shareId)
      ->execute();
  }

  public function loadShare(int $shareId): ?object {
    $record = $this->database->select('signage_share', 's')
      ->fields('s')
      ->condition('id', $shareId)
      ->execute()
      ->fetchObject();

    return $record ?: NULL;
  }

  public function copySharedSlide(int $shareId): ?int {
    $share = $this->loadShare($shareId);
    if (!$share) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $original = $storage->load((int) $share->slide_id);

    if (!$original instanceof NodeInterface || $original->bundle() !== 'slide') {
      return NULL;
    }

    $values = [
      'type' => 'slide',
      'title' => $original->label(),
      'uid' => (int) $this->currentUser->id(),
      'status' => $original->isPublished(),
    ];

    if ($original->hasField('field_slide_body') && !$original->get('field_slide_body')->isEmpty()) {
      $values['field_slide_body'] = $original->get('field_slide_body')->getValue();
    }

    if ($original->hasField('field_slide_media') && !$original->get('field_slide_media')->isEmpty()) {
      $values['field_slide_media'] = $original->get('field_slide_media')->getValue();
    }

    $copy = $storage->create($values);
    $copy->save();

    $this->database->update('signage_share')
      ->fields(['status' => 'copied'])
      ->condition('id', $shareId)
      ->execute();

    return (int) $copy->id();
  }
}