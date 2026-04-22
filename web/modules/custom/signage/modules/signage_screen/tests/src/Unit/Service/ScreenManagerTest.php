<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_screen\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_screen\Service\ScreenManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

#[CoversClass(ScreenManager::class)]
#[Group('signage_screen')]
final class ScreenManagerTest extends UnitTestCase {

  private EntityTypeManagerInterface&MockObject $entityTypeManager;
  private EntityStorageInterface&MockObject $nodeStorage;
  private LoggerInterface&MockObject $logger;
  private ScreenManager $manager;

  protected function setUp(): void {
    parent::setUp();

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($this->nodeStorage);

    $this->logger = $this->createMock(LoggerInterface::class);

    $this->manager = new ScreenManager($this->entityTypeManager, $this->logger);
  }

  public function testAttachDefaultPlaylistDoesNothingForNonScreenBundle(): void {
    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('article');
    $screen->expects(self::never())->method('save');

    $this->nodeStorage->expects(self::never())->method('create');
    $this->logger->expects(self::never())->method('notice');

    $this->manager->attachDefaultPlaylist($screen);
  }

  public function testAttachDefaultPlaylistDoesNothingWhenPlaylistAlreadyAttached(): void {
    $field = $this->fieldStub(empty: FALSE);

    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('screen');
    $screen->method('get')->with('field_screen_playlist')->willReturn($field);
    $screen->expects(self::never())->method('save');
    $screen->expects(self::never())->method('set');

    $this->nodeStorage->expects(self::never())->method('create');
    $this->logger->expects(self::never())->method('notice');

    $this->manager->attachDefaultPlaylist($screen);
  }

  public function testAttachDefaultPlaylistCreatesPlaylistAndPersistsReference(): void {
    $field = $this->fieldStub(empty: TRUE);

    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('screen');
    $screen->method('get')->with('field_screen_playlist')->willReturn($field);
    $screen->method('label')->willReturn('Lobby Screen');
    $screen->method('getOwnerId')->willReturn(42);

    $playlist = $this->createMock(NodeInterface::class);
    $playlist->method('id')->willReturn(7);
    $playlist->method('label')->willReturn('Lobby Screen - Default Playlist');
    $playlist->expects(self::once())->method('save');

    $this->nodeStorage
      ->expects(self::once())
      ->method('create')
      ->with([
        'type' => 'playlist',
        'title' => 'Lobby Screen - Default Playlist',
        'status' => NodeInterface::PUBLISHED,
        'uid' => 42,
      ])
      ->willReturn($playlist);

    $screen
      ->expects(self::once())
      ->method('set')
      ->with('field_screen_playlist', ['target_id' => 7]);
    $screen->expects(self::once())->method('setNewRevision')->with(FALSE);
    $screen->expects(self::once())->method('save');

    $this->logger
      ->expects(self::once())
      ->method('notice')
      ->with(
        'Created default playlist @playlist for screen @screen.',
        [
          '@playlist' => 'Lobby Screen - Default Playlist',
          '@screen' => 'Lobby Screen',
        ]
      );

    $this->manager->attachDefaultPlaylist($screen);
  }

  public function testGetPlayerPathFormatsScreenId(): void {
    $screen = $this->createMock(NodeInterface::class);
    $screen->method('id')->willReturn(123);

    self::assertSame('/player/123', $this->manager->getPlayerPath($screen));
  }

  /**
   * Build a tiny stub for a field item list that just answers isEmpty().
   */
  private function fieldStub(bool $empty): object {
    return new class($empty) {
      public function __construct(private bool $empty) {}
      public function isEmpty(): bool {
        return $this->empty;
      }
    };
  }

}
