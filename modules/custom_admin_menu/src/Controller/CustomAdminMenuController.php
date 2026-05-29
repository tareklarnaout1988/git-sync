<?php

namespace Drupal\custom_admin_menu\Controller;

use Drupal\Core\Controller\ControllerBase;

class CustomAdminMenuController extends ControllerBase {

  public function main() {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('<p>This is the main page for the custom admin menu.</p>'),
    ];
  }

  public function firstSubPage() {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('<p>This is the first submenu page.</p>'),
    ];
  }

  public function secondSubPage() {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('<p>This is the second submenu page.</p>'),
    ];
  }

}
