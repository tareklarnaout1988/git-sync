<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\indicator_score\Form\CountryProfileForm;
use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Database\Database;
use Drupal\indicator_score\Entity\IndicatorScore;

/**
 * Returns responses for indicator_score routes.
 */
final class IndicatorScoreController extends ControllerBase
{

  public function title($period)
  {
    return $this->t('Period @period', ['@period' => $period]);
  }

  public function __invoke(?int $period = NULL): array
  {

    $repo = \Drupal::service('indicator_score.indicator_repository');

    // 0) Countries (lignes).
    $countries = $repo->get_countries(); // [tid => label]

    // 1) Arbre Dimension > Subdimension > Indicators (comme ton screenshot).
    $period_vocab = 'period';
    $country_vocab = 'cit_countries_information';
    $dimension_vocab = 'dimension';
    $subdimension_vocab = 'subdimension';
    $indicator_vocab = 'indicator';
    $subdim_to_dim_field = 'field_dimension';
    $ind_to_subdim_field = 'field_subdimension';



    $dimensionTree = $repo->buildDimensionTree(
      $dimension_vocab,
      $subdimension_vocab,
      $indicator_vocab,
      $subdim_to_dim_field,
      $ind_to_subdim_field
    );


    [$columns, $headerRows] = $this->buildColumnsAndHeaders($dimensionTree);

    $scores = $this->loadScoresMap(array_keys($countries), array_column($columns, 'indicator_tid'), $period);

    // $scores[$country_tid][$indicator_tid] = float|int|null
    // dd($scores);
    $periods = $repo->getExistingIndiscatorScoreYear(FALSE);
    // dd($periods);
    return [
      '#markup' => $this->t('@period', ['@period' => $period]),
      '#theme'       => 'indicator_table',
      '#periods' => $periods,
      '#period'   => $period,
      '#countries'   => $countries,
      '#columns'     => $columns,
      '#scores'      => $scores,
      '#header_rows' => $headerRows,
      '#attached'    => [
        'library' => ['indicator_score/datatables'], // optionnel (CSS)
      ],
    ];
  }

  /**
   * Construit colonnes + en-têtes groupés (3 lignes) à partir de l’arbre.
   *
   * @param array $tree  // structure comme ton screenshot
   * @return array{0: array, 1: array}
   */
  protected function buildColumnsAndHeaders(array $tree): array
  {
    $columns = [];
    $h1 = []; // Dimensions
    $h2 = []; // Subdimensions
    $h3 = []; // Indicators

    // On garde un compteur de colspan pour chaque groupement.
    foreach ($tree as $dim_tid => $dim) {
      $dim_label = $dim['label'] ?? ('#' . $dim_tid);
      $dim_colspan = 0;
      $subRows = [];

      foreach ($dim['subdimensions'] ?? [] as $sd_tid => $sd) {
        $sd_label = $sd['label'] ?? ('#' . $sd_tid);
        $sd_colspan = 0;
        foreach ($sd['indicators'] ?? [] as $ind_tid => $ind_label) {
          $columns[] = [
            'dimension_tid'    => (int) $dim_tid,
            'dimension'        => (string) $dim_label,
            'subdimension_tid' => (int) $sd_tid,
            'subdimension'     => (string) $sd_label,
            'indicator_tid'    => (int) $ind_tid,
            'indicator'        => (string) $ind_label['label'],
          ];

          $sd_colspan++;
          $h3[] = ['data' => $ind_label['label'], 'colspan' => 1, 'rowspan' => 1];
        }

        // Subdimension header cell
        $subRows[] = ['data' => $sd_label, 'colspan' => max(1, $sd_colspan), 'rowspan' => 1];
        $dim_colspan += max(1, $sd_colspan);
      }

      // Dimension header cell
      $h1[] = ['data' => $dim_label, 'colspan' => max(1, $dim_colspan), 'rowspan' => 1];

      // Append subdimension cells in order
      foreach ($subRows as $cell) {
        $h2[] = $cell;
      }
    }

    // On ajoutera dans Twig une première cellule "Country" en rowspan=3,
    // donc ici les 3 lignes d’en-têtes ne contiennent que les colonnes indicateurs.
    $headerRows = [$h1, $h2, $h3];
    return [$columns, $headerRows];
  }

  /**
   * Charge les scores Country × Indicator (remplace les noms de tables/champs si besoin).
   * Retourne: [$country_tid][$indicator_tid] => score|null
   */
  protected function loadScoresMap(array $country_tids, array $indicator_tids, ?int $period_tid): array
  {
    $map = [];
    if (!$country_tids || !$indicator_tids) {
      return $map;
    }
    $conn = Database::getConnection();


    $query = $conn->select('indicator_score', 'ind')
      ->fields('ind', ['id', 'country', 'indicator', 'score']);

    $query->condition('ind.country', $country_tids, 'IN');
    $query->condition('ind.indicator', $indicator_tids, 'IN');
    if ($period_tid !== NULL) {
      $query->condition('ind.period', $period_tid);
    }


    $res = $query->execute();

    foreach ($res as $row) {


      $country = (int) $row->country;
      $indicator = (int) $row->indicator;
      $indicator_score_id = (int) $row->id;
      $map[$country][$indicator]['score'] = is_numeric($row->score) ? (float) $row->score : NULL;
      $map[$country][$indicator]['indicator_score_id'] = $indicator_score_id;
    }

    return $map;
  }
}
