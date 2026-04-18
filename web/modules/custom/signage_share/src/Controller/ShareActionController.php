<?php

declare(strict_types=1);

namespace Drupal\signage_share\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\signage_share\Service\ShareManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class ShareActionController extends ControllerBase {

  public function __construct(
    private readonly ShareManager $shareManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('signage_share.manager'),
    );
  }

  public function copy(int $share): RedirectResponse {
    $newSlideId = $this->shareManager->copySharedSlide($share);

    if ($newSlideId) {
      $this->messenger()->addStatus($this->t('Slide kopiert.'));
      return $this->redirect('entity.node.edit_form', ['node' => $newSlideId]);
    }

    $this->messenger()->addError($this->t('Kunne ikke kopiere slide.'));
    return $this->redirect('signage_dashboard.page');
  }

  public function archive(int $share): RedirectResponse {
    $this->shareManager->archive($share);
    $this->messenger()->addStatus($this->t('Melding arkivert.'));
    return $this->redirect('signage_dashboard.page');
  }
}