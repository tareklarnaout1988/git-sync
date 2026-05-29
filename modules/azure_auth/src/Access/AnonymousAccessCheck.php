<?php

namespace Drupal\azure_auth\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;


/**
 * Checks access for anonymous users only.
 */
class AnonymousAccessCheck implements AccessInterface {

  /**
   * Checks access for anonymous users only.
   */
  public function access(AccountInterface $account) {
    return $account->isAnonymous() ? AccessResult::allowed() : AccessResult::forbidden();
  }

}
