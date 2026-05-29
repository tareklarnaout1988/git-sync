<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Form controller for the indicator score entity edit forms.
 */
final class IndicatorScoreForm extends ContentEntityForm
{

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    /** @var \Drupal\indicator_score\Entity\IndicatorScore $entity */
    $entity = $this->entity;


    $request = \Drupal::request();
    $period = $request->query->get('period');

    $indicator = $request->query->get('indicator');
    $country = $request->query->get('country');

    //  Prepopulate only if entity is new
    if ($entity->isNew()) {
      if ($period) {
        $entity->set('period', $period);
      }
      if ($indicator && Term::load($indicator)) {
        $entity->set('indicator', $indicator);
      }
      if ($country && Term::load($country)) {
        $entity->set('country', $country);
      }
    }

    $form = parent::buildForm($form, $form_state);

    if ($entity->get('period')->value) {
      $form['period']['#disabled'] = TRUE;
    }
    if ($entity->get('indicator')->target_id) {
      $form['indicator']['#disabled'] = TRUE;
    }
    if ($entity->get('country')->target_id) {
      $form['country']['#disabled'] = TRUE;
    }

    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int
  {
    $result = parent::save($form, $form_state);

    /** @var \Drupal\indicator_score\Entity\IndicatorScore $entity */
    $entity = $this->entity;

    // Récupérer les valeurs
    $indicator_name = $entity->get('indicator')->entity ? $entity->get('indicator')->entity->label() : 'N/A';
    $country_name = $entity->get('country')->entity ? $entity->get('country')->entity->label() : 'N/A';
    $year = $entity->get('period')->value ?? 'N/A';
    $score = $entity->get('score')->value ?? 'N/A';

    // Construire le message personnalisé
    $action = $result === SAVED_NEW ? 'created' : 'updated';
    $message = $this->t(
      'The score for <strong>@indicator</strong> in <strong>@country</strong> for the year <strong>@year</strong> has been successfully @action. (Current value: @score)',
      [
        '@indicator' => $indicator_name,
        '@year' => $year,
        '@country' => $country_name,
        '@action' => $action,
        '@score' => $score,
      ]
    );

    // Afficher le message
    $this->messenger()->addStatus($message);

    // Logger l’action
    $this->logger('indicator_score')->notice(
      'Indicator score @indicator for @year / @country has been @action (Score: @score).',
      [
        '@indicator' => $indicator_name,
        '@year' => $year,
        '@country' => $country_name,
        '@action' => $action,
        '@score' => $score,
      ]
    );

    // Redirection après sauvegarde
    $destination = \Drupal::request()->query->get('destination');
    if ($destination && str_starts_with($destination, '/')) {
      $form_state->setRedirectUrl(\Drupal\Core\Url::fromUserInput($destination));
    } else {
      $form_state->setRedirectUrl($entity->toUrl());
    }

    return $result;
  }
}
