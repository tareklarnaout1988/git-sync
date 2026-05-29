<?php

declare(strict_types=1);

namespace Drupal\psdi_perception\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;

/**
 * Form controller for the psdi perception entity edit forms.
 */
final class PsdiPerceptionForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\psdi_perception\Entity\PsdiPerception $entity */
    $entity = $this->entity;

    $request = \Drupal::request();
    $period  = $request->query->get('period');
    $country = $request->query->get('country');

    // Pré-remplir seulement si on est sur un nouvel entity.
    if ($entity->isNew()) {
      if ($period) {
        $entity->set('period', (int) $period);
      }
      if ($country && Term::load($country)) {
        $entity->set('country', (int) $country);
      }
    }

    // Laisse Drupal générer les widgets.
    $form = parent::buildForm($form, $form_state);

    // Désactiver period/country s’ils sont déjà fixés.
    if ($entity->get('period')->value) {
      $form['period']['#disabled'] = TRUE;
    }
    if ($entity->get('country')->target_id) {
      $form['country']['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    /** @var \Drupal\psdi_perception\Entity\PsdiPerception $entity */
    $entity = $this->entity;

    // Récupérer valeurs utiles.
    $country_name = $entity->get('country')->entity
      ? $entity->get('country')->entity->label()
      : 'N/A';

    $year = $entity->get('period')->value ?? 'N/A';
    $score = $entity->get('score')->value ?? 'N/A';

    $action = $result === SAVED_NEW ? 'created' : 'updated';

    // Message lisible type "Perception for Côte d’Ivoire 2022 has been created (Score: 45.3)".
    $message = $this->t(
      'The perception score for <strong>@country</strong> in <strong>@year</strong> has been successfully @action. (Current value: @score)',
      [
        '@country' => $country_name,
        '@year'    => $year,
        '@action'  => $action,
        '@score'   => $score,
      ]
    );
    $this->messenger()->addStatus($message);

    // Log technique.
    $this->logger('psdi_perception')->notice(
      'Perception score for @country / @year has been @action (Score: @score).',
      [
        '@country' => $country_name,
        '@year'    => $year,
        '@action'  => $action,
        '@score'   => $score,
      ]
    );

    // Redirection : destination prioritaire si présent.
    $request = \Drupal::request();
    $destination = $request->query->get('destination');

    if ($destination && str_starts_with($destination, '/')) {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
    }
    else {
      $form_state->setRedirectUrl($entity->toUrl());
    }

    return $result;
  }

}
