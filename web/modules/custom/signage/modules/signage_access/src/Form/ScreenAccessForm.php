<?php

namespace Drupal\signage_access\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for managing which users can access a given screen.
 *
 * URL: /admin/signage/access
 * URL: /admin/signage/access/{node}  (pre-selects a screen)
 */
class ScreenAccessForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'signage_access_screen_access_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL): array {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // ----------------------------------------------------------------
    // Step 1 — Screen selector
    // ----------------------------------------------------------------
    $screens = $node_storage->loadByProperties(['type' => 'screen', 'status' => 1]);
    $screen_options = [];
    foreach ($screens as $screen) {
      $screen_options[$screen->id()] = $screen->label();
    }
    asort($screen_options);

    // Determine which screen is currently selected (from URL param or form state).
    $selected_nid = $node?->id()
      ?? $form_state->getValue('screen_id')
      ?? NULL;

    $form['screen_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Screen'),
      '#options' => ['' => $this->t('— Select a screen —')] + $screen_options,
      '#default_value' => $selected_nid,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateUsersField',
        'wrapper' => 'screen-users-wrapper',
        'event' => 'change',
      ],
    ];

    // ----------------------------------------------------------------
    // Step 2 — User list (rebuilt when screen changes via AJAX)
    // ----------------------------------------------------------------
    $form['users_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'screen-users-wrapper'],
    ];

    // Only render the user fields once a screen is chosen.
    $selected_nid = $selected_nid ?? $form_state->getValue('screen_id');

    if ($selected_nid && isset($screen_options[$selected_nid])) {
      $screen_node = $node_storage->load($selected_nid);

      // Collect current allowed user IDs from the field.
      $current_user_ids = [];
      if ($screen_node->hasField('field_screen_access_users')) {
        foreach ($screen_node->get('field_screen_access_users')->getValue() as $ref) {
          $current_user_ids[] = (int) $ref['target_id'];
        }
      }

      // Load the owner so we can display them (read-only).
      $owner = $screen_node->getOwner();

      $form['users_wrapper']['owner_info'] = [
        '#type' => 'item',
        '#title' => $this->t('Screen owner'),
        '#markup' => $this->t(
          '<strong>@name</strong> (@mail) — select this user below if they should see the screen in the dashboard.',
          [
            '@name' => $owner->getDisplayName(),
            '@mail' => $owner->getEmail() ?: 'no email',
          ]
        ),
      ];

      // Load all active users for the checkboxes.
      $user_storage = $this->entityTypeManager->getStorage('user');
      $uids = $user_storage->getQuery()
        ->condition('status', 1)
        ->condition('uid', 0, '>')        // exclude anonymous
        ->accessCheck(FALSE)
        ->sort('name')
        ->execute();

      $users = $user_storage->loadMultiple($uids);
      $user_options = [];
      foreach ($users as $user) {
        $user_options[$user->id()] = $this->t('@name (@mail)', [
          '@name' => $user->getDisplayName(),
          '@mail' => $user->getEmail() ?: 'no email',
        ]);
      }

      if (empty($user_options)) {
        $form['users_wrapper']['no_users'] = [
          '#markup' => $this->t('<p>No users found.</p>'),
        ];
      }
      else {
        $form['users_wrapper']['allowed_users'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Users with access'),
          '#description' => $this->t('Check users who should be able to view and edit this screen.'),
          '#options' => $user_options,
          '#default_value' => $current_user_ids,
        ];

        $form['users_wrapper']['actions'] = [
          '#type' => 'actions',
        ];

        $form['users_wrapper']['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save access'),
          '#button_type' => 'primary',
        ];

        // Quick-link to edit the screen node itself.
        $form['users_wrapper']['actions']['edit_screen'] = [
          '#type' => 'link',
          '#title' => $this->t('Edit screen content'),
          '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $selected_nid]),
          '#attributes' => ['class' => ['button']],
        ];
      }
    }
    else {
      $form['users_wrapper']['placeholder'] = [
        '#markup' => $this->t('<p>Select a screen above to manage its access.</p>'),
      ];
    }

    return $form;
  }

  /**
   * AJAX callback — re-renders the users section when the screen changes.
   */
  public function updateUsersField(array &$form, FormStateInterface $form_state): array {
    return $form['users_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = $form_state->getValue('screen_id');
    $node_storage = $this->entityTypeManager->getStorage('node');
    $screen_node = $node_storage->load($nid);

    if (!$screen_node || $screen_node->bundle() !== 'screen') {
      $this->messenger()->addError($this->t('Invalid screen selected.'));
      return;
    }

    if (!$screen_node->hasField('field_screen_access_users')) {
      $this->messenger()->addError(
        $this->t('The screen content type is missing the field_screen_access_users field. Please add it first.')
      );
      return;
    }

    // Build the new list of user references from the checkboxes.
    $checked = array_filter($form_state->getValue('allowed_users') ?? []);
    $new_refs = [];
    foreach (array_keys($checked) as $uid) {
      $new_refs[] = ['target_id' => $uid];
    }

    $screen_node->set('field_screen_access_users', $new_refs);
    $screen_node->save();

    $this->messenger()->addStatus(
      $this->t('Access updated for <em>@title</em>. @count user(s) now have explicit access.', [
        '@title' => $screen_node->label(),
        '@count' => count($new_refs),
      ])
    );

    // Rebuild the form so the admin can immediately manage another screen.
    $form_state->setRebuild(TRUE);
  }

}
