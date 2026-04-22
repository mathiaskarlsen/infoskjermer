<?php

namespace Drupal\signage_player\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
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
      'playlists' => [],
      'status' => [
        'screen_found' => false,
        'playlists_found' => 0,
        'items_found' => 0,
        'total_items' => 0,
        'enabled_items' => 0,
        'time_window_items' => 0,
        'typed_items' => 0,
        'image_items' => 0,
        'valid_slide_items' => 0,
        'duplicate_items_skipped' => 0,
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

    if (!$screen->hasField('field_screen_playlist') || $screen->get('field_screen_playlist')->isEmpty()) {
      $result['status']['fallback_reason'] = 'playlists_missing';
      return $result;
    }

    $playlists = [];
    foreach ($screen->get('field_screen_playlist')->referencedEntities() as $playlist) {
      if ($playlist instanceof NodeInterface && $playlist->bundle() === 'playlist') {
        $playlists[] = $playlist;
      }
    }

    if (!$playlists) {
      $result['status']['fallback_reason'] = 'playlists_missing';
      return $result;
    }

    $result['status']['playlists_found'] = count($playlists);

    $items = [];
    $seenSlideIds = [];

    foreach ($playlists as $playlist) {
      $result['playlists'][] = [
        'id' => (int) $playlist->id(),
        'title' => $playlist->label(),
      ];

      if (!$playlist->hasField('field_playlist_items') || $playlist->get('field_playlist_items')->isEmpty()) {
        continue;
      }

      $playlistItems = [];

      foreach ($playlist->get('field_playlist_items')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'playlist_item') {
          continue;
        }

        $result['status']['total_items']++;

        if (!$this->isEnabled($paragraph)) {
          continue;
        }

        $result['status']['enabled_items']++;

        if (!$this->isActiveNow($paragraph)) {
          continue;
        }

        $result['status']['time_window_items']++;

        $typed = $this->getTypedParagraph($paragraph);
        if (!$typed) {
          continue;
        }

        $result['status']['typed_items']++;

        // Only image slides are implemented in this iteration. Text and video
        // paragraphs are intentionally ignored until their renderers exist.
        if ($typed->bundle() !== 'image') {
          continue;
        }

        $result['status']['image_items']++;

        $item = $this->buildImageItem($paragraph, $typed);
        if ($item === null) {
          continue;
        }

        $result['status']['valid_slide_items']++;

        if (isset($seenSlideIds[$item['slide_id']])) {
          $result['status']['duplicate_items_skipped']++;
          continue;
        }

        $seenSlideIds[$item['slide_id']] = true;
        $playlistItems[] = $item;
      }

      usort($playlistItems, fn(array $a, array $b) => $a['order'] <=> $b['order']);

      foreach ($playlistItems as $item) {
        $items[] = $item;
      }
    }

    $result['items'] = $items;
    $result['status']['items_found'] = count($items);

    if (!$items) {
      $result['status']['fallback_reason'] = $this->inferFallbackReason($result['status']);
    }

    return $result;
  }

  protected function inferFallbackReason(array $status): string {
    if ($status['total_items'] === 0) {
      return 'playlists_empty';
    }
    if ($status['enabled_items'] === 0) {
      return 'all_items_disabled';
    }
    if ($status['time_window_items'] === 0) {
      return 'all_items_outside_schedule';
    }
    if ($status['typed_items'] === 0) {
      return 'no_typed_items';
    }
    if ($status['image_items'] === 0) {
      return 'no_image_slides';
    }
    return 'no_valid_slides';
  }

  protected function isEnabled(ParagraphInterface $item): bool {
    if (!$item->hasField('field_enabled') || $item->get('field_enabled')->isEmpty()) {
      return false;
    }

    return (bool) $item->get('field_enabled')->value;
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

    try {
      $date = new DrupalDateTime($value, 'UTC');
      return $date->getTimestamp();
    }
    catch (\Throwable $e) {
      return null;
    }
  }

  protected function getTypedParagraph(ParagraphInterface $item): ?ParagraphInterface {
    if (!$item->hasField('field_type') || $item->get('field_type')->isEmpty()) {
      return null;
    }

    $typed = $item->get('field_type')->entity;
    return $typed instanceof ParagraphInterface ? $typed : null;
  }

  protected function buildImageItem(ParagraphInterface $playlistItem, ParagraphInterface $imageParagraph): ?array {
    if (!$imageParagraph->hasField('field_image') || $imageParagraph->get('field_image')->isEmpty()) {
      return null;
    }

    $slide = $imageParagraph->get('field_image')->entity;
    if (!$slide instanceof NodeInterface || $slide->bundle() !== 'slide') {
      return null;
    }

    if ($this->getSlideType($slide) !== 'image') {
      return null;
    }

    $mediaUrl = $this->getSlideMediaUrl($slide);
    if ($mediaUrl === null) {
      return null;
    }

    return [
      'slide_id' => (int) $slide->id(),
      'type' => 'image',
      'title' => $slide->label(),
      'body' => $this->getSlideBody($slide),
      'media_url' => $mediaUrl,
      'duration' => $this->getIntegerField($imageParagraph, 'field_duration_seconds', 10),
      'order' => $this->getIntegerField($playlistItem, 'field_sort_order', 1),
      'start_at' => $playlistItem->get('field_start_at')->value ?? null,
      'end_at' => $playlistItem->get('field_end_at')->value ?? null,
    ];
  }

  protected function getIntegerField(ParagraphInterface $item, string $fieldName, int $default): int {
    if (!$item->hasField($fieldName) || $item->get($fieldName)->isEmpty()) {
      return $default;
    }

    return max(1, (int) $item->get($fieldName)->value);
  }

  protected function getSlideType(NodeInterface $slide): ?string {
    if (!$slide->hasField('field_slide_type') || $slide->get('field_slide_type')->isEmpty()) {
      return null;
    }

    return (string) $slide->get('field_slide_type')->value;
  }

  protected function getSlideBody(NodeInterface $slide): string {
    if (!$slide->hasField('field_slide_body') || $slide->get('field_slide_body')->isEmpty()) {
      return '';
    }

    $value = (string) $slide->get('field_slide_body')->value;
    return trim(html_entity_decode(strip_tags($value)));
  }

  protected function getSlideMediaUrl(NodeInterface $slide): ?string {
    if (!$slide->hasField('field_slide_media') || $slide->get('field_slide_media')->isEmpty()) {
      return null;
    }

    $media = $slide->get('field_slide_media')->entity;
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
