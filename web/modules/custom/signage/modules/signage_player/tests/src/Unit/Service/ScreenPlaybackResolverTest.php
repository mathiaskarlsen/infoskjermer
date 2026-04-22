<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_player\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\signage_player\Service\ScreenPlaybackResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ScreenPlaybackResolver::class)]
#[Group('signage_player')]
final class ScreenPlaybackResolverTest extends UnitTestCase {

  /**
   * Fixed "now" used by the time mock — 2023-11-14T22:13:20Z.
   */
  private const NOW = 1_700_000_000;

  private EntityTypeManagerInterface&MockObject $entityTypeManager;
  private EntityStorageInterface&MockObject $nodeStorage;
  private TimeInterface&MockObject $time;
  private FileUrlGeneratorInterface&MockObject $fileUrlGenerator;
  private ScreenPlaybackResolver $resolver;

  protected function setUp(): void {
    parent::setUp();

    // DrupalDateTime touches the language manager during construction.
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn(new Language(['id' => 'en']));
    $container = new ContainerBuilder();
    $container->set('language_manager', $languageManager);
    \Drupal::setContainer($container);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($this->nodeStorage);

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getCurrentTime')->willReturn(self::NOW);

    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $this->fileUrlGenerator
      ->method('generateAbsoluteString')
      ->willReturnCallback(static fn(string $uri): string => 'https://example.test/' . ltrim($uri, '/'));

    $this->resolver = new ScreenPlaybackResolver(
      $this->entityTypeManager,
      $this->time,
      $this->fileUrlGenerator,
    );
  }

  public function testReturnsScreenNotFoundWhenStorageReturnsNull(): void {
    $this->nodeStorage->method('load')->with(99)->willReturn(NULL);

    $result = $this->resolver->resolve(99);

    self::assertFalse($result['status']['screen_found']);
    self::assertSame('screen_not_found', $result['status']['fallback_reason']);
    self::assertNull($result['screen']);
    self::assertSame([], $result['playlists']);
    self::assertSame([], $result['items']);
  }

  public function testReturnsScreenNotFoundForWrongBundle(): void {
    $node = $this->mockEntity(NodeInterface::class, [], [
      'bundle' => 'page',
      'id' => '5',
      'label' => 'Some page',
    ]);
    $this->nodeStorage->method('load')->with(5)->willReturn($node);

    $result = $this->resolver->resolve(5);

    self::assertSame('screen_not_found', $result['status']['fallback_reason']);
    self::assertFalse($result['status']['screen_found']);
  }

