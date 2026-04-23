<?php

declare(strict_types=1);

namespace Drupal\signage_dashboard\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\signage_player\Service\ScreenPlaybackResolver;
use Drupal\signage_share\Service\ShareManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly ScreenPlaybackResolver $screenPlaybackResolver,
    private readonly ShareManager $shareManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('signage_player.screen_playback_resolver'),
      $container->get('signage_share.manager'),
    );
  }

  public function build(): array {
    $account = $this->currentUser();
    $screens = $this->loadMyScreens();
    $messages = $this->shareManager->loadReceivedMessages((int) $account->id(), ['new', 'seen', 'copied']) ?? [];
    if (!is_array($messages)) {
      $messages = [];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['signage-dashboard'],
      ],
      '#attached' => [
        'library' => ['signage_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'paragraph_list'],
      ],

      'intro' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['signage-dashboard__intro'],
        ],
        'welcome' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Velkommen, @name', [
            '@name' => $account->getDisplayName(),
          ]),
        ],
        'lead' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => (string) $this->t('Her ser du skjermene dine og innhold som er aktivt akkurat nå.'),
          '#attributes' => [
            'class' => ['signage-dashboard__lead'],
          ],
        ],
      ],

      'shared_messages_section' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-panel'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => (string) $this->t('Nye fellesmeldinger (@count)', [
            '@count' => count($messages),
          ]),
        ],
        'content' => $this->buildSharedMessagesSection($messages),
      ],

      'screens_section' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-panel'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => (string) $this->t('Dine skjermer (@count)', [
            '@count' => count($screens),
          ]),
        ],
        'content' => $this->buildMyScreensSection($screens),
      ],
    ];
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadMyScreens(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'screen')
      ->condition('uid', (int) $this->currentUser()->id())
      ->sort('created', 'DESC')
      ->execute();

    if (!$nids) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);

    return array_values(array_filter(
      $nodes,
      static fn ($node) => $node instanceof NodeInterface
    ));
  }

  private function collectActiveMediaForScreen(NodeInterface $screen): array {
    $items = [];
    $seen = [];

    $resolved = $this->screenPlaybackResolver->resolve((int) $screen->id());

    foreach ($resolved['items'] as $item) {
      $key = $screen->id() . ':' . $item['slide_id'];

      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;

      $items[] = [
        'screen_id' => (int) $screen->id(),
        'screen_title' => $screen->label(),
        'slide_id' => (int) $item['slide_id'],
        'slide_title' => (string) $item['title'],
        'body' => !empty($item['body']) ? Unicode::truncate((string) $item['body'], 140, TRUE, TRUE) : '',
        'duration' => (int) $item['duration'],
        'order' => (int) ($item['order'] ?? 9999),
        'media_url' => $item['media_url'] ?? NULL,
        'start_at' => $item['start_at'] ?? NULL,
        'end_at' => $item['end_at'] ?? NULL,
        'period_label' => $this->formatPeriod(
          $item['start_at'] ?? NULL,
          $item['end_at'] ?? NULL
        ),
      ];
    }

    usort($items, function (array $a, array $b): int {
      $order_compare = $a['order'] <=> $b['order'];
      if ($order_compare !== 0) {
        return $order_compare;
      }

      return strcasecmp($a['slide_title'], $b['slide_title']);
    });

    return $items;
  }

  private function buildMyScreensSection(array $screens): array {
    if (!$screens) {
      return $this->buildEmptyMessage($this->t('Du har ingen skjermer ennå.'));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-screen-list'],
      ],
    ];

    foreach ($screens as $index => $screen) {
      $location = '-';
      if (
        $screen->hasField('field_screen_location') &&
        !$screen->get('field_screen_location')->isEmpty() &&
        $screen->get('field_screen_location')->entity
      ) {
        $location = $screen->get('field_screen_location')->entity->label();
      }

      $playlist_entity = NULL;
      if (
        $screen->hasField('field_screen_playlist') &&
        !$screen->get('field_screen_playlist')->isEmpty() &&
        $screen->get('field_screen_playlist')->entity
      ) {
        $playlist_entity = $screen->get('field_screen_playlist')->entity;
      }

      $active_media = $this->collectActiveMediaForScreen($screen);

      $build['item_' . $index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-screen-item'],
        ],

        'header' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-screen-item__header'],
          ],

          'main' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-screen-item__main'],
            ],
            'title' => [
              '#type' => 'link',
              '#title' => $screen->label(),
              '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $screen->id()]),
              '#options' => [
                'attributes' => [
                  'class' => ['dashboard-screen-item__title'],
                ],
              ],
            ],
            'meta' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['dashboard-screen-meta'],
              ],
              'location' => $this->buildMetaItem($this->t('Location:'), $location),
              'playlist' => [
                '#type' => 'container',
                '#attributes' => [
                  'class' => ['dashboard-meta-item'],
                ],
                'label' => [
                  '#type' => 'html_tag',
                  '#tag' => 'span',
                  '#value' => (string) $this->t('Playlist:'),
                  '#attributes' => [
                    'class' => ['dashboard-meta-label'],
                  ],
                ],
                'value' => $playlist_entity
                  ? [
                      '#type' => 'link',
                      '#title' => $playlist_entity->label(),
                      '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $playlist_entity->id()]),
                      '#options' => [
                        'attributes' => [
                          'class' => ['dashboard-meta-value', 'dashboard-meta-link'],
                        ],
                      ],
                    ]
                  : [
                      '#type' => 'html_tag',
                      '#tag' => 'span',
                      '#value' => '-',
                      '#attributes' => [
                        'class' => ['dashboard-meta-value'],
                      ],
                    ],
              ],
            ],
          ],

          'side' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-screen-item__side'],
            ],
            'status' => $this->buildStatusBadge($screen->isPublished()),
            'player' => [
              '#type' => 'link',
              '#title' => $this->t('Open player'),
              '#url' => Url::fromUri('internal:/player/' . $screen->id()),
              '#options' => [
                'attributes' => [
                  'class' => ['dashboard-action-link'],
                ],
              ],
            ],
          ],
        ],

        'media' => [
          '#type' => 'details',
          '#title' => $this->t('Aktive medier (@count)', [
            '@count' => count($active_media),
          ]),
          '#open' => FALSE,
          '#attributes' => [
            'class' => ['dashboard-screen-media', 'dashboard-screen-media--collapsible'],
          ],
          'content' => $this->buildScreenMediaSection($active_media),
        ],
      ];
    }

    return $build;
  }

  private function buildScreenMediaSection(array $items): array {
    if (!$items) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) $this->t('Ingen aktive medier akkurat nå.'),
        '#attributes' => [
          'class' => ['dashboard-empty', 'dashboard-empty--compact'],
        ],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-media-grid', 'dashboard-media-grid--nested'],
      ],
    ];

    foreach ($items as $index => $item) {
      $build['item_' . $index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-media-card'],
        ],
        'thumb' => $this->buildMediaThumb($item),
        'content' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-media-card__content'],
          ],
          'title' => [
            '#type' => 'link',
            '#title' => $item['slide_title'],
            '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $item['slide_id']]),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-media-card__title'],
              ],
            ],
          ],
          'body' => $item['body'] !== '' ? [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $item['body'],
            '#attributes' => [
              'class' => ['dashboard-media-card__body'],
            ],
          ] : [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => (string) $this->t('Ingen beskrivelse.'),
            '#attributes' => [
              'class' => ['dashboard-media-card__body', 'is-muted'],
            ],
          ],
          'meta' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-media-card__meta'],
            ],
            'duration' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => (string) $this->t('@seconds s', ['@seconds' => $item['duration']]),
              '#attributes' => [
                'class' => ['dashboard-pill'],
              ],
            ],
            'period' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $item['period_label'],
              '#attributes' => [
                'class' => ['dashboard-pill', 'dashboard-pill--secondary'],
                'title' => $item['period_label'],
              ],
            ],
          ],
        ],
      ];
    }

    return $build;
  }

  private function buildSharedMessagesSection(array $messages): array {
    if (!$messages) {
      return $this->buildEmptyMessage($this->t('Ingen nye fellesmeldinger.'));
    }
  
    $userStorage = $this->entityTypeManager()->getStorage('user');
  
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-share-list'],
      ],
    ];
  
    foreach ($messages as $index => $message) {
      $sender = $userStorage->load((int) $message->sender_uid);
      $senderName = $sender ? $sender->getDisplayName() : (string) $this->t('Ukjent bruker');
      $slides = $this->shareManager->loadMessageSlides((int) $message->id);
        
      $slideBuild = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-share-card__slides'],
        ],
      ];
  
      foreach ($slides as $delta => $slide) {
        if (!$slide instanceof NodeInterface || $slide->bundle() !== 'slide') {
          continue;
        }
  
        $slideBuild['item_' . $delta] = $this->buildSharedSlidePreview($slide);
      }
  
      $createdLabel = \Drupal::service('date.formatter')->format((int) $message->created, 'custom', 'd.m H:i');
      $slideCount = is_array($slides) ? count($slides) : 0;
  
      $build['item_' . $index] = [
        '#type' => 'details',
        '#open' => FALSE,
        '#attributes' => [
          'class' => ['dashboard-share-card', 'dashboard-share-card--collapsible'],
        ],
        '#title' => $senderName . ' — ' . $createdLabel . ' — ' . $slideCount . ' ' . $this->t('slides'),
      
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $message->message !== '' ? $message->message : (string) $this->t('Ingen beskjed.'),
        '#attributes' => [
          'class' => ['dashboard-share-card__message'],
        ],
      ],
      
      'slides' => $slideBuild,
      
      'actions' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-share-card__actions'],
          ],
          'copy' => [
            '#type' => 'link',
            '#title' => $this->t('Kopier alle til mine slides'),
            '#url' => Url::fromRoute('signage_share.copy', ['message' => $message->id]),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-action-link'],
              ],
            ],
          ],
          'remove' => [
            '#type' => 'link',
            '#title' => $this->t('Fjern melding'),
            '#url' => Url::fromRoute('signage_share.archive', ['message' => $message->id]),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-action-link', 'dashboard-action-link--danger', 'js-confirm-remove-message'],
                'data-confirm-message' => (string) $this->t('Er du sikker på at du vil fjerne meldingen fra dashboardet?'),
              ],
            ],
          ],
        ],
      ];
    }
  
    return $build;
  }

  private function buildSharedSlidePreview(NodeInterface $slide): array {
    $body = '';
  
    if ($slide->hasField('field_slide_body') && !$slide->get('field_slide_body')->isEmpty()) {
      $body = Unicode::truncate(
        trim(html_entity_decode(strip_tags((string) $slide->get('field_slide_body')->value))),
        60,
        TRUE,
        TRUE
      );
    }
  
    $thumb = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-share-slide__thumb', 'is-empty'],
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
          'class' => ['dashboard-share-slide__image'],
        ],
      ];
    }
  
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-share-slide'],
      ],
      'thumb' => $thumb,
      'content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-share-slide__content'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $slide->label(),
        ],
      ],
    ];
  
    if ($body !== '') {
      $build['content']['body'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $body,
      ];
    }
  
    return $build;
  }

  private function buildMediaThumb(array $item): array {
    if (!empty($item['media_url'])) {
      return [
        '#type' => 'inline_template',
        '#template' => '
          <a class="dashboard-media-card__thumb-link" href="{{ url }}" target="_blank" rel="noopener">
            <div class="dashboard-media-card__thumb">
              <img src="{{ src }}" alt="{{ alt }}" loading="lazy" />
            </div>
          </a>
        ',
        '#context' => [
          'url' => $item['media_url'],
          'src' => $item['media_url'],
          'alt' => $item['slide_title'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-media-card__thumb', 'is-empty'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) $this->t('No image'),
      ],
    ];
  }

  private function buildMetaItem($label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-meta-item'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) $label,
        '#attributes' => [
          'class' => ['dashboard-meta-label'],
        ],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => [
          'class' => ['dashboard-meta-value'],
        ],
      ],
    ];
  }

  private function buildStatusBadge(bool $published): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $published ? (string) $this->t('Active') : (string) $this->t('Inactive'),
      '#attributes' => [
        'class' => [
          'dashboard-badge',
          $published ? 'is-active' : 'is-inactive',
        ],
      ],
    ];
  }

  private function buildEmptyMessage($message): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => (string) $message,
      '#attributes' => [
        'class' => ['dashboard-empty'],
      ],
    ];
  }

  private function formatPeriod(?string $startAt, ?string $endAt): string {
    if (!$startAt && !$endAt) {
      return (string) $this->t('Alltid aktiv');
    }

    if ($startAt && !$endAt) {
      return (string) $this->t('Fra @start', [
        '@start' => $this->formatDrupalDate($startAt),
      ]);
    }

    if (!$startAt && $endAt) {
      return (string) $this->t('Til @end', [
        '@end' => $this->formatDrupalDate($endAt),
      ]);
    }

    return $this->formatDrupalDate($startAt) . '–' . $this->formatDrupalDate($endAt);
  }

  private function formatDrupalDate(string $value): string {
    $timestamp = strtotime($value);
    if ($timestamp === FALSE) {
      return $value;
    }

    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'd.m H:i');
  }

}