<?php

declare(strict_types=1);

namespace Drupal\psdi_perception;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the psdi perception entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class PsdiPerceptionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view psdi_perception'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit psdi_perception'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete psdi_perception'),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete psdi_perception revision'),
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermissions($account, ['view psdi_perception revision', 'view psdi_perception']),
      'revert' => AccessResult::allowedIfHasPermissions($account, ['revert psdi_perception revision', 'edit psdi_perception']),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create psdi_perception', 'administer psdi_perception'], 'OR');
  }

}
