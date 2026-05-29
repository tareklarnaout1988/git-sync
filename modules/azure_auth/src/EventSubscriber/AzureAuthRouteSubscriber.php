<?php

declare(strict_types=1);

namespace Drupal\azure_auth\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber.
 */
final class AzureAuthRouteSubscriber extends RouteSubscriberBase
{

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void
  {
    // @see https://www.drupal.org/node/2187643
    if ($route = $collection->get('user.login')) {
      $route->setPath('/azure-auth/login');
    }
  }
}
