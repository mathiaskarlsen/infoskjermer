<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_share\Unit\Controller;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\signage_share\Controller\ShareActionController;
use Drupal\signage_share\Service\ShareManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[CoversClass(ShareActionController::class)]
#[Group('signage_share')]
final class ShareActionControllerTest extends UnitTestCase {

  private ShareManager&MockObject $shareManager;
  private MessengerInterface&MockObject $messenger;
  private ShareActionController $controller;

  protected function setUp(): void {
    parent::setUp();

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator
      ->method('generateFromRoute')
      ->willReturnCallback(static fn(string $name): string => '/' . $name);

    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $this->shareManager = $this->createMock(ShareManager::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->controller = new ShareActionController($this->shareManager);
    $this->controller->setStringTranslation($this->getStringTranslationStub());
    $this->controller->setMessenger($this->messenger);
  }

  public function testCopyReportsCountAndRedirectsToDashboardOnSuccess(): void {
    $this->shareManager
      ->expects(self::once())
      ->method('copyMessageSlides')
      ->with(42)
      ->willReturn([101, 102, 103]);

    $this->messenger
      ->expects(self::once())
      ->method('addStatus')
      ->with(self::callback(function ($message): bool {
        // ControllerBase::t() returns a TranslatableMarkup — render to string.
        $string = (string) $message;
        self::assertStringContainsString('3', $string);
        return TRUE;
      }));
    $this->messenger->expects(self::never())->method('addError');

    $response = $this->controller->copy(42);

    self::assertInstanceOf(RedirectResponse::class, $response);
    self::assertStringContainsString('signage_dashboard.page', $response->getTargetUrl());
  }

  public function testCopyShowsErrorAndStillRedirectsWhenNoSlidesCopied(): void {
    $this->shareManager->method('copyMessageSlides')->with(42)->willReturn([]);

    $this->messenger->expects(self::never())->method('addStatus');
    $this->messenger->expects(self::once())->method('addError');

    $response = $this->controller->copy(42);

    self::assertInstanceOf(RedirectResponse::class, $response);
    self::assertStringContainsString('signage_dashboard.page', $response->getTargetUrl());
  }

  public function testArchiveDelegatesAndRedirectsToDashboard(): void {
    $this->shareManager->expects(self::once())->method('archive')->with(42);

    $this->messenger->expects(self::once())->method('addStatus');
    $this->messenger->expects(self::never())->method('addError');

    $response = $this->controller->archive(42);

    self::assertInstanceOf(RedirectResponse::class, $response);
    self::assertStringContainsString('signage_dashboard.page', $response->getTargetUrl());
  }

}
