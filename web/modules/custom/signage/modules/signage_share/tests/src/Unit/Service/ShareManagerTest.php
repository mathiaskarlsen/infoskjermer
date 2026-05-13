<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_share\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_share\Service\ShareManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ShareManager::class)]
#[Group('signage_share')]
final class ShareManagerTest extends UnitTestCase {

  private const NOW = 1_700_000_000;
  private const SENDER_UID = 5;

  private Connection&MockObject $database;
  private EntityTypeManagerInterface&MockObject $entityTypeManager;
  private EntityStorageInterface&MockObject $nodeStorage;
  private AccountProxyInterface&MockObject $currentUser;
  private TimeInterface&MockObject $time;
  private ShareManager $manager;

  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($this->nodeStorage);

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(self::SENDER_UID);

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getCurrentTime')->willReturn(self::NOW);

    $this->manager = new ShareManager(
      $this->database,
      $this->entityTypeManager,
      $this->currentUser,
      $this->time,
    );
  }

  public function testCreateMessageReturnsNullWhenNoSlidesProvided(): void {
    $this->database->expects(self::never())->method('startTransaction');
    $this->database->expects(self::never())->method('insert');

    self::assertNull($this->manager->createMessage([], 42, 'hello'));
  }

  public function testCreateMessageReturnsNullWhenAllSlideIdsFalsy(): void {
    // array_filter drops zero / null / empty strings.
    $this->database->expects(self::never())->method('insert');

    self::assertNull($this->manager->createMessage([0, NULL, ''], 42));
  }

  public function testCreateMessageInsertsMessageAndItemsAndReturnsId(): void {
    $messageInsert = $this->mockInsert();
    $messageInsert
      ->expects(self::once())
      ->method('fields')
      ->with([
        'sender_uid' => self::SENDER_UID,
        'recipient_uid' => 42,
        'message' => 'hi there',
        'created' => self::NOW,
        'status' => 'new',
      ])
      ->willReturnSelf();
    $messageInsert->expects(self::once())->method('execute');

    $itemInsert = $this->mockInsert();
    $itemFields = [];
    // Two slide ids end up de-duplicated and cast to int.
    $itemInsert
      ->expects(self::exactly(2))
      ->method('fields')
      ->willReturnCallback(function (array $fields) use ($itemInsert, &$itemFields): Insert {
        $itemFields[] = $fields;
        return $itemInsert;
      });
    $itemInsert->expects(self::exactly(2))->method('execute');

    $this->database
      ->expects(self::exactly(3))
      ->method('insert')
      ->willReturnCallback(function (string $table) use ($messageInsert, $itemInsert): Insert {
        return match ($table) {
          'signage_share_message' => $messageInsert,
          'signage_share_message_item' => $itemInsert,
        };
      });

    $this->database
      ->expects(self::once())
      ->method('startTransaction')
      ->willReturn($this->stubTransaction());
    $this->database->method('lastInsertId')->willReturn('17');

    $result = $this->manager->createMessage(['12', 12, 34], 42, 'hi there');

    self::assertSame(17, $result);
    self::assertSame(
      [
        ['share_message_id' => 17, 'slide_id' => 12],
        ['share_message_id' => 17, 'slide_id' => 34],
      ],
      $itemFields,
    );
  }

  public function testCreateMessageRollsBackAndRethrowsOnFailure(): void {
    $boom = new \RuntimeException('db down');

    $messageInsert = $this->mockInsert();
    $messageInsert->method('fields')->willReturnSelf();
    $messageInsert->method('execute')->willThrowException($boom);

    $this->database->method('insert')->willReturn($messageInsert);

    $transaction = $this->stubTransaction();
    $this->database->method('startTransaction')->willReturn($transaction);

    $this->expectExceptionObject($boom);

    try {
      $this->manager->createMessage([1], 42);
    }
    finally {
      self::assertSame(1, $transaction->rollBackCalls, 'rollBack() should be called exactly once on failure.');
    }
  }

  public function testLoadReceivedMessagesReturnsRowsKeyedById(): void {
    $rows = [
      11 => (object) ['id' => 11, 'recipient_uid' => 7, 'status' => 'new'],
      9 => (object) ['id' => 9, 'recipient_uid' => 7, 'status' => 'seen'],
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllAssoc')->with('id')->willReturn($rows);

    $captured = [];

    $select = $this->mockSelect();
    $select->expects(self::once())->method('fields')->with('m')->willReturnSelf();
    $select
      ->expects(self::exactly(2))
      ->method('condition')
      ->willReturnCallback(function (string $field, mixed $value, ?string $op = NULL) use ($select, &$captured) {
        $captured[] = ['field' => $field, 'value' => $value, 'op' => $op];
        return $select;
      });
    $select->expects(self::once())->method('orderBy')->with('created', 'DESC')->willReturnSelf();
    $select->expects(self::once())->method('execute')->willReturn($statement);

    $this->database
      ->expects(self::once())
      ->method('select')
      ->with('signage_share_message', 'm')
      ->willReturn($select);

    self::assertSame($rows, $this->manager->loadReceivedMessages(7));
    self::assertSame(
      [
        // PHPUnit applies the interface's default (`$operator = '='`) when the
        // production code only passes two args to ->condition().
        ['field' => 'recipient_uid', 'value' => 7, 'op' => '='],
        ['field' => 'status', 'value' => ['new', 'seen', 'copied'], 'op' => 'IN'],
      ],
      $captured,
    );
  }

  public function testLoadReceivedMessagesAcceptsCustomStatusFilter(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllAssoc')->willReturn([]);

    $capturedStatuses = NULL;

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $select
      ->method('condition')
      ->willReturnCallback(function (string $field, mixed $value, ?string $op = NULL) use ($select, &$capturedStatuses) {
        if ($field === 'status') {
          $capturedStatuses = $value;
        }
        return $select;
      });

    $this->database->method('select')->willReturn($select);

    $this->manager->loadReceivedMessages(7, ['archived']);

    self::assertSame(['archived'], $capturedStatuses);
  }

  public function testLoadMessageReturnsNullWhenNotFound(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    self::assertNull($this->manager->loadMessage(123));
  }

  public function testLoadMessageReturnsRecordWhenFound(): void {
    $record = (object) ['id' => 123, 'status' => 'new'];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($record);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->expects(self::once())->method('condition')->with('id', 123)->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->with('signage_share_message', 'm')->willReturn($select);

    self::assertSame($record, $this->manager->loadMessage(123));
  }

  public function testLoadMessageSlidesReturnsEmptyWhenNoItemsLinked(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([]);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);
    $this->nodeStorage->expects(self::never())->method('loadMultiple');

    self::assertSame([], $this->manager->loadMessageSlides(123));
  }

  public function testLoadMessageSlidesReturnsSlideNodesInInsertionOrder(): void {
    $statement = $this->createMock(StatementInterface::class);
    // Stored insertion order in the join table.
    $statement->method('fetchCol')->willReturn(['11', '22', '33']);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->expects(self::once())->method('condition')->with('share_message_id', 123)->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    $slide11 = $this->createMock(NodeInterface::class);
    $slide22 = $this->createMock(NodeInterface::class);
    $slide33 = $this->createMock(NodeInterface::class);

    // Note: loadMultiple returns nodes keyed by id but in an unrelated order.
    $this->nodeStorage
      ->method('loadMultiple')
      ->with([11, 22, 33])
      ->willReturn([22 => $slide22, 11 => $slide11, 33 => $slide33]);

    $result = $this->manager->loadMessageSlides(123);

    self::assertSame([$slide11, $slide22, $slide33], $result);
  }

  public function testLoadMessageSlidesSkipsNonNodeResults(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['11', '22']);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $slide11 = $this->createMock(NodeInterface::class);
    // Missing id 22 in the loaded set (e.g. deleted).
    $this->nodeStorage->method('loadMultiple')->willReturn([11 => $slide11]);

    self::assertSame([$slide11], $this->manager->loadMessageSlides(123));
  }

  public function testMarkSeenOnlyUpdatesNewMessages(): void {
    $update = $this->mockUpdate();
    $update
      ->expects(self::once())
      ->method('fields')
      ->with(['status' => 'seen'])
      ->willReturnSelf();

    $conditions = [];
    $update
      ->expects(self::exactly(2))
      ->method('condition')
      ->willReturnCallback(function (string $field, mixed $value) use ($update, &$conditions) {
        $conditions[$field] = $value;
        return $update;
      });
    $update->expects(self::once())->method('execute');

    $this->database
      ->expects(self::once())
      ->method('update')
      ->with('signage_share_message')
      ->willReturn($update);

    $this->manager->markSeen(7);

    self::assertSame(['id' => 7, 'status' => 'new'], $conditions);
  }

  public function testArchiveSetsStatusArchived(): void {
    $update = $this->mockUpdate();
    $update
      ->expects(self::once())
      ->method('fields')
      ->with(['status' => 'archived'])
      ->willReturnSelf();
    $update
      ->expects(self::once())
      ->method('condition')
      ->with('id', 7)
      ->willReturnSelf();
    $update->expects(self::once())->method('execute');

    $this->database
      ->expects(self::once())
      ->method('update')
      ->with('signage_share_message')
      ->willReturn($update);

    $this->manager->archive(7);
  }

  public function testCopyMessageSlidesReturnsEmptyWhenMessageMissing(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);

    $select = $this->mockSelect();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    // No update on missing message.
    $this->database->expects(self::never())->method('update');

    self::assertSame([], $this->manager->copyMessageSlides(999));
  }

  public function testCopyMessageSlidesReturnsEmptyWhenNoSlidesAttached(): void {
    // First select: loadMessage → returns record. Second select: loadMessageSlides → empty.
    $messageStmt = $this->createMock(StatementInterface::class);
    $messageStmt->method('fetchObject')->willReturn((object) ['id' => 1]);

    $slideStmt = $this->createMock(StatementInterface::class);
    $slideStmt->method('fetchCol')->willReturn([]);

    $this->database
      ->method('select')
      ->willReturnCallback(function (string $table) use ($messageStmt, $slideStmt): SelectInterface {
        $select = $this->mockSelect();
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('execute')->willReturn(
          $table === 'signage_share_message' ? $messageStmt : $slideStmt,
        );
        return $select;
      });

    $this->database->expects(self::never())->method('update');

    self::assertSame([], $this->manager->copyMessageSlides(1));
  }

  public function testCopyMessageSlidesDuplicatesEligibleSlidesAndMarksCopied(): void {
    $messageStmt = $this->createMock(StatementInterface::class);
    $messageStmt->method('fetchObject')->willReturn((object) ['id' => 1]);

    $slideStmt = $this->createMock(StatementInterface::class);
    $slideStmt->method('fetchCol')->willReturn(['11', '22', '33']);

    $this->database
      ->method('select')
      ->willReturnCallback(function (string $table) use ($messageStmt, $slideStmt): SelectInterface {
        $select = $this->mockSelect();
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('execute')->willReturn(
          $table === 'signage_share_message' ? $messageStmt : $slideStmt,
        );
        return $select;
      });

    $slide11 = $this->mockSlide(11, 'Slide A');
    // bundle != 'slide' — should be skipped entirely.
    $slide22 = $this->createMock(NodeInterface::class);
    $slide22->method('bundle')->willReturn('article');
    $slide33 = $this->mockSlide(33, 'Slide C');

    $this->nodeStorage
      ->method('loadMultiple')
      ->willReturn([11 => $slide11, 22 => $slide22, 33 => $slide33]);

    $copy11 = $this->mockCopyNode(111);
    $copy33 = $this->mockCopyNode(333);

    $this->nodeStorage
      ->expects(self::exactly(2))
      ->method('create')
      ->willReturnOnConsecutiveCalls($copy11, $copy33);

    // The implementation issues the "copied" update twice (a duplicated block).
    $update = $this->mockUpdate();
    $update->method('fields')->with(['status' => 'copied'])->willReturnSelf();
    $update->method('condition')->with('id', 1)->willReturnSelf();
    $update->expects(self::exactly(2))->method('execute');

    $this->database
      ->expects(self::exactly(2))
      ->method('update')
      ->with('signage_share_message')
      ->willReturn($update);

    $result = $this->manager->copyMessageSlides(1);

    self::assertSame([111, 333], $result);
  }

  public function testCopyMessageSlidesSkipsCreatesWhenNoEligibleBundles(): void {
    $messageStmt = $this->createMock(StatementInterface::class);
    $messageStmt->method('fetchObject')->willReturn((object) ['id' => 1]);

    $slideStmt = $this->createMock(StatementInterface::class);
    $slideStmt->method('fetchCol')->willReturn(['22']);

    $this->database
      ->method('select')
      ->willReturnCallback(function (string $table) use ($messageStmt, $slideStmt): SelectInterface {
        $select = $this->mockSelect();
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('execute')->willReturn(
          $table === 'signage_share_message' ? $messageStmt : $slideStmt,
        );
        return $select;
      });

    $wrongBundle = $this->createMock(NodeInterface::class);
    $wrongBundle->method('bundle')->willReturn('article');
    $this->nodeStorage->method('loadMultiple')->willReturn([22 => $wrongBundle]);

    $this->nodeStorage->expects(self::never())->method('create');

    // The duplicated trailing update still fires because that block is
    // unconditional in the current implementation.
    $update = $this->mockUpdate();
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->expects(self::once())->method('execute');
    $this->database->expects(self::once())->method('update')->willReturn($update);

    self::assertSame([], $this->manager->copyMessageSlides(1));
  }

  /**
   * Build a slide node with the optional body / media fields populated.
   */
  private function mockSlide(int $id, string $title): NodeInterface&MockObject {
    $slide = $this->createMock(NodeInterface::class);
    $slide->method('id')->willReturn($id);
    $slide->method('bundle')->willReturn('slide');
    $slide->method('label')->willReturn($title);
    $slide->method('isPublished')->willReturn(TRUE);
    // Both optional fields exist and are non-empty so duplicateSlide() copies them.
    $slide->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, ['field_slide_body', 'field_slide_media'], TRUE),
    );
    $slide->method('get')->willReturnCallback(function (string $name) use ($title) {
      $field = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(FALSE);
      $field->method('getValue')->willReturn([['value' => 'body of ' . $title]]);
      return $field;
    });
    return $slide;
  }

  private function mockCopyNode(int $newId): NodeInterface&MockObject {
    $copy = $this->createMock(NodeInterface::class);
    $copy->method('id')->willReturn($newId);
    $copy->expects(self::once())->method('save');
    return $copy;
  }

  /**
   * A Transaction stub whose constructor and destructor are no-ops.
   *
   * PHPUnit's auto-mocking excludes __destruct(), so a plain createMock()
   * leaves the real destructor in place — which accesses the readonly
   * $connection property that was never initialized, blowing up at GC time.
   */
  private function stubTransaction(): Transaction {
    return new class extends Transaction {
      public int $rollBackCalls = 0;
      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {}
      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __destruct() {}
      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function rollBack() {
        $this->rollBackCalls++;
      }
    };
  }

  private function mockInsert(): Insert&MockObject {
    return $this->createMock(Insert::class);
  }

  private function mockUpdate(): Update&MockObject {
    return $this->createMock(Update::class);
  }

  private function mockSelect(): SelectInterface&MockObject {
    return $this->createMock(SelectInterface::class);
  }

}
