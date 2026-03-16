<?php

namespace Drupal\signage_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;

class DashboardController extends ControllerBase {

  public function build() {
    $account = $this->currentUser();

    if ($account->isAnonymous()) {
      return [
        '#markup' => '<p>Welcome! Please <a href="/user/login">log in</a> to access your dashboard.</p>',
      ];
    }

    // Load the full user entity to get created date
    $user = \Drupal\user\Entity\User::load($account->id());
    $created = $user->getCreatedTime();
    $account_age = \Drupal::service('date.formatter')
      ->formatDiff($created, \Drupal::time()->getCurrentTime());

    return [
      '#markup' => '
        <h2>Welcome, ' . $account->getDisplayName() . '</h2>
        <p>Email: ' . $account->getEmail() . '</p>
        <p>Member for: ' . $account_age . '</p>
      ',
    ];
  }
}
