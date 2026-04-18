<?php

declare(strict_types=1);

namespace Drupal\signage_share\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\signage_share\Service\ShareManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ShareSlideForm extends FormBase {

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
    return 'signage_share_slide_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'slide') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $uids = $userStorage->getQuery()
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->condition('uid', $this->currentUser()->id(), '!=')
      ->accessCheck(FALSE)
      ->sort('name')
      ->execute();

    $users = $userStorage->loadMultiple($uids);
    $options = [];
    foreach ($users as $user) {
      $options[$user->id()] = $user->getDisplayName();
    }

    $form['slide_id'] = [
      '#type' => 'value',
      '#value' => (int) $node->id(),
    ];

    $form['info'] = [
      '#markup' => '<p>Del slide: <strong>' . $node->label() . '</strong></p>',
    ];

    $form['recipient_uid'] = [
      '#type' => 'select',
      '#title' => $this->t('Mottaker'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Kort beskjed'),
      '#rows' => 3,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Del slide'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->shareManager->createShare(
      (int) $form_state->getValue('slide_id'),
      (int) $form_state->getValue('recipient_uid'),
      (string) $form_state->getValue('message')
    );

    $this->messenger()->addStatus($this->t('Slide delt.'));
  }
}