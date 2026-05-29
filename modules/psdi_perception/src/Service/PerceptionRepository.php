<?php

namespace Drupal\psdi_perception\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\indicator_score\Service\IndicatorRepository;

class PerceptionRepository
{

    protected $entityTypeManager;
    protected $database;
    protected $indicatorRepository;


    public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database, IndicatorRepository $indicatorRepository)
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->database = $database;
        $this->indicatorRepository = $indicatorRepository;
    }

    /**
     * Retourne les scores de perception sous forme :
     *   [ 'DZA' => 18.02, 'MAR' => 42.45, ... ]
     */
    public function getPerceptionsByPeriod(int $period): array
    {


        $query = $this->database->select('psdi_perception', 'p');
        $query->fields('p', ['id', 'country', 'score', 'status']);
        $query->condition('period', $period);
        if (!(\Drupal::currentUser()->hasRole('psdi_administrator') || \Drupal::currentUser()->hasRole('administrator'))) {
            $query->condition('p.status',  1);
        }

        $rows = $query->execute()->fetchAll();

        if (!$rows) {
            return [];
        }


        $countryIds = [];
        foreach ($rows as $row) {
            if (!empty($row->country)) {
                $countryIds[] = (int) $row->country;
            }
        }

        $countryIds = array_unique($countryIds);

        if (!$countryIds) {
            return [];
        }

        //Charger tous les terms de pays concernés
        $terms = $this->entityTypeManager
            ->getStorage('taxonomy_term')
            ->loadMultiple($countryIds);

        $output = [];
        $langcode = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

        foreach ($rows as $row) {

            $tid = (int) $row->country;

            if (!isset($terms[$tid])) {
                continue;
            }

            /** @var \Drupal\taxonomy\Entity\Term $term */
            $term = $terms[$tid];

            // check vocab type
            if ($term->bundle() !== 'cit_countries_information') {
                continue;
            }


            $iso2 = $term->hasField('field_citf_iso2_code') && !$term->get('field_citf_iso2_code')->isEmpty()
                ? $term->get('field_citf_iso2_code')->value
                : null;

            $iso3 = $term->hasField('field_citf_iso3_code') && !$term->get('field_citf_iso3_code')->isEmpty()
                ? $term->get('field_citf_iso3_code')->value
                : null;

            $region = $term->hasField('field_africa_region') && !$term->get('field_africa_region')->isEmpty()
                ? $term->get('field_africa_region')->value
                : null;

            // Valeur  score

            $score = is_numeric($row->score) ? (float) $row->score : null;

            $status = $row->status;

            if ($term->hasTranslation($langcode)) {
                $term = $term->getTranslation($langcode);
            }

            // dd($term->get('field_citf_iso2_code')->value);

            $name = $term->label();
            $output[] = [
                'entity_id' => (int) $row->id,
                'country_id' => $tid,
                'country_name' => $name,
                'country_iso2' => $iso2,
                'country_iso3' => $iso3,
                'region' => $region,
                'score' => $score,
                'status' => $status
            ];
        }

        return $output;
    }
    public function getPerceptionCoverageByPeriod2(): array
    {
        $countries = $this->indicatorRepository->get_countries();
        if (empty($countries)) {
            return [];
        }

        $validCountryIds = array_map('intval', array_keys($countries));
        $totalCountries  = count($validCountryIds);


        $query = $this->database->select('psdi_perception', 'p');
        $query->addField('p', 'period');

        $query->addExpression(
            "COUNT(DISTINCT CASE WHEN p.status = 1 THEN p.country END)",
            'published_countries'
        );

        $query->addExpression(
            "COUNT(DISTINCT CASE WHEN p.status = 0 THEN p.country END)",
            'unpublished_countries'
        );

        $query->condition('p.country', $validCountryIds, 'IN');

        $query->groupBy('p.period');
        $query->orderBy('p.period', 'DESC');

        $result = $query->execute()->fetchAll();
        if (!$result) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {

            $published   = (int) $row->published_countries;
            $unpublished = (int) $row->unpublished_countries;
            $filled      = $published + $unpublished;

            if ($filled === 0) {
                continue;
            }

            $coveragePct = round($filled / $totalCountries * 100, 1);

            $rows[] = [
                'period'                => (int) $row->period,
                'published_countries'   => $published,
                'unpublished_countries' => $unpublished,
                'total_countries'       => $totalCountries,
                'coverage_pct'          => $coveragePct,
            ];
        }

        // dump($rows); // à garder seulement pour debug
        return $rows;
    }

    // public function getPerceptionCoverageByPeriod(): array
    // {
    //     // 1) Nombre total de pays (cit_countries_information publiés).
    //     $countries = $this->indicatorRepository->get_countries();

    //     if (empty($countries)) {
    //         return [];
    //     }
    //     $countCountries = count($countries);

    //     // Récupérer toutes les périodes distinctes présentes dans psdi_perception.
    //     $periods = $this->getExistingPerceptionScoreYear(FALSE);

    //     if (empty($periods)) {
    //         return [];
    //     }

    //     $rows = [];

    //     foreach ($periods as $period) {
    //         $totalUnpublished = 0;

    //         $period = (int) $period;

    //         $perceptions = $this->getPerceptionsByPeriod($period);

    //         $filledByCountry = [];
    //         foreach ($perceptions as $row) {
    //             if ($row['score'] === null) {
    //                 continue;
    //             }
    //             $filledByCountry[(int) $row['country_id']] = TRUE;
    //             $totalUnpublished += ((int) $row['status'] === 0);
    //         }

    //         $filledCount = count($filledByCountry);
    //         if ($filledCount === 0) {
    //             continue;
    //         }

    //         $coveragePct = round($filledCount / $countCountries * 100, 1);


    //         $rows[] = [
    //             'period'           => $period,
    //             'filled_countries' => $filledCount,
    //             'total_countries'  => $countCountries,
    //             'coverage_pct'     => $coveragePct,
    //             'total_unpublished' => $totalUnpublished

    //         ];
    //     }

    //     return $rows;
    // }

    public function getExistingPerceptionScoreYear(?bool $includeStatus = TRUE)
    {
        $connection = Database::getConnection();

        $query = $connection->select('psdi_perception', 'p');
        $query->addField('p', 'period');
        $query->addExpression('COUNT(period)', 'period_count');
        if ($includeStatus)
            $query->condition('p.status',  1);
        $query->groupBy('period');
        $query->orderBy('period', 'DESC');

        $result = $query->execute()->fetchAllAssoc('period');

        $years = [];
        foreach ($result as $period => $row) {
            $years[(int) $period] = (string) $period;
        }

        return $years;
    }
}
