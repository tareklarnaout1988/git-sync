<?php

namespace Drupal\psdi_perception\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\psdi_perception\Service\PerceptionRepository;
use Drupal\Core\Url;
use Drupal\indicator_score\Service\IndicatorRepository;

class DataPerceptionController extends ControllerBase
{

  /**
   * @var \Drupal\psdi_perception\Service\PerceptionRepository
   */
  protected $perceptionRepository;
  protected $indicatorRepository;

  public static function create(ContainerInterface $container)
  {
    $instance = new static();
    $instance->perceptionRepository = $container->get('psdi_perception.perception_repository');
    $instance->indicatorRepository = $container->get('indicator_score.indicator_repository');

    return $instance;
  }

  /**
   * /psdi-dashboard/data/perceptions/{period}
   */
  public function getPerceptionByPeriod(int $period): JsonResponse
  {
    $rows = $this->perceptionRepository->getPerceptionsByPeriod($period);
    $region_list = $this->indicatorRepository->get_africa_regions_list();

    $byIso3 = [];

    foreach ($rows as $row) {
      $county_id = $row['country_id'] ?? null;
      $iso3  = $row['country_iso3'] ?? null;
      $score = $row['score'] ?? null;
      $region = $row['region'] ?? null;

      if (!$iso3) {
        continue;
      }

      // On accepte "No data" mais on renvoie quand même le label
      $scoreValue = (is_numeric($score)) ? (float) $score : null;

      $byIso3['full_data'][$iso3] = [
        'label' => $row['country_name'] ?? $iso3,
        'score' => $scoreValue,
        'region' => $region,
      ];
    }

    $byIso3['regions'] = $region_list;


    $response = new CacheableJsonResponse($byIso3);

    $metadata = (new CacheableMetadata())
      ->setCacheTags(['indicator_score_list'])
      ->setCacheContexts(['languages:language_interface']);

    $response->addCacheableDependency($metadata);

    return $response;
  }

  /**
   * Affiche les perceptions de tous les pays pour une période donnée.
   *
   */
  public function listPerceptions(int $period): array
  {

    $countries = $this->indicatorRepository->get_countries();
    $perceptions = $this->perceptionRepository->getPerceptionsByPeriod($period);

    $perceptionIndex = [];
    foreach ($perceptions as $p) {
      if (!isset($p['country_id'])) {
        continue;
      }
      $perceptionIndex[(int) $p['country_id']] = $p;
    }

    // URL de retour (destination)
    $destination = Url::fromRoute('psdi_perception.perception_list', [
      'period' => $period,
    ])->toString();

    $header = [
      $this->t('Country'),
      $this->t('ISO2'),
      $this->t('ISO3'),
      $this->t('Perception Score'),
      $this->t('Status'),

    ];

    $rows = [];

    foreach ($countries as $tid => $country_name) {

      $name = $country_name;
      $iso2 = '-';
      $iso3 = '-';
      $score_cell = $this->t('N/A');

      $entry = $perceptionIndex[$tid] ?? null;

      if ($entry) {

        // dump($entry);
        $name = $entry['country_name'] ?? $country_name;
        $iso2 = $entry['country_iso2'] ?? '-';
        $iso3 = $entry['country_iso3'] ?? '-';
        $status = $entry['status'] ? "✅" : "❌";

        if ($entry['score'] !== null && $entry['score'] !== '') {
          if (!empty($entry['entity_id'])) {
            // Score cliquable -> edit form
            $score_cell = [
              'data' => [
                '#type'  => 'link',
                '#title' => (string) $entry['score'],
                '#url'   => Url::fromRoute(
                  'entity.psdi_perception.edit_form',
                  ['psdi_perception' => $entry['entity_id']],
                  [
                    'query' => [
                      'destination' => $destination,
                    ],
                  ]
                ),
                '#attributes' => [
                  'class' => ['psdi-perception-edit-link'],
                  'title' => $this->t('Edit perception score'),
                ],
              ],
            ];
          } else {
            $score_cell = $entry['score'];
          }
        } else {
          // Pas de score -> lien Add
          $score_cell = [
            'data' => [
              '#type'  => 'link',
              '#title' => $this->t('Add'),
              '#url'   => Url::fromRoute(
                'entity.psdi_perception.add_form',
                [],
                [
                  'query' => [
                    'country'     => $tid,
                    'period'      => $period,
                    'destination' => $destination,
                  ],
                ]
              ),
              '#attributes' => [
                'class' => ['button', 'button--small'],
                'title' => $this->t('Add perception score'),
              ],
            ],
          ];
        }
      } else {
        // Aucun enregistrement de perception pour ce pays -> lien Add
        $score_cell = [
          'data' => [
            '#type'  => 'link',
            '#title' => $this->t('Add'),
            '#url'   => Url::fromRoute(
              'entity.psdi_perception.add_form',
              [],
              [
                'query' => [
                  'country'     => $tid,
                  'period'      => $period,
                  'destination' => $destination,
                ],
              ]
            ),
            '#attributes' => [
              'class' => ['button', 'button--small'],
              'title' => $this->t('Add perception score'),
            ],
          ],
        ];
      }

      $rows[] = [
        $name,
        $iso2,
        $iso3,
        $score_cell,
        $status
      ];
    }

    return [
      '#type'   => 'table',
      '#header' => $header,
      '#rows'   => $rows,
      '#empty'  => $this->t('No countries found.'),
      '#title'  => $this->t('Perception scores for period @p', ['@p' => $period]),
    ];
  }
}
