<?php

declare(strict_types=1);

namespace Drupal\Tests\signage_share\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_share\Form\ShareSlidesForm;
use Drupal\signage_share\Service\ShareManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ShareSlidesForm::class)]
#[Group('signage_share')]
final class ShareSlidesFormTest extends UnitTestCase {

  private EntityTypeManagerInterface&MockObject $entityTypeManager;
  private EntityStorageInterface&MockObject $nodeStorage;
  private EntityStorageInterface&MockObject $userStorage;
  private ShareManager&MockObject $shareManager;
  private MessengerInterface&MockObject $messenger;
  private AccountProxyInterface&MockObject $currentUser;
  private ShareSlidesForm $form;

  protected function setUp(): void {
    parent::setUp();

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $type) => match ($type) {
        'node' => $this->nodeStorage,
        'user' => $this->userStorage,
      },
    );

    $this->shareManager = $this->createMock(ShareManager::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('id')->willReturn(5);

    // FormBase::currentUser() reads from \Drupal::currentUser().
    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    \Drupal::setContainer($container);

    $this->form = new ShareSlidesForm($this->entityTypeManager, $this->shareManager);
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->messenger);
  }

  public function testGetFormIdReturnsExpectedString(): void {
    self::assertSame('signage_share_slides_form', $this->form->getFormId());
  }

  public function testValidateFormErrorsWhenNoSlideSelected(): void {
    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('slide_ids')->willReturn([
      '10' => ['selected' => 0],
      '11' => ['selected' => 0],
    ]);

    $formState
      ->expects(self::once())
      ->method('setErrorByName')
      ->with('slide_ids', self::isInstanceOf(\Stringable::class));

    $this->form->validateForm($formArray, $formState);
  }

  public function testValidateFormErrorsWhenSlideIdsValueMissing(): void {
    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('slide_ids')->willReturn(NULL);

    $formState
      ->expects(self::once())
      ->method('setErrorByName')
      ->with('slide_ids', self::anything());

    $this->form->validateForm($formArray, $formState);
  }

  public function testValidateFormPassesWhenAtLeastOneSlideSelected(): void {
    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('slide_ids')->willReturn([
      '10' => ['selected' => 0],
      '11' => ['selected' => '11'],
    ]);

    $formState->expects(self::never())->method('setErrorByName');

    $this->form->validateForm($formArray, $formState);
  }

  public function testSubmitFormSendsSelectedSlidesAndRedirects(): void {
    $formArray = [];
    $values = [
      'slide_ids' => [
        '10' => ['selected' => '10'],
        '11' => ['selected' => 0],
        '12' => ['selected' => '12'],
      ],
      'recipient_uid' => '42',
      'message' => 'Hi there',
    ];

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturnCallback(
      static fn(string $name) => $values[$name] ?? NULL,
    );

    $this->shareManager
      ->expects(self::once())
      ->method('createMessage')
      ->with([10, 12], 42, 'Hi there');

    $this->messenger->expects(self::once())->method('addStatus');

    $formState
      ->expects(self::once())
      ->method('setRedirect')
      ->with('signage_dashboard.page');

    $this->form->submitForm($formArray, $formState);
  }

  public function testSubmitFormHandlesMissingMessageAndRecipient(): void {
    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturnCallback(
      static fn(string $name) => match ($name) {
        'slide_ids' => ['10' => ['selected' => '10']],
        default => NULL,
      },
    );

    $this->shareManager
      ->expects(self::once())
      ->method('createMessage')
      ->with([10], 0, '');

    $this->messenger->expects(self::once())->method('addStatus');
    $formState->expects(self::once())->method('setRedirect');

    $this->form->submitForm($formArray, $formState);
  }

  public function testBuildFormShowsEmptyMessageWhenUserHasNoSlides(): void {
    // url_generator is touched by some library / theme system calls during
    // form building. Stub it out defensively.
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $container = \Drupal::getContainer();
    $container->set('url_generator', $urlGenerator);

    $nodeQuery = $this->mockEntityQuery(returns: []);
    $userQuery = $this->mockEntityQuery(returns: []);

    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);
    $this->nodeStorage->method('loadMultiple')->willReturn([]);

    $this->userStorage->method('getQuery')->willReturn($userQuery);
    $this->userStorage->method('loadMultiple')->willReturn([]);

    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturn(NULL);

    $form = $this->form->buildForm($formArray, $formState);

    self::assertArrayHasKey('empty', $form['slide_ids']);
    self::assertArrayHasKey('recipient_uid', $form);
    self::assertSame('select', $form['recipient_uid']['#type']);
    self::assertSame([], $form['recipient_uid']['#options']);
  }

  public function testBuildFormFiltersToSlideBundleOnly(): void {
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    \Drupal::getContainer()->set('url_generator', $urlGenerator);

    $slide = $this->createMock(NodeInterface::class);
    $slide->method('id')->willReturn(10);
    $slide->method('bundle')->willReturn('slide');
    $slide->method('label')->willReturn('Slide 10');
    $slide->method('hasField')->willReturn(FALSE);

    $notASlide = $this->createMock(NodeInterface::class);
    $notASlide->method('bundle')->willReturn('article');

    $nodeQuery = $this->mockEntityQuery(returns: [10, 99]);
    $this->nodeStorage->method('getQuery')->willReturn($nodeQuery);
    $this->nodeStorage->method('loadMultiple')->willReturn([
      10 => $slide,
      99 => $notASlide,
    ]);

    $userQuery = $this->mockEntityQuery(returns: []);
    $this->userStorage->method('getQuery')->willReturn($userQuery);
    $this->userStorage->method('loadMultiple')->willReturn([]);

    $formArray = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturn(NULL);

    $form = $this->form->buildForm($formArray, $formState);

    self::assertArrayHasKey('10', $form['slide_ids']);
    self::assertArrayNotHasKey('99', $form['slide_ids']);
    self::assertArrayNotHasKey('empty', $form['slide_ids']);
  }

  /**
   * Build a chainable entity-query mock that returns the given ids on execute.
   */
  private function mockEntityQuery(array $returns): QueryInterface&MockObject {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn($returns);
    return $query;
  }

}
