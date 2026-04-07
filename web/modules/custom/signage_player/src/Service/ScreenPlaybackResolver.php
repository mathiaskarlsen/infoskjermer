<?php

namespace Drupal\signage_player\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

class ScreenPlaybackResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public function resolve(int $screenId): array {
    $result = [
      'screen' => null,
      'playlist' => null,
      'status' => [
        'screen_found' => false,
        'playlist_found' => false,
        'items_found' => 0,
        'fallback_reason' => null,
      ],
      'items' => [],
    ];

    $screen = $this->entityTypeManager
      ->getStorage('node')
      ->load($screenId);

    if (!$screen instanceof NodeInterface || $screen->bundle() !== 'screen') {
      $result['status']['fallback_reason'] = 'screen_not_found';
      return $result;
    }

    $result['screen'] = [
      'id' => (int) $screen->id(),
      'title' => $screen->label(),
    ];
    $result['status']['screen_found'] = true;

    if (!$screen->hasField('field_playlist') || $screen->get('field_playlist')->isEmpty()) {
      $result['status']['fallback_reason'] = 'playlist_missing';
      return $result;
    }

    $playlist = $screen->get('field_playlist')->entity;

    if (!$playlist instanceof NodeInterface || $playlist->bundle() !== 'playlist') {
      $result['status']['fallback_reason'] = 'playlist_missing';
      return $result;
    }

    $result['playlist'] = [
      'id' => (int) $playlist->id(),
      'title' => $playlist->label(),
    ];
    $result['status']['playlist_found'] = true;

    if (!$playlist->hasField('field_items') || $playlist->get('field_items')->isEmpty()) {
      $result['status']['fallback_reason'] = 'playlist_empty';
      return $result;
    }

    $items = [];
    $paragraphs = $playlist->get('field_items')->referencedEntities();

    foreach ($paragraphs as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'playlist_item') {
        continue;
      }

      if (!$this->isEnabled($paragraph)) {
        continue;
      }

      if (!$this->isActiveNow($paragraph)) {
        continue;
      }

      $slide = $paragraph->get('field_slide')->entity ?? null;
      if (!$slide instanceof NodeInterface || $slide->bundle() !== 'slide') {
        continue;
      }

      $items[] = [
        'slide_id' => (int) $slide->id(),
        'title' => $slide->label(),
        'body' => $this->getSlideBody($slide),
        'media_url' => $this->getSlideMediaUrl($slide),
        'duration' => $this->getIntegerField($paragraph, 'field_duration_seconds', 10),
        'order' => $this->getIntegerField($paragraph, 'field_sort_order', 1),
      ];
    }

    usort($items, fn(array $a, array $b) => $a['order'] <=> $b['order']);

    $result['items'] = $items;
    $result['status']['items_found'] = count($items);

    if (!$items) {
      $result['status']['fallback_reason'] = 'no_active_items';
    }

    return $result;
  }

  protected function isEnabled(ParagraphInterface $item): bool {
    if (!$item->hasField('field_is_enabled') || $item->get('field_is_enabled')->isEmpty()) {
      return false;
    }

    return (bool) $item->get('field_is_enabled')->value;
  }

  protected function isActiveNow(ParagraphInterface $item): bool {
    $now = $this->time->getCurrentTime();

    $start = $this->getTimestampFromField($item, 'field_start_at');
    $end = $this->getTimestampFromField($item, 'field_end_at');

    if ($start === null && $end === null) {
      return true;
    }

    if ($start !== null && $end === null) {
      return $now >= $start;
    }

    if ($start === null && $end !== null) {
      return $now <= $end;
    }

    return $now >= $start && $now <= $end;
  }

  protected function getTimestampFromField(ParagraphInterface $item, string $fieldName): ?int {
    if (!$item->hasField($fieldName) || $item->get($fieldName)->isEmpty()) {
      return null;
    }

    $value = $item->get($fieldName)->value;
    if (!$value) {
      return null;
    }

    $date = new DrupalDateTime($value, 'UTC');
    return $date->getTimestamp();
  }

  protected function getIntegerField(ParagraphInterface $item, string $fieldName, int $default): int {
    if (!$item->hasField($fieldName) || $item->get($fieldName)->isEmpty()) {
      return $default;
    }

    return max(1, (int) $item->get($fieldName)->value);
  }

  protected function getSlideBody(NodeInterface $slide): string {
    if (!$slide->hasField('field_body') || $slide->get('field_body')->isEmpty()) {
      return '';
    }

    $value = (string) $slide->get('field_body')->value;
    return trim(html_entity_decode(strip_tags($value)));
  }

  protected function getSlideMediaUrl(NodeInterface $slide): ?string {
    if (!$slide->hasField('field_media') || $slide->get('field_media')->isEmpty()) {
      return null;
    }

    $media = $slide->get('field_media')->entity;
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return null;
    }

    $file = $media->get('field_media_image')->entity;
    if (!$file) {
      return null;
    }

    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

}