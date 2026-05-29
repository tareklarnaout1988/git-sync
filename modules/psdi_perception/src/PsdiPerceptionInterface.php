<?php

declare(strict_types=1);

namespace Drupal\psdi_perception;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a psdi perception entity type.
 */
interface PsdiPerceptionInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
