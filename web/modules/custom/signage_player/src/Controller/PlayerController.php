<?php

namespace Drupal\signage_player\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\signage_player\Service\ScreenPlaybackResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerController extends ControllerBase {

  public function __construct(
    protected ScreenPlaybackResolver $resolver,
    protected KillSwitch $killSwitch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('signage_player.screen_playback_resolver'),
      $container->get('page_cache_kill_switch'),
    );
  }

  public function view(int $screen): array {
    $this->killSwitch->trigger();

    $result = $this->resolver->resolve($screen);

    if (!$result['status']['screen_found']) {
      throw new NotFoundHttpException();
    }

    return [
      '#theme' => 'signage_player_screen',
      '#playback' => $result,
      '#cache' => [
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => [
          'signage_player/player',
        ],
      ],
    ];
  }

}