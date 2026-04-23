<?php

declare(strict_types=1);

namespace Drupal\signage_share\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_share\Service\ShareManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ShareSlidesForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ShareManager $shareManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('signage_share.manager'),
    );
  }

  public function getFormId(): string {
    return 'signage_share_slides_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'signage_dashboard/dashboard';
    $form['#attributes']['class'][] = 'share-slides-form';
    
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $userStorage = $this->entityTypeManager->getStorage('user');

    $slideIds = $nodeStorage->getQuery()
      ->condition('type', 'slide')
      ->condition('uid', (int) $this->currentUser()->id())
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->execute();

    $slides = $nodeStorage->loadMultiple($slideIds);
    $slides = array_values(array_filter(
      $slides,
      static fn ($slide) => $slide instanceof NodeInterface && $slide->bundle() === 'slide'
    ));

    $uids = $userStorage->getQuery()
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->condition('uid', (int) $this->currentUser()->id(), '!=')
      ->accessCheck(FALSE)
      ->sort('name')
      ->execute();

    $users = $userStorage->loadMultiple($uids);
    $userOptions = [];

    foreach ($users as $user) {
      $userOptions[$user->id()] = $user->getDisplayName();
    }

    $form['slides_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => (string) $this->t('Velg slides som skal deles'),
      '#attributes' => [
        'class' => ['share-slide-picker__heading'],
      ],
    ];

    $form['slide_ids'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['share-slide-picker'],
      ],
    ];

    if (!$slides) {
      $form['slide_ids']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) $this->t('Du har ingen slides å dele.'),
        '#attributes' => [
          'class' => ['share-slide-picker__empty'],
        ],
      ];
    }
    else {
      foreach ($slides as $slide) {
        $slideId = (string) $slide->id();
        $defaultSelected = (bool) ($form_state->getValue(['slide_ids', $slideId, 'selected']) ?? FALSE);

        $form['slide_ids'][$slideId] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['share-slide-picker__item'],
          ],
          'selected' => [
            '#type' => 'checkbox',
            '#title' => '',
            '#return_value' => $slideId,
            '#default_value' => $defaultSelected ? $slideId : 0,
            '#attributes' => [
              'class' => ['share-slide-picker__checkbox'],
            ],
          ],
          'preview' => $this->buildSlidePickerPreview($slide),
        ];
      }
    }

    $form['recipient_uid'] = [
      '#type' => 'select',
      '#title' => $this->t('Mottaker'),
      '#options' => $userOptions,
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Melding'),
      '#rows' => 4,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send melding'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  private function buildSlidePickerPreview(NodeInterface $slide): array {
    $thumb = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['share-slide-picker__thumb', 'is-empty'],
      ],
      'label' => [
        '#markup' => $this->t('No image'),
      ],
    ];

    if (
      $slide->hasField('field_slide_media') &&
      !$slide->get('field_slide_media')->isEmpty() &&
      $slide->get('field_slide_media')->entity &&
      $slide->get('field_slide_media')->entity->hasField('field_media_image') &&
      !$slide->get('field_slide_media')->entity->get('field_media_image')->isEmpty() &&
      $slide->get('field_slide_media')->entity->get('field_media_image')->entity
    ) {
      $file = $slide->get('field_slide_media')->entity->get('field_media_image')->entity;

      $thumb = [
        '#theme' => 'image',
        '#uri' => $file->getFileUri(),
        '#alt' => $slide->label(),
        '#attributes' => [
          'class' => ['share-slide-picker__image'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['share-slide-picker__preview'],
      ],
      'thumb' => $thumb,
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $slide->label(),
        '#attributes' => [
          'class' => ['share-slide-picker__title'],
        ],
      ],
    ];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selectedSlideIds = [];

    foreach (($form_state->getValue('slide_ids') ?? []) as $slideId => $values) {
      if (!empty($values['selected'])) {
        $selectedSlideIds[] = (int) $slideId;
      }
    }

    if (!$selectedSlideIds) {
      $form_state->setErrorByName('slide_ids', $this->t('Du må velge minst én slide.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $slideIds = [];

    foreach (($form_state->getValue('slide_ids') ?? []) as $slideId => $values) {
      if (!empty($values['selected'])) {
        $slideIds[] = (int) $slideId;
      }
    }

    $this->shareManager->createMessage(
      $slideIds,
      (int) $form_state->getValue('recipient_uid'),
      (string) $form_state->getValue('message')
    );

    $this->messenger()->addStatus($this->t('Meldingen ble sendt.'));
    $form_state->setRedirect('signage_dashboard.page');
  }

}