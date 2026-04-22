<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_dashboard\Unit\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\signage_dashboard\Controller\DashboardController;
use Drupal\signage_player\Service\ScreenPlaybackResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(DashboardController::class)]
#[Group('signage_dashboard')]
final class DashboardControllerTest extends UnitTestCase {

  private DashboardController $controller;
  private DateFormatterInterface&MockObject $dateFormatter;

  protected function setUp(): void {
    parent::setUp();

    $this->dateFormatter = $this->createMock(DateFormatterInterface::class);
    $this->dateFormatter
      ->method('format')
      ->willReturnCallback(
        static fn(int $timestamp, string $type, string $format): string => date($format, $timestamp)
      );

    $container = new ContainerBuilder();
    $container->set('date.formatter', $this->dateFormatter);
    \Drupal::setContainer($container);

    $resolver = $this->createMock(ScreenPlaybackResolver::class);
    $this->controller = new DashboardController($resolver);
    $this->controller->setStringTranslation($this->getStringTranslationStub());
  }

  public function testFormatPeriodWithBothNullValuesUsesAlwaysActiveLabel(): void {
    self::assertSame('Alltid aktiv', $this->invokePrivate('formatPeriod', [NULL, NULL]));
  }

  public function testFormatPeriodWithOnlyStartUsesFromLabel(): void {
    $result = $this->invokePrivate('formatPeriod', ['2024-05-01T08:00:00', NULL]);
    self::assertSame('Fra 01.05 08:00', $result);
  }

  public function testFormatPeriodWithOnlyEndUsesUntilLabel(): void {
    $result = $this->invokePrivate('formatPeriod', [NULL, '2024-05-31T23:00:00']);
    self::assertSame('Til 31.05 23:00', $result);
  }

  public function testFormatPeriodWithBothBoundariesJoinsWithDash(): void {
    $result = $this->invokePrivate('formatPeriod', ['2024-05-01T08:00:00', '2024-05-31T23:00:00']);
    self::assertSame('01.05 08:00–31.05 23:00', $result);
  }

  public function testFormatDrupalDateReturnsRawValueForUnparseableInput(): void {
    self::assertSame('not-a-date', $this->invokePrivate('formatDrupalDate', ['not-a-date']));
  }

  public function testFormatDrupalDateFormatsValidIsoString(): void {
    self::assertSame('15.07 14:30', $this->invokePrivate('formatDrupalDate', ['2024-07-15T14:30:00']));
  }

  /**
   * Invoke a private method on the controller by name.
   */
  private function invokePrivate(string $method, array $args): mixed {
    $ref = new \ReflectionMethod($this->controller, $method);
    return $ref->invokeArgs($this->controller, $args);
  }

}
