<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\indicator_score\Entity\IndicatorScore;

/**
 * Valide la contrainte IndicatorUnique.
 */
class IndicatorUniqueConstraintValidator extends ConstraintValidator
{

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint)
  {
    // Ne valide que nos entités Indicator.
    if (!$entity instanceof IndicatorScore) {
      return;
    }

    // Récupérer les IDs cibles des références.
    $country   = (int) $entity->get('country')->target_id;
    $indicator = (int) $entity->get('indicator')->target_id;
    $period    = (int) $entity->get('period')->target_id;

    // Ne valide que si les trois sont présents.
    if (!$country || !$indicator || !$period) {
      return;
    }

    // Construire la requête d’existence.
    $query = \Drupal::entityTypeManager()
      ->getStorage('indicator_score')
      ->getQuery()
      ->condition('country', $country)
      ->condition('indicator', $indicator)
      ->condition('period', $period);

    // Exclure l’entité courante en édition.
    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }

    $ids = $query->accessCheck(TRUE)->execute();

    if (!empty($ids)) {
      // Déclenche la violation -> message utilisateur.
      $this->context->addViolation($constraint->notUniqueMessage);
    }
  }
}
