<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_access\Unit;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[Group('signage_access')]
final class SignageAccessHookTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();

    // The AccessResult cacheability methods consult the cache contexts manager.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    require_once __DIR__ . '/../../../signage_access.module';
  }

  public function testNonScreenBundleReturnsNeutral(): void {
    $node = $this->mockNode(bundle: 'article');
    $account = $this->mockAccount(uid: 5);

    $result = signage_access_node_access($node, 'view', $account);

    self::assertInstanceOf(AccessResultNeutral::class, $result);
  }

  public function testNonViewOrUpdateOperationReturnsNeutral(): void {
    $node = $this->mockNode(bundle: 'screen');
    $account = $this->mockAccount(uid: 5);

    self::assertInstanceOf(AccessResultNeutral::class, signage_access_node_access($node, 'delete', $account));
    self::assertInstanceOf(AccessResultNeutral::class, signage_access_node_access($node, 'create', $account));
  }

  public function testAdminPermissionsBypassAndReturnNeutral(): void {
    $node = $this->mockNode(bundle: 'screen');
    $admin = $this->mockAccount(uid: 5, perms: ['administer nodes']);

    self::assertInstanceOf(AccessResultNeutral::class, signage_access_node_access($node, 'view', $admin));

    $screenAdmin = $this->mockAccount(uid: 6, perms: ['edit any screen content']);
    self::assertInstanceOf(AccessResultNeutral::class, signage_access_node_access($node, 'update', $screenAdmin));
  }

  public function testOwnerIsAllowed(): void {
    $node = $this->mockNode(bundle: 'screen', ownerId: 10);
    $owner = $this->mockAccount(uid: 10);

    $result = signage_access_node_access($node, 'view', $owner);

    self::assertInstanceOf(AccessResultAllowed::class, $result);
  }

  public function testUserListedInAccessFieldIsAllowed(): void {
    $node = $this->mockNode(
      bundle: 'screen',
      ownerId: 1,
      hasAccessField: TRUE,
      accessUserIds: [10, 22, 33],
    );
    $account = $this->mockAccount(uid: 22);

    $result = signage_access_node_access($node, 'update', $account);

    self::assertInstanceOf(AccessResultAllowed::class, $result);
  }

  public function testUserNotInAccessFieldIsForbidden(): void {
    $node = $this->mockNode(
      bundle: 'screen',
      ownerId: 1,
      hasAccessField: TRUE,
      accessUserIds: [10, 22],
    );
    $account = $this->mockAccount(uid: 99);

    $result = signage_access_node_access($node, 'view', $account);

    self::assertInstanceOf(AccessResultForbidden::class, $result);
  }

  public function testNoAccessFieldFallsThroughToForbidden(): void {
    $node = $this->mockNode(
      bundle: 'screen',
      ownerId: 1,
      hasAccessField: FALSE,
    );
    $account = $this->mockAccount(uid: 99);

    $result = signage_access_node_access($node, 'view', $account);

    self::assertInstanceOf(AccessResultForbidden::class, $result);
  }

  private function mockNode(
    string $bundle,
    int $ownerId = 1,
    bool $hasAccessField = FALSE,
    array $accessUserIds = [],
  ): NodeInterface&MockObject {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('getOwnerId')->willReturn($ownerId);
    // Cacheable dependency calls — required when the access result mixes the
    // node into its cacheability metadata.
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheTags')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(\Drupal\Core\Cache\Cache::PERMANENT);
    $node->method('hasField')
      ->willReturnCallback(static fn(string $f): bool => $f === 'field_screen_access_users' && $hasAccessField);

    if ($hasAccessField) {
      $field = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
      $field->method('getValue')->willReturn(
        array_map(static fn(int $uid) => ['target_id' => $uid], $accessUserIds)
      );
      $node->method('get')->with('field_screen_access_users')->willReturn($field);
    }

    return $node;
  }

  private function mockAccount(int $uid, array $perms = []): AccountInterface&MockObject {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $p): bool => in_array($p, $perms, TRUE));
    return $account;
  }

}
