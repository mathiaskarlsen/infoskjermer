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

  public function copy(int $message): RedirectResponse {
    $newSlideIds = $this->shareManager->copyMessageSlides($message);

    if ($newSlideIds) {
      $this->messenger()->addStatus($this->t('@count slides kopiert til dine slides.', [
        '@count' => count($newSlideIds),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Kunne ikke kopiere slides.'));
    }

    return $this->redirect('signage_dashboard.page');
  }

  public function archive(int $message): RedirectResponse {
  $this->shareManager->archive($message);
  $this->messenger()->addStatus($this->t('Meldingen ble fjernet fra dashboardet.'));
  return $this->redirect('signage_dashboard.page');
}

}