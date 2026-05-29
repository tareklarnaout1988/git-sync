<?php

declare(strict_types=1);

namespace Drupal\indicator_score;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the indicator score entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class IndicatorScoreAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view indicator_score'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit indicator_score'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete indicator_score'),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete indicator_score revision'),
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermissions($account, ['view indicator_score revision', 'view indicator_score']),
      'revert' => AccessResult::allowedIfHasPermissions($account, ['revert indicator_score revision', 'edit indicator_score']),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create indicator_score', 'administer indicator_score'], 'OR');
  }

}
