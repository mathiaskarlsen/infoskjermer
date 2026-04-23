<?php

declare(strict_types=1);

namespace Drupal\signage_share\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

final class ShareManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  public function createMessage(array $slideIds, int $recipientUid, string $message = ''): ?int {
    $slideIds = array_values(array_unique(array_map('intval', array_filter($slideIds))));

    if ($slideIds === []) {
      return NULL;
    }

    $transaction = $this->database->startTransaction();

    try {
      $this->database->insert('signage_share_message')
        ->fields([
          'sender_uid' => (int) $this->currentUser->id(),
          'recipient_uid' => $recipientUid,
          'message' => $message,
          'created' => $this->time->getCurrentTime(),
          'status' => 'new',
        ])
        ->execute();

      $messageId = (int) $this->database->lastInsertId();

      foreach ($slideIds as $slideId) {
        $this->database->insert('signage_share_message_item')
          ->fields([
            'share_message_id' => $messageId,
            'slide_id' => $slideId,
          ])
          ->execute();
      }

      unset($transaction);
      return $messageId;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  public function loadReceivedMessages(int $uid, array $statuses = ['new', 'seen', 'copied']): array {
    $query = $this->database->select('signage_share_message', 'm')
      ->fields('m')
      ->condition('recipient_uid', $uid)
      ->condition('status', $statuses, 'IN')
      ->orderBy('created', 'DESC');
  
    $results = $query->execute()->fetchAllAssoc('id');
  
    return is_array($results) ? $results : [];
  }

  public function loadMessage(int $messageId): ?object {
    $record = $this->database->select('signage_share_message', 'm')
      ->fields('m')
      ->condition('id', $messageId)
      ->execute()
      ->fetchObject();

    return $record ?: NULL;
  }

  public function loadMessageSlides(int $messageId): array {
    $query = $this->database->select('signage_share_message_item', 'i')
      ->fields('i', ['slide_id'])
      ->condition('share_message_id', $messageId)
      ->orderBy('id', 'ASC');

    $slideIds = array_map('intval', $query->execute()->fetchCol());

    if ($slideIds === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $slides = $storage->loadMultiple($slideIds);

    $orderedSlides = [];
    foreach ($slideIds as $slideId) {
      if (isset($slides[$slideId]) && $slides[$slideId] instanceof NodeInterface) {
        $orderedSlides[] = $slides[$slideId];
      }
    }

    return $orderedSlides;
  }

  public function markSeen(int $messageId): void {
    $this->database->update('signage_share_message')
      ->fields(['status' => 'seen'])
      ->condition('id', $messageId)
      ->condition('status', 'new')
      ->execute();
  }

  public function archive(int $messageId): void {
    $this->database->update('signage_share_message')
      ->fields(['status' => 'archived'])
      ->condition('id', $messageId)
      ->execute();
  }

  public function copyMessageSlides(int $messageId): array {
    $message = $this->loadMessage($messageId);
    if (!$message) {
      return [];
    }

    $slides = $this->loadMessageSlides($messageId);
    if ($slides === []) {
      return [];
    }

    $newIds = [];

    foreach ($slides as $slide) {
      if (!$slide instanceof NodeInterface || $slide->bundle() !== 'slide') {
        continue;
      }

      $newId = $this->duplicateSlide($slide);
      if ($newId !== NULL) {
        $newIds[] = $newId;
      }
    }

    if ($newIds !== []) {
      $this->database->update('signage_share_message')
        ->fields(['status' => 'copied'])
        ->condition('id', $messageId)
        ->execute();
    }
    
    $this->database->update('signage_share_message')
    ->fields(['status' => 'copied'])
    ->condition('id', $messageId)
    ->execute();
  
  return $newIds;
  }

  private function duplicateSlide(NodeInterface $original): ?int {
    if ($original->bundle() !== 'slide') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('node');

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

    return (int) $copy->id();
  }

}