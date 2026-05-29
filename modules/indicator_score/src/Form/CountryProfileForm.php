<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Provides a indicator_score form.
 */
final class CountryProfileForm extends FormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'indicator_score_country_profile';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $country = NULL, $period = NULL): array
  {

    if ($country === NULL || $period === NULL) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    if (!Term::load($country) || !$period) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    /** @var \Drupal\indicator_score\Service\IndicatorRepository $repo */
    $repo = \Drupal::service('indicator_score.indicator_repository');
    $scores = $repo->listAllScores((int) $period, (int) $country);

    $form['info'] = [
      '#markup' => $this->t('Country Profile: @c, Period ID: @p', ['@c' => $this->get_taxonomy_term_name($country), '@p' => $period]),
    ];

    // dump($period);
    // --- Vocab names (adapte si besoin) -------------------------------------
    $period_vocab = 'period';
    $country_vocab = 'cit_countries_information';
    $dimension_vocab = 'dimension';
    $subdimension_vocab = 'subdimension';
    $indicator_vocab = 'indicator';
    $subdim_to_dim_field = 'field_dimension';
    $ind_to_subdim_field = 'field_subdimension';

    // On veut récupérer un arbre propre en submit.
    $form['#tree'] = TRUE;

    // --- Period & Country (ajoutés) -----------------------------------------
    $form['period'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Period'),
      '#options' => $period,
      '#empty_option' => $this->t('- Select period -'),
      '#required' => TRUE,
      '#default_value' => $period,
      '#disabled' => 'disabled'

    ];

    $form['country'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $this->getTaxonomyOptions($country_vocab),
      '#empty_option' => $this->t('- Select country -'),
      '#required' => TRUE,
      '#default_value' => $country,
      '#disabled' => 'disabled'

    ];

    // --- Arborescence D -> SD -> IND ----------------------------------------
    $tree = $this->buildDimensionTree(
      $dimension_vocab,
      $subdimension_vocab,
      $indicator_vocab,
      $subdim_to_dim_field,
      $ind_to_subdim_field
    );

    $form['dimensions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dsi-wrapper']],
      '#cache' => ['max-age' => 0],
    ];

    foreach ($tree as $dim_id => $dim) {
      // Bloc par Dimension.
      $form['dimensions'][$dim_id] = [
        '#type' => 'details',
        '#title' => $dim['label'],
        '#open' => TRUE,
      ];

      if (!empty($dim['subdimensions'])) {
        foreach ($dim['subdimensions'] as $sub_id => $sub) {
          // Bloc par Subdimension.
          $form['dimensions'][$dim_id]['subdimensions'][$sub_id] = [
            '#type' => 'details',
            '#title' => $sub['label'],
            '#open' => FALSE,
          ];

          if (!empty($sub['indicators'])) {
            foreach ($sub['indicators'] as $ind_id => $ind_label) {
              $form['dimensions'][$dim_id]['subdimensions'][$sub_id]['indicators'][$ind_id] = [
                '#type' => 'container',
                '#attributes' => ['class' => ['indicator-row']],
              ];

              $form['dimensions'][$dim_id]['subdimensions'][$sub_id]['indicators'][$ind_id]['label'] = [
                '#type' => 'item',
                '#markup' => $this->t('@label', ['@label' => $ind_label]),
              ];

              // Textfield pour la valeur de l'indicateur.
              $form['dimensions'][$dim_id]['subdimensions'][$sub_id]['indicators'][$ind_id]['value'] = [
                '#type' => 'number',
                '#step' => 0.00001,
                '#min' => 0,
                '#max' => 100,
                '#title' => $this->t('Value'),
                '#title_display' => 'invisible',
                '#default_value' => $scores[$ind_id]['score'],
                '#placeholder' => $this->t('Enter value'),

                // '#default_value' => ... // si tu as une valeur à préremplir
              ];
            }
          } else {
            $form['dimensions'][$dim_id]['subdimensions'][$sub_id]['empty'] = [
              '#type' => 'item',
              '#markup' => $this->t('No indicators.'),
            ];
          }
        }
      } else {
        $form['dimensions'][$dim_id]['empty'] = [
          '#type' => 'item',
          '#markup' => $this->t('No subdimensions.'),
        ];
      }
    }

    // --- Actions -------------------------------------------------------------
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
    ];
    return $form;
  }

  /**
   * Arbre: Dimension -> Subdimension -> Indicators.
   */
  function buildDimensionTree(
    string $dimension_vocab,
    string $subdimension_vocab,
    string $indicator_vocab,
    string $subdim_to_dim_field,
    string $ind_to_subdim_field
  ): array {
    $tree = [];

    // 1) Dimensions
    $dimensions = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($dimension_vocab, 0, NULL, TRUE);

    $dimensions = array_filter($dimensions, function ($term) {
      return $term->get('status')->value == 1;
    });

    usort(
      $dimensions,
      fn($a, $b) => ($a->getWeight() <=> $b->getWeight())
        ?: strcasecmp($a->label(), $b->label())
    );


    // dd($dimensions);
    foreach ($dimensions as $dim) {
      $tree[$dim->id()] = [
        'label' => $dim->label(),
        'subdimensions' => [],
      ];

      // 2) Subdimensions liées à la dimension
      $sub_ids = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', $subdimension_vocab)
        ->condition('status', 1) // published = 1
        ->condition("$subdim_to_dim_field.target_id", $dim->id())
        ->accessCheck(TRUE)
        ->execute();

      if ($sub_ids) {
        $subs = Term::loadMultiple($sub_ids);
        usort($subs, fn($a, $b) => strcasecmp($a->label(), $b->label()));

        foreach ($subs as $sub) {
          $tree[$dim->id()]['subdimensions'][$sub->id()] = [
            'label' => $sub->label(),
            'indicators' => [],
          ];

          // 3) Indicateurs liés à la subdimension
          $ind_ids = \Drupal::entityQuery('taxonomy_term')
            ->condition('vid', $indicator_vocab)
            ->condition('status', 1) // published = 1
            ->condition("$ind_to_subdim_field.target_id", $sub->id())
            ->accessCheck(TRUE)
            ->execute();

          if ($ind_ids) {
            $inds = Term::loadMultiple($ind_ids);
            usort($inds, fn($a, $b) => strcasecmp($a->label(), $b->label()));
            foreach ($inds as $ind) {
              $tree[$dim->id()]['subdimensions'][$sub->id()]['indicators'][$ind->id()] = $ind->label();
            }
          }
        }
      }
    }

    // dd($tree);
    return $tree;
  }

  /**
   * Options [tid => label] pour un vocabulaire.
   */
  private function getTaxonomyOptions(string $vocab): array
  {
    $options = [];
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vocab, 0, NULL, TRUE);
    foreach ($terms as $term) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    // Exemple de validation : forcer numérique uniquement (si besoin).
    // $values = $form_state->getValue('dimensions') ?? [];
    // foreach ($values as $dim_id => $dim) {
    //   foreach (($dim['subdimensions'] ?? []) as $sub_id => $sub) {
    //     foreach (($sub['indicators'] ?? []) as $ind_id => $ind) {
    //       $val = $ind['value'] ?? '';
    //       if ($val !== '' && !is_numeric($val)) {
    //         $form_state->setErrorByName("dimensions][$dim_id][subdimensions][$sub_id][indicators][$ind_id][value", $this->t('Value must be numeric.'));
    //       }
    //     }
    //   }
    // }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $etm = \Drupal::entityTypeManager();
    // dd($form_state->getValues());

    $period_tid = (int) $form_state->getValue('period');
    $country_tid = (int) $form_state->getValue('country');

    $values_tree = $form_state->getValue('dimensions') ?? [];
    $filled = 0;
    foreach ($values_tree as $dim) {
      foreach (($dim['subdimensions'] ?? []) as $sub) {
        foreach (($sub['indicators'] ?? []) as $ind_id => $score) {
          if (!empty($score['value'])) {


            $storage = $etm->getStorage('indicator_score');

            // 1) Tenter de charger l'existant.
            $existing = $storage->loadByProperties([
              'country'   => $country_tid,
              'indicator' => $ind_id,
              'period'    => $period_tid,
            ]);
            $entity = $existing ? reset($existing) : NULL;

            if ($entity) {
              $current_score = $entity->get('score')->value;
              // dd($current_score == $score['value']);
              if ($current_score != $score['value']) {
                // update
                $entity->set('score', $score['value']);
                $filled++;
              }
            } else {
              // Création si rien n'existe encore.
              $entity = $storage->create([
                'country'   => $country_tid,
                'indicator' => $ind_id,
                'period'    => $period_tid,
                'score'     => $score['value'],
                'status'    => 1,
              ]);
              $filled++;
            }
            $entity->save();
          }
        }
      }
    }

    $this->messenger()->addStatus($this->t(
      'Saved for Period @p and Country @c. @n indicator values filled.',
      [
        '@p' => $period_tid,
        '@c' => $country_tid,
        '@n' => $filled,
      ]
    ));
    //  $this->redirect('business.indicators_list', ['period' => $period_tid]);
    $form_state->setRedirect('indicator_score.indicators_list', [
      'period' => $period_tid,
    ]);
  }
  private function get_taxonomy_term_name($tid): ?string
  {

    if (empty($tid)) {
      return NULL;
    }

    $term = Term::load($tid);
    return $term ? $term->label() : NULL;
  }
}
