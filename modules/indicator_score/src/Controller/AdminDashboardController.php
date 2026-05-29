<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\indicator_score\Service\IndicatorRepository;
use Drupal\psdi_perception\Service\PerceptionRepository;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;

/**
 * Returns responses for Indicator Score routes.
 */
final class AdminDashboardController extends ControllerBase
{

  protected IndicatorRepository $indicatorRepository;
  protected PerceptionRepository $perceptionRepository;

  public function __construct(
    IndicatorRepository $indicatorRepository,
    PerceptionRepository $perceptionRepository
  ) {
    $this->indicatorRepository = $indicatorRepository;
    $this->perceptionRepository = $perceptionRepository;
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('indicator_score.indicator_repository'),
      $container->get('psdi_perception.perception_repository')
    );
  }

  public function dashboard(): array
  {

    // $perception_data = $this->perceptionRepository->getPerceptionCoverageByPeriod();


    $legend = [
      '#type' => 'container',
      '#attributes' => ['class' => ['psdi-legend']],
      'title' => [
        '#markup' => '<strong>' . $this->t('Legend') . ':</strong>',
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('✅ Country profiles complete & published'),
          $this->t('❌ Country profiles incomplete'),
          $this->t('Only the latest year with status ✅ Country profiles complete & published is displayed to end users.')
        ],
      ],
    ];
    //  INDICATEURS par année.
    $indicator_data = $this->indicatorRepository->getCoverageByPeriod();


    //  PERCEPTIONS par année.
    $perception_data = $this->perceptionRepository->getPerceptionCoverageByPeriod2();



    //
    //  Table 1 : INDICATor
    //
    $header = [
      $this->t('Year'),
      $this->t('Published Indicators'),
      $this->t('Unpublished Indicators'),
      $this->t('Total indicators'),
      $this->t('Coverage (%)'),
      $this->t('Status'),
      $this->t('Actions'),
    ];

    $rows = [];

    foreach ($indicator_data as $indicator) {


      $period_id = (int) $indicator['period_id'];

      // --- Lien popup "Show missing"
      $details_link = [];

      if ($indicator['coverage_pct'] != 100) {
        $details_link = [

          '#type' => 'link',
          '#title' => $this->t('Show missing'),
          '#url' => Url::fromRoute('indicator_score.admin_dashboard_missing', [
            'period' => $period_id,
          ]),
          '#attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => 800,
            ]),
          ],
        ];
      }


      // --- Lien “View indicators”
      $indicator_list_link = [
        '#type' => 'link',
        '#title' => $this->t('View indicators'),
        '#url' => Url::fromRoute('indicator_score.indicators_list', [
          'period' => $period_id,
        ]),
        '#attributes' => [
          'class' => ['button', 'button--small'],
        ],
      ];

      // --- Lien publish link

      if ($indicator['unpublished_indicators'] == 0) {
        // Disabled
        $publish_link = [
          '#type' => 'html_tag',
          '#tag'  => 'span',
          '#value' => $this->t('Publish indicators'),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--primary', 'is-disabled'],
            'title' => $this->t('Please fill all indicators before publishing'),
          ],
        ];
      } else {
        // Enabled link
        $publish_link = [
          '#type' => 'link',
          '#title' => $this->t('Publish indicators'),
          '#url' => Url::fromRoute('indicator_score.publish_indicators', ['period' => $period_id]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--primary'],
          ],
        ];
      }

      if ($indicator['published_indicators']) {
        // Enabled
        $unpublish_link = [
          '#type' => 'link',
          '#title' => $this->t('Unpublish indicators'),
          '#url' => Url::fromRoute('indicator_score.unpublish_indicators', [
            'period' => $period_id,
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger'],
            'title' => $this->t('Unpublish all indicators for this year'),
          ],
        ];
      } else {
        // Disabled
        $unpublish_link = [
          '#type' => 'html_tag',
          '#tag'  => 'span',
          '#value' => $this->t('Unpublish indicators'),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger', 'is-disabled'],
            'title' => $this->t('No published indicators to unpublish'),
          ],
        ];
      }
      $countryProfiles  = "❌";
      if ($indicator['coverage_pct'] == 100 && $indicator['unpublished_indicators'] == 0) {
        $countryProfiles =  "✅";
      }
      // Colonne "Actions"
      $actions = [
        'data' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['psdi-actions']],

          'view' => $indicator_list_link,
          'sep1' => [
            '#markup' => ' | ',
          ],
          'publish' => $publish_link,

          'unpublish' => $unpublish_link,

          'missing' => $details_link,


        ],
      ];

      $rows[] = [
        $indicator['period_name'],
        $indicator['published_indicators'],
        $indicator['unpublished_indicators'],
        $indicator['total_indicators'],
        $indicator['coverage_pct'] . ' %',
        $countryProfiles,
        $actions,
      ];
    }

    // Bouton d'import
    $import_button = [
      '#type' => 'link',
      '#title' => $this->t('Import indicator scores'),
      '#url' => Url::fromRoute('indicator_import_ui.form'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $indicator_table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No data available.'),
    ];

    //
    // ---------- TABLEAU 2 : PERCEPTIONS ----------
    //
    $perception_header = [
      $this->t('Year'),
      $this->t('Published perceptions'),
      $this->t('Unpublished perceptions'),
      $this->t('Total countries'),
      $this->t('Coverage (%)'),
      $this->t('Status'),
      $this->t('Actions'),
    ];

    $perception_rows = [];

    foreach ($perception_data as $perception) {

      $period_id = (int) $perception['period'];

      // Lien "View perception details"
      $perception_link = [
        '#type' => 'link',
        '#title' => $this->t('View perceptions'),
        '#url' => Url::fromRoute('psdi_perception.perception_list', [
          'period' => $perception['period'],
        ]),
        '#attributes' => [
          'class' => ['button', 'button--small'],
        ],
      ];

      //
      // PUBLISH PERCEPTIONS
      //
      if ($perception['unpublished_countries'] > 0) {
        // Enabled
        $perception_publish_link = [
          '#type' => 'link',
          '#title' => $this->t('Publish perceptions'),
          '#url' => Url::fromRoute('psdi_perception.publish_perceptions', [
            'period' => $period_id,
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--primary'],
            'title' => $this->t('Publish all perceptions for this year'),
          ],
        ];
      } else {
        // Disabled
        $perception_publish_link = [
          '#type' => 'html_tag',
          '#tag'  => 'span',
          '#value' => $this->t('Publish perceptions'),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--primary', 'is-disabled'],
            'title' => $this->t('Please fill all perception scores before publishing'),
          ],
        ];
      }

      //
      // UNPUBLISH PERCEPTIONS
      //
      if ($perception['published_countries']) {
        // Enabled
        $perception_unpublish_link = [
          '#type' => 'link',
          '#title' => $this->t('Unpublish perceptions'),
          '#url' => Url::fromRoute('psdi_perception.unpublish_perceptions', [
            'period' => $period_id,
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger'],
            'title' => $this->t('Unpublish all perceptions for this year'),
          ],
        ];
      } else {
        // Disabled
        $perception_unpublish_link = [
          '#type' => 'html_tag',
          '#tag'  => 'span',
          '#value' => $this->t('Unpublish perceptions'),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger', 'is-disabled'],
            'title' => $this->t('No published perceptions to unpublish'),
          ],
        ];
      }

      $countryPerceptionProfiles  = "❌";
      if ($perception['coverage_pct'] == 100 && $perception['unpublished_countries'] == 0) {
        $countryPerceptionProfiles =  "✅";
      }
      $actions_perception = [
        'data' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['psdi-actions']],

          'view' => $perception_link,
          'sep2' => [
            '#markup' => ' | ',
          ],
          'publish' => $perception_publish_link,
          'unpublish' => $perception_unpublish_link,

        ],
      ];
      $perception_rows[] = [
        $perception['period'],
        $perception['published_countries'],
        $perception['unpublished_countries'],
        $perception['total_countries'],
        $perception['coverage_pct'] . ' %',
        $countryPerceptionProfiles,
        $actions_perception
      ];
    }
    $import_perception_button = [
      '#type' => 'link',
      '#title' => $this->t('Import perceptions'),
      '#url' => Url::fromRoute('psdi_perception.import_form'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];
    $perception_table = [
      '#type' => 'table',
      '#header' => $perception_header,
      '#rows' => $perception_rows,
      '#empty' => $this->t('No perception data available.'),
    ];

    //
    // ---------- BUILD GLOBAL ----------
    //
    $build = [
      '#type' => 'container',
      'legend' => $legend,
      // Titre + bouton import + tableau indicateurs
      'indicators_title' => [
        '#markup' => '<h2>' . $this->t('Indicator coverage by year') . '</h2>',
      ],
      'import' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['psdi-import-wrapper']],
        'button' => $import_button,
      ],
      'indicator_table' => $indicator_table,


      // Titre + tableau perception
      'perception_title' => [
        '#markup' => '<h2 style="margin-top:2rem;">' . $this->t('Perception coverage by year') . '</h2>',
      ],
      'import_perception' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['psdi-import-wrapper']],
        'button' => $import_perception_button,
      ],
      'perception_table' => $perception_table,

      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Contenu du POPUP : ce qui manque par pays pour une année donnée.
   */
  public function missingDetails(int $period): array
  {
    $countries = $this->indicatorRepository->get_countries();

    $header = [
      $this->t('Country'),
      $this->t('Missing indicators'),
      $this->t('Missing count'),
    ];

    $rows = [];

    foreach ($countries as $country_tid => $country_name) {
      $missing = $this->indicatorRepository
        ->getMissingIndicatorsForCountryPeriod((int) $country_tid, $period);

      if (empty($missing)) {
        continue;
      }

      $labels = implode(', ', array_column($missing, 'label'));
      $count = count($missing);

      $rows[] = [
        $country_name,
        $labels,
        $count,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No missing indicators for this period.'),
      '#title' => $this->t('Missing indicators for period @p', ['@p' => $period]),
    ];
  }
}
