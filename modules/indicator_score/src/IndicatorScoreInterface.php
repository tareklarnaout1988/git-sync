<?php

declare(strict_types=1);

namespace Drupal\indicator_score;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an indicator score entity type.
 */
interface IndicatorScoreInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