  public function testReturnsPlaylistsMissingWhenScreenHasNoPlaylistField(): void {
    $screen = $this->mockEntity(NodeInterface::class, [
      'field_screen_playlist' => $this->fieldItem(),
    ], [
      'bundle' => 'screen',
      'id' => '1',
      'label' => 'Screen one',
    ]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertTrue($result['status']['screen_found']);
    self::assertSame(0, $result['status']['playlists_found']);
    self::assertSame('playlists_missing', $result['status']['fallback_reason']);
    self::assertSame([], $result['playlists']);
  }

  public function testReturnsPlaylistsMissingWhenAllReferencesAreNonPlaylists(): void {
    $page = $this->mockEntity(NodeInterface::class, [], ['bundle' => 'page']);
    $screen = $this->mockScreen(1, [$page]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(0, $result['status']['playlists_found']);
    self::assertSame('playlists_missing', $result['status']['fallback_reason']);
  }

  public function testReturnsPlaylistsEmptyWhenPlaylistsHaveNoItems(): void {
    $playlist = $this->mockPlaylist(10, []);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['playlists_found']);
    self::assertSame([['id' => 10, 'title' => 'Playlist 10']], $result['playlists']);
    self::assertSame('playlists_empty', $result['status']['fallback_reason']);
    self::assertSame([], $result['items']);
  }

  public function testCollectsItemsFromSinglePlaylist(): void {
    $slide = $this->mockSlide(101, title: 'Hello', body: 'Body text');
    $image = $this->mockImageParagraph($slide, duration: 7);
    $playlistItem = $this->mockPlaylistItem(type: $image, order: 1);
    $playlist = $this->mockPlaylist(10, [$playlistItem]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertNull($result['status']['fallback_reason']);
    self::assertSame(1, $result['status']['items_found']);
    self::assertCount(1, $result['items']);

    $item = $result['items'][0];
    self::assertSame(101, $item['slide_id']);
    self::assertSame('image', $item['type']);
    self::assertSame('Hello', $item['title']);
    self::assertSame('Body text', $item['body']);
    self::assertSame(7, $item['duration']);
    self::assertSame(1, $item['order']);
    self::assertStringStartsWith('https://example.test/', $item['media_url']);
  }

  public function testCollectsItemsFromMultiplePlaylistsInReferenceOrder(): void {
    $slideA = $this->mockSlide(201, title: 'A');
    $slideB = $this->mockSlide(202, title: 'B');
    $slideC = $this->mockSlide(203, title: 'C');

    $playlistA = $this->mockPlaylist(10, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($slideA), order: 1),
      $this->mockPlaylistItem(type: $this->mockImageParagraph($slideB), order: 2),
    ]);
    $playlistB = $this->mockPlaylist(20, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($slideC), order: 1),
    ]);

    $screen = $this->mockScreen(1, [$playlistA, $playlistB]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(2, $result['status']['playlists_found']);
    self::assertSame(3, $result['status']['items_found']);
    self::assertSame([201, 202, 203], array_column($result['items'], 'slide_id'));
  }

  public function testDeduplicatesSlidesAcrossPlaylistsKeepingFirstOccurrence(): void {
    $sharedSlide = $this->mockSlide(300, title: 'Shared');
    $uniqueSlide = $this->mockSlide(301, title: 'Unique');

    // Same slide entity referenced from two image paragraphs in two playlists.
    $playlistA = $this->mockPlaylist(10, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($sharedSlide, duration: 5), order: 1),
    ]);
    $playlistB = $this->mockPlaylist(20, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($sharedSlide, duration: 99), order: 1),
      $this->mockPlaylistItem(type: $this->mockImageParagraph($uniqueSlide), order: 2),
    ]);

    $screen = $this->mockScreen(1, [$playlistA, $playlistB]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(2, $result['status']['items_found']);
    self::assertSame(1, $result['status']['duplicate_items_skipped']);
    self::assertSame([300, 301], array_column($result['items'], 'slide_id'));
    // First occurrence wins — duration from playlist A's paragraph.
    self::assertSame(5, $result['items'][0]['duration']);
  }

  public function testSkipsDisabledItems(): void {
    $slide = $this->mockSlide(400);
    $disabled = $this->mockPlaylistItem(
      enabled: FALSE,
      type: $this->mockImageParagraph($slide),
    );
    $playlist = $this->mockPlaylist(10, [$disabled]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['total_items']);
    self::assertSame(0, $result['status']['enabled_items']);
    self::assertSame([], $result['items']);
    self::assertSame('all_items_disabled', $result['status']['fallback_reason']);
  }

  public function testSkipsItemsOutsideTimeWindow(): void {
    $slide = $this->mockSlide(500);
    // Window ended a day before NOW.
    $expired = $this->mockPlaylistItem(
      start: '2023-01-01T00:00:00',
      end: '2023-11-13T00:00:00',
      type: $this->mockImageParagraph($slide),
    );
    $playlist = $this->mockPlaylist(10, [$expired]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['enabled_items']);
    self::assertSame(0, $result['status']['time_window_items']);
    self::assertSame('all_items_outside_schedule', $result['status']['fallback_reason']);
  }

  public function testIncludesItemsInsideTimeWindow(): void {
    $slide = $this->mockSlide(501);
    $active = $this->mockPlaylistItem(
      start: '2023-01-01T00:00:00',
      end: '2030-01-01T00:00:00',
      type: $this->mockImageParagraph($slide),
    );
    $playlist = $this->mockPlaylist(10, [$active]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['items_found']);
    self::assertNull($result['status']['fallback_reason']);
  }

  public function testSkipsNonImageTypedParagraphs(): void {
    $videoTyped = $this->mockEntity(ParagraphInterface::class, [], ['bundle' => 'video']);
    $playlistItem = $this->mockPlaylistItem(type: $videoTyped);
    $playlist = $this->mockPlaylist(10, [$playlistItem]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['typed_items']);
    self::assertSame(0, $result['status']['image_items']);
    self::assertSame('no_image_slides', $result['status']['fallback_reason']);
  }

  public function testSkipsImageParagraphWithoutValidSlide(): void {
    // Image paragraph with no slide attached.
    $playlistItem = $this->mockPlaylistItem(type: $this->mockImageParagraph(NULL));
    $playlist = $this->mockPlaylist(10, [$playlistItem]);
    $screen = $this->mockScreen(1, [$playlist]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame(1, $result['status']['image_items']);
    self::assertSame(0, $result['status']['valid_slide_items']);
    self::assertSame('no_valid_slides', $result['status']['fallback_reason']);
  }

  public function testSortsItemsWithinPlaylistByOrderAndConcatenatesAcrossPlaylists(): void {
    // Playlist A items have orders 5 and 1 — should be reordered to 1, 5.
    $a1 = $this->mockSlide(601);
    $a5 = $this->mockSlide(605);
    $playlistA = $this->mockPlaylist(10, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($a5), order: 5),
      $this->mockPlaylistItem(type: $this->mockImageParagraph($a1), order: 1),
    ]);

    // Playlist B item with order 2 — must come AFTER all of A despite low order.
    $b2 = $this->mockSlide(702);
    $playlistB = $this->mockPlaylist(20, [
      $this->mockPlaylistItem(type: $this->mockImageParagraph($b2), order: 2),
    ]);

    $screen = $this->mockScreen(1, [$playlistA, $playlistB]);
    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $result = $this->resolver->resolve(1);

    self::assertSame([601, 605, 702], array_column($result['items'], 'slide_id'));
  }

  /**
   * Build a NodeInterface mock representing a screen and tying to playlists.
   */
  private function mockScreen(int $id, array $playlists, string $title = 'Screen'): NodeInterface {
    $field = $playlists
      ? $this->fieldItem(['referenced' => $playlists])
      : $this->fieldItem();

    return $this->mockEntity(NodeInterface::class, [
      'field_screen_playlist' => $field,
    ], [
      'bundle' => 'screen',
      'id' => (string) $id,
      'label' => $title,
    ]);
  }

  /**
   * Build a NodeInterface mock representing a playlist.
   */
  private function mockPlaylist(int $id, array $items): NodeInterface {
    $field = $items
      ? $this->fieldItem(['referenced' => $items])
      : $this->fieldItem();

    return $this->mockEntity(NodeInterface::class, [
      'field_playlist_items' => $field,
    ], [
      'bundle' => 'playlist',
      'id' => (string) $id,
      'label' => 'Playlist ' . $id,
    ]);
  }

  /**
   * Build a ParagraphInterface mock representing a playlist_item.
   */
  private function mockPlaylistItem(
    bool $enabled = TRUE,
    ?string $start = NULL,
    ?string $end = NULL,
    ?ParagraphInterface $type = NULL,
    int $order = 1,
  ): ParagraphInterface {
    $fields = [
      'field_enabled' => $this->fieldItem(['value' => $enabled ? '1' : '0']),
      'field_start_at' => $start !== NULL ? $this->fieldItem(['value' => $start]) : $this->fieldItem(),
      'field_end_at' => $end !== NULL ? $this->fieldItem(['value' => $end]) : $this->fieldItem(),
      'field_type' => $type !== NULL ? $this->fieldItem(['entity' => $type]) : $this->fieldItem(),
      'field_sort_order' => $this->fieldItem(['value' => (string) $order]),
    ];

    return $this->mockEntity(ParagraphInterface::class, $fields, [
      'bundle' => 'playlist_item',
    ]);
  }

  /**
   * Build a ParagraphInterface mock representing an image typed paragraph.
   */
  private function mockImageParagraph(?NodeInterface $slide, int $duration = 10): ParagraphInterface {
    $fields = [
      'field_image' => $slide !== NULL ? $this->fieldItem(['entity' => $slide]) : $this->fieldItem(),
      'field_duration_seconds' => $this->fieldItem(['value' => (string) $duration]),
    ];

    return $this->mockEntity(ParagraphInterface::class, $fields, [
      'bundle' => 'image',
    ]);
  }

  /**
   * Build a NodeInterface mock representing a slide node.
   */
  private function mockSlide(int $id, string $title = 'Slide', string $body = '', string $type = 'image', bool $withMedia = TRUE): NodeInterface {
    $media = $withMedia ? $this->mockMedia('public://slide-' . $id . '.jpg') : NULL;

    $fields = [
      'field_slide_type' => $this->fieldItem(['value' => $type]),
      'field_slide_body' => $body !== '' ? $this->fieldItem(['value' => $body]) : $this->fieldItem(),
      'field_slide_media' => $media !== NULL ? $this->fieldItem(['entity' => $media]) : $this->fieldItem(),
    ];

    return $this->mockEntity(NodeInterface::class, $fields, [
      'bundle' => 'slide',
      'id' => (string) $id,
      'label' => $title,
    ]);
  }

  /**
   * Build a MediaInterface mock with a file attached.
   */
  private function mockMedia(string $uri): MediaInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($uri);

    return $this->mockEntity(MediaInterface::class, [
      'field_media_image' => $this->fieldItem(['entity' => $file]),
    ]);
  }

  /**
   * Generic entity mock with hasField/get dispatch and arbitrary extras.
   *
   * @param array<string, object> $fields
   *   Map of field name → field item list stub.
   * @param array<string, mixed> $extras
   *   Map of method name → return value (e.g. 'bundle' => 'screen').
   */
  private function mockEntity(string $interface, array $fields, array $extras = []): MockObject {
    $entity = $this->createMock($interface);

    $entity->method('hasField')->willReturnCallback(
      static fn(string $name): bool => array_key_exists($name, $fields)
    );
    $entity->method('get')->willReturnCallback(
      static function (string $name) use ($fields) {
        if (!array_key_exists($name, $fields)) {
          throw new \RuntimeException("Unexpected field access: $name");
        }
        return $fields[$name];
      }
    );

    foreach ($extras as $method => $value) {
      $entity->method($method)->willReturn($value);
    }

    return $entity;
  }

  /**
   * Build a stub for a field item list.
   *
   * The resolver only relies on duck-typed access (isEmpty(),
   * referencedEntities(), ->value, ->entity) so a small anonymous class is
   * simpler and faster than mocking the full interface.
   *
   * @param array{value?: mixed, entity?: object, referenced?: array} $opts
   *   Optional values to expose. Empty options → isEmpty() returns TRUE.
   */
  private function fieldItem(array $opts = []): object {
    return new class($opts) {
      public mixed $value;
      public mixed $entity;
      private array $referenced;
      private bool $empty;

      public function __construct(array $opts) {
        $this->value = $opts['value'] ?? NULL;
        $this->entity = $opts['entity'] ?? NULL;
        $this->referenced = $opts['referenced'] ?? [];
        $this->empty = $opts === [];
      }

      public function isEmpty(): bool {
        return $this->empty;
      }

      public function referencedEntities(): array {
        return $this->referenced;
      }

      public function first(): static {
        return $this;
      }
    };
  }

}
