<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Vérifie l’unicité (country, indicator, period) pour l’entité indicator.
 *
 * @Constraint(
 *   id = "IndicatorUnique",
 *   label = @Translation("Unique country/indicator/period combination", context = "Validation")
 * )
 */
class IndicatorUniqueConstraint extends Constraint {

  /**
   * Message affiché si la combinaison existe déjà.
   *
   * @var string
   */
  public string $notUniqueMessage = 'An entry already exists for this Country, Indicator, and Period.';
}
