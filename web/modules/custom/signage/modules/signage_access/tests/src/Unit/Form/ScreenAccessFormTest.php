<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_access\Unit\Form;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_access\Form\ScreenAccessForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ScreenAccessForm::class)]
#[Group('signage_access')]
final class ScreenAccessFormTest extends UnitTestCase {

  private EntityTypeManagerInterface&MockObject $entityTypeManager;
  private EntityStorageInterface&MockObject $nodeStorage;
  private MessengerInterface&MockObject $messenger;
  private ScreenAccessForm $form;

  protected function setUp(): void {
    parent::setUp();

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->willReturnCallback(fn(string $type) => match ($type) {
        'node' => $this->nodeStorage,
        default => $this->createMock(EntityStorageInterface::class),
      });

    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->form = new ScreenAccessForm($this->entityTypeManager);
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->messenger);
  }

  public function testGetFormIdReturnsExpectedString(): void {
    self::assertSame('signage_access_screen_access_form', $this->form->getFormId());
  }

  public function testSubmitErrorsWhenScreenIsMissing(): void {
    $formArray = [];
    $formState = $this->mockFormState(values: ['screen_id' => 999]);

    $this->nodeStorage->method('load')->with(999)->willReturn(NULL);
    $this->messenger->expects(self::once())->method('addError');
    $this->messenger->expects(self::never())->method('addStatus');

    $this->form->submitForm($formArray, $formState);
  }

  public function testSubmitErrorsWhenSelectedNodeIsNotAScreen(): void {
    $formArray = [];
    $formState = $this->mockFormState(values: ['screen_id' => 1]);

    $page = $this->createMock(NodeInterface::class);
    $page->method('bundle')->willReturn('article');

    $this->nodeStorage->method('load')->with(1)->willReturn($page);
    $this->messenger->expects(self::once())->method('addError');
    $this->messenger->expects(self::never())->method('addStatus');

    $this->form->submitForm($formArray, $formState);
  }

  public function testSubmitErrorsWhenAccessFieldIsMissing(): void {
    $formArray = [];
    $formState = $this->mockFormState(values: ['screen_id' => 1]);

    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('screen');
    $screen->method('hasField')->with('field_screen_access_users')->willReturn(FALSE);
    $screen->expects(self::never())->method('save');

    $this->nodeStorage->method('load')->with(1)->willReturn($screen);
    $this->messenger->expects(self::once())->method('addError');
    $this->messenger->expects(self::never())->method('addStatus');

    $this->form->submitForm($formArray, $formState);
  }

  public function testSubmitSavesCheckedUsersAndRebuildsForm(): void {
    $formArray = [];
    // Drupal checkbox values: keys are user ids, value 0 for unchecked, uid for
    // checked (but array_filter drops the 0s either way).
    $formState = $this->mockFormState(values: [
      'screen_id' => 1,
      'allowed_users' => [
        2 => 2,
        5 => 0,
        7 => 7,
      ],
    ]);

    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('screen');
    $screen->method('label')->willReturn('Lobby');
    $screen->method('hasField')->with('field_screen_access_users')->willReturn(TRUE);
    $screen
      ->expects(self::once())
      ->method('set')
      ->with('field_screen_access_users', [
        ['target_id' => 2],
        ['target_id' => 7],
      ]);
    $screen->expects(self::once())->method('save');

    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $this->messenger->expects(self::never())->method('addError');
    $this->messenger->expects(self::once())->method('addStatus');

    $formState->expects(self::once())->method('setRebuild')->with(TRUE);

    $this->form->submitForm($formArray, $formState);
  }

  public function testSubmitHandlesEmptyAllowedUsersValue(): void {
    $formArray = [];
    $formState = $this->mockFormState(values: ['screen_id' => 1]);

    $screen = $this->createMock(NodeInterface::class);
    $screen->method('bundle')->willReturn('screen');
    $screen->method('label')->willReturn('Lobby');
    $screen->method('hasField')->with('field_screen_access_users')->willReturn(TRUE);
    $screen
      ->expects(self::once())
      ->method('set')
      ->with('field_screen_access_users', []);
    $screen->expects(self::once())->method('save');

    $this->nodeStorage->method('load')->with(1)->willReturn($screen);

    $this->messenger->expects(self::once())->method('addStatus');

    $this->form->submitForm($formArray, $formState);
  }

  /**
   * Build a FormStateInterface mock backed by a values map.
   */
  private function mockFormState(array $values): FormStateInterface&MockObject {
    $state = $this->createMock(FormStateInterface::class);
    $state->method('getValue')->willReturnCallback(
      static fn(string $name) => $values[$name] ?? NULL
    );
    return $state;
  }

}
