<?php

namespace Drupal\indicator_score\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

class IndicatorRepository
{

    public function __construct(
        private readonly EntityTypeManagerInterface $etm,
        private readonly LanguageManagerInterface $languageManager,
    ) {}

    /**
     *  [tid => 'Côte d’Ivoire', ...] from cit_countries_information.
     */
    public function get_countries(): array
    {
        $out = [];

        // Langue de contenu courante (celle qui compte pour les entités traduites)
        $langcode = $this->languageManager
            ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
            ->getId();

        $terms = $this->etm
            ->getStorage('taxonomy_term')
            ->loadTree('cit_countries_information', 0, NULL, TRUE);

        foreach ($terms as $term) {
            if (!$term->isPublished()) {
                continue;
            }

            // Prendre la traduction si elle existe
            if ($term->hasTranslation($langcode)) {
                $term = $term->getTranslation($langcode);
            }

            $out[(int) $term->id()] = $term->getName();
        }
        return $out;
    }

    public function get_region_by_country_id(): array
    {
        $out = [];

        $langcode = $this->languageManager
            ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
            ->getId();

        $storage = $this->etm->getStorage('taxonomy_term');

        $terms = $storage->loadTree('cit_countries_information', 0, NULL, TRUE);

        $region_field = 'field_africa_region';

        foreach ($terms as $term) {

            if (!$term->isPublished()) {
                continue;
            }

            if ($term->hasTranslation($langcode)) {
                $term = $term->getTranslation($langcode);
            }

            $country_id = (int) $term->id();

            // Si pas de région → Other
            if (!$term->hasField($region_field) || $term->get($region_field)->isEmpty()) {
                $out[$country_id] = 'Other';
                continue;
            }

            // Si c’est une liste (list_string)
            $region_label = trim((string) $term->get($region_field)->value);

            $out[$country_id] = $region_label !== '' ? $region_label : 'Other';
        }

        ksort($out);

        return $out;
    }



    public function get_africa_regions_list(): array
    {
        $field_name  = 'field_africa_region';
        $entity_type = 'taxonomy_term';
        $bundle      = 'cit_countries_information';

        /** @var \Drupal\field\Entity\FieldConfig|null $field_config */
        $field_config = $this->etm
            ->getStorage('field_config')
            ->load($entity_type . '.' . $bundle . '.' . $field_name);

        if (!$field_config) {
            return [];
        }

        $allowed = (array) ($field_config->getSetting('allowed_values') ?? []);
        $out = [];

        foreach ($allowed as $k => $v) {
            // Cas 1: ['north' => 'North', ...]
            if (!is_array($v)) {
                $value = (string) $k;
                $label = (string) $v;
                if ($value !== '') {
                    $out[$value] = $label;
                }
                continue;
            }

            // Cas 2: [ ['value'=>'north','label'=>'North'], ... ]
            $value = (string) ($v['value'] ?? '');
            $label = (string) ($v['label'] ?? $value);
            if ($value !== '') {
                $out[$value] = $label;
            }
        }

        // asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
    /**
     * Variante: renvoie toutes les lignes (score + id), triées par récence.
     * Utile si tu veux faire de l’agrégation (avg/max/etc.).
     *
     * @return array<int, array{entity_id:int, score:float|null}>
     */
    public function listAllScores(int $period, int $country_tid): array
    {
        $storage = $this->etm->getStorage('indicator_score');

        $ids = \Drupal::entityQuery('indicator_score')
            ->condition('period', $period)
            ->condition('country', $country_tid)
            ->sort('changed', 'DESC')
            ->accessCheck(TRUE)
            ->execute();

        if (!$ids) {
            return [];
        }

        $entities = $storage->loadMultiple($ids);

        $out = [];
        foreach ($entities as $id => $e) {
            $score = $e->get('score')->value;
            $ind_tid = (int) $e->get('indicator')->target_id; // TID du terme indicator


            $out[(int) $ind_tid] = [
                'entity_id' => (int) $id,
                // 'ind_tid' => (int) $ind_tid,
                'score' => $score === null || $score === '' ? null : (float) $score

            ];
        }
        return $out;
    }


    /**
     * Arbre Dimension -> Subdimension -> Indicators.
     * - $only_dimension_tid: si renseigné, retourne uniquement cette dimension.
     * - $only_subdimension_tid: si renseigné, retourne la dimension parente mais uniquement cette sous-dimension.
     */
    function buildDimensionTree(
        string $dimension_vocab,
        string $subdimension_vocab,
        string $indicator_vocab,
        string $subdim_to_dim_field,
        string $ind_to_subdim_field,
        ?int $only_dimension_tid = NULL,
        ?int $only_subdimension_tid = NULL
    ): array {

        if ($only_dimension_tid !== NULL && $only_subdimension_tid !== NULL) {
            throw new \InvalidArgumentException(
                'You cannot set both $only_dimension_tid and $only_subdimension_tid at the same time.'
            );
        }
        $tree = [];

        // Comparateur commun: poids asc puis label -1, 0 ou 1.
        $byWeightThenLabel = static function ($a, $b) {
            $cmp = $a->getWeight() <=> $b->getWeight();
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a->label(), $b->label());
        };

        // --------- helpers function locale pour charger une sous-dimension + indicateurs ----------
        $loadIndicatorsForSub = function (Term $sub) use ($indicator_vocab, $ind_to_subdim_field, $byWeightThenLabel) {
            $sub_entry = [
                'label' => $sub->label(),
                'machine_name' => $sub->get('machine_name')->value,
                'weight' => (float) ($sub->get('field_weight')->value ?? 1.0),
                'indicators' => [],
            ];

            $ind_ids = \Drupal::entityQuery('taxonomy_term')
                ->condition('vid', $indicator_vocab)
                ->condition('status', 1)
                ->condition("$ind_to_subdim_field.target_id", (int) $sub->id())
                ->accessCheck(TRUE)
                ->execute();

            if ($ind_ids) {
                $inds = Term::loadMultiple($ind_ids);
                $inds = array_values($inds);
                usort($inds, $byWeightThenLabel);
                foreach ($inds as $ind) {
                    $sub_entry['indicators'][$ind->id()] = [
                        'label' => $ind->label(),
                        'weight' => (float) ($ind->get('field_weight')->value ?? 1.0),
                    ];
                }
            }
            return $sub_entry;
        };

        // ============== CAS dimension ============================
        if ($only_dimension_tid) {
            $dim = Term::load($only_dimension_tid);
            if (!$dim || (int) $dim->get('status')->value !== 1) {
                return [];
            }

            $tree[$dim->id()] = [
                'label' => $dim->label(),
                'weight' => (float) ($dim->get('field_weight')->value ?? 1.0),
                'subdimensions' => [],
            ];


            $sub_ids = \Drupal::entityQuery('taxonomy_term')
                ->condition('vid', $subdimension_vocab)
                ->condition('status', 1)
                ->condition("$subdim_to_dim_field.target_id", (int) $dim->id())
                ->accessCheck(TRUE)
                ->execute();

            if ($sub_ids) {
                $subs = Term::loadMultiple($sub_ids);
                $subs = array_values($subs);
                usort($subs, $byWeightThenLabel);
                foreach ($subs as $sub) {
                    $tree[$dim->id()]['subdimensions'][$sub->id()] = $loadIndicatorsForSub($sub);
                }
            }

            return $tree;
        }


        // ============== CAS sous-dimension =======================
        if ($only_subdimension_tid) {

            $sub = Term::load($only_subdimension_tid);
            if (!$sub || (int) $sub->get('status')->value !== 1) {
                return []; // introuvable/non publié
            }
            // Dimension parente
            $dim_parent_tid = (int) ($sub->get($subdim_to_dim_field)->target_id ?? 0);
            if ($dim_parent_tid <= 0) {
                return []; // pas de parent
            }

            $dim = Term::load($dim_parent_tid);
            if (!$dim || (int) $dim->get('status')->value !== 1) {
                return [];
            }

            // Construire la sous-dimension avec 'dimension' => parent TID
            $sub_entry = [];
            $sub_entry = $loadIndicatorsForSub($sub);
            $tree[$sub->id()] = [
                'dimension' => $dim_parent_tid,
                'label' => $sub->label(),
                'weight' => (float) ($sub->get('field_weight')->value ?? 1.0),
                'indicators' => $sub_entry['indicators'] ?? [],
            ];

            return $tree;
        }



        // ============== CAS par défaut : arbre GLOBAL =============================
        $dimensions = $this->etm
            ->getStorage('taxonomy_term')
            ->loadTree($dimension_vocab, 0, NULL, TRUE);

        // filtrer publiés
        $dimensions = array_values(array_filter($dimensions, fn($t) => (int) $t->get('status')->value === 1));
        // tri global
        usort($dimensions, $byWeightThenLabel);

        foreach ($dimensions as $dim) {
            $tree[$dim->id()] = [
                'label' => $dim->label(),
                'weight' => (float) ($dim->get('field_weight')->value ?? 1.0),
                'subdimensions' => [],
            ];

            $sub_ids = \Drupal::entityQuery('taxonomy_term')
                ->condition('vid', $subdimension_vocab)
                ->condition('status', 1)
                ->condition("$subdim_to_dim_field.target_id", (int) $dim->id())
                ->accessCheck(TRUE)
                ->execute();

            if ($sub_ids) {
                $subs = Term::loadMultiple($sub_ids);
                $subs = array_values($subs);
                usort($subs, $byWeightThenLabel);

                foreach ($subs as $sub) {
                    $tree[$dim->id()]['subdimensions'][$sub->id()] = $loadIndicatorsForSub($sub);
                }
            }
        }

        return $tree;
    }

    public function scoreSubDimensionEngine(int $country, int $period, int $subdimensionTid, ?array $subdimensionTree = NULL): float
    {


        // Vocab & champs de référence.
        $dimension_vocab      = 'dimension';
        $subdimension_vocab   = 'subdimension';
        $indicator_vocab      = 'indicator';
        $subdim_to_dim_field  = 'field_dimension';
        $ind_to_subdim_field  = 'field_subdimension';

        // ---- CAS SOUS-DIMENSION --------------------------------------------------
        if ($subdimensionTid !== NULL) {
            // Arbre réduit: la sous-dimension demandée à la racine, avec ses indicateurs.

            // verifier si subdimensionTree n'est pas vide depuis les paramètres
            if (is_null($subdimensionTree)) {
                $subdimensionTree = $this->buildDimensionTree(
                    $dimension_vocab,
                    $subdimension_vocab,
                    $indicator_vocab,
                    $subdim_to_dim_field,
                    $ind_to_subdim_field,
                    NULL,
                    $subdimensionTid
                );
            }

            // dump('from subdimension', $subdimensionTree);

            if (empty($subdimensionTree[$subdimensionTid]['indicators'])) {
                return 0.0;
            }

            // Map (indicator_tid => poids)
            $indWeights = [];
            foreach ($subdimensionTree[$subdimensionTid]['indicators'] as $iTid => $info) {
                $indWeights[(int) $iTid] = isset($info['weight']) && is_numeric($info['weight']) ? (float)$info['weight'] : 1.0;
            }
            $indicatorIds = array_keys($indWeights);

            // Récupérer scores pour ce pays/période/indicateurs.
            $conn  = \Drupal::database();
            $query = $conn->select('indicator_score', 's')
                ->fields('s', ['indicator', 'score'])
                ->condition('s.country', $country)
                ->condition('s.period',  $period)
                ->condition('s.indicator', $indicatorIds, 'IN');
            if (!(\Drupal::currentUser()->hasRole('psdi_administrator') || \Drupal::currentUser()->hasRole('administrator'))) {
                $query->condition('s.status',  1);
            }
            //
            $rows = $query->execute()->fetchAll();

            // Somme pondérée
            $sum = 0.0;
            foreach ($rows as $r) {
                if ($r->score === NULL || !is_numeric($r->score)) {
                    continue;
                }
                $iTid = (int) $r->indicator;
                $w    = $indWeights[$iTid] ?? 1.0;
                $sum += ((float)$r->score) * $w;
            }
            return $sum; // somme(score_indic × poids_indic) pour la sous-dimension
        }

        // Ni dimension, ni sous-dimension: rien à calculer ici.
        return 0.0;
    }

    public function scoreDimensionEngine(int $country, int $period, int $dimensionTid, ?array $dimTree = NULL): float
    {

        // Vocab & champs de référence.
        $dimension_vocab      = 'dimension';
        $subdimension_vocab   = 'subdimension';
        $indicator_vocab      = 'indicator';
        $subdim_to_dim_field  = 'field_dimension';
        $ind_to_subdim_field  = 'field_subdimension';
        // ---- CAS DIMENSION -------------------------------------------------------
        if ($dimensionTid !== NULL) {

            // dump($country);

            //Charger tree d’UNE dimension (avec SD + IND + weights).
            if (is_null($dimTree)) {
                $dimTree = $this->buildDimensionTree(
                    $dimension_vocab,
                    $subdimension_vocab,
                    $indicator_vocab,
                    $subdim_to_dim_field,
                    $ind_to_subdim_field,
                    $dimensionTid,
                    NULL
                );
            }


            if (empty($dimTree[$dimensionTid])) {
                return 0.0;
            }

            $subdims = $dimTree[$dimensionTid]['subdimensions'] ?? [];

            if (empty($subdims)) {
                return 0.0;
            }

            $total = 1.0;
            foreach ($subdims as $ksd => $vsd) {
                $scoreSubDimension = $this->scoreSubDimensionEngine($country, $period, $ksd,  [$ksd => $vsd]);
                $weightSubDimension = $vsd['weight'];
                if ($scoreSubDimension <= 0) {
                    continue;
                }

                $total *= $scoreSubDimension ** $weightSubDimension;
            }

            return $total;
        }


        return 0.0;
    }

    public function getExistingIndiscatorScoreYear(?bool $includeStatus = TRUE)
    {
        $connection = Database::getConnection();

        $query = $connection->select('indicator_score', 's');
        $query->addField('s', 'period');
        $query->addExpression('COUNT(period)', 'period_count');
        if ($includeStatus)
            $query->condition('s.status',  1);

        $query->groupBy('period');
        $query->orderBy('period', 'DESC');

        $result = $query->execute()->fetchAllAssoc('period');

        $years = [];
        foreach ($result as $period => $row) {
            $years[(int) $period] = (string) $period;
        }

        return $years;
    }


    public function getValidIndicatorTerms(): array
    {
        $dimension_vocab      = 'dimension';
        $subdimension_vocab   = 'subdimension';
        $indicator_vocab      = 'indicator';
        $subdim_to_dim_field  = 'field_dimension';
        $ind_to_subdim_field  = 'field_subdimension';

        $dimTree = $this->buildDimensionTree(
            $dimension_vocab,
            $subdimension_vocab,
            $indicator_vocab,
            $subdim_to_dim_field,
            $ind_to_subdim_field,
            NULL,
            NULL
        );

        $validIndicatorIds = [];
        foreach ($dimTree as $dimension) {
            if (empty($dimension['subdimensions'])) {
                continue;
            }
            foreach ($dimension['subdimensions'] as $sub) {
                if (empty($sub['indicators'])) {
                    continue;
                }
                foreach ($sub['indicators'] as $ind_tid => $info) {
                    $validIndicatorIds[(int) $ind_tid] = TRUE;
                }
            }
        }

        return $validIndicatorIds;
    }





    // public function getCoverageByPeriod(): array
    // {
    //     $connection = Database::getConnection();

    //     $validIndicatorIds  = $this->getValidIndicatorTerms();
    //     if (count($validIndicatorIds) === 0) {
    //         return [];
    //     }
    //     $totalIndicators = count($validIndicatorIds);

    //     $countries = $this->get_countries();
    //     $countCountries = count($countries);
    //     if (empty($countries)) {
    //         return [];
    //     }
    //     $existing_periods = $this->getExistingIndiscatorScoreYear(FALSE);

    //     $period_ids = array_keys($existing_periods);

    //     if (empty($period_ids)) {
    //         return [];
    //     }

    //     $rows = [];
    //     $validIndicatorIdList = array_keys($validIndicatorIds);

    //     foreach ($period_ids as $period_id) {
    //         $totalUnpublished = 0;
    //         $totalCountFilled = 0;
    //         $coveragePct = 0;
    //         foreach ($countries as $idCountry => $nameCountry) {
    //             $period_id = (int) $period_id;
    //             $query = $connection->select('indicator_score', 's');
    //             $query->fields('s', ['indicator']);
    //             $query->condition('s.period', $period_id);
    //             $query->condition('s.country', $idCountry);
    //             $query->condition('s.indicator', $validIndicatorIdList, 'IN');
    //             $query->distinct();
    //             $filledIds   = $query->execute()->fetchCol();

    //             $query_unpublished = $connection->select('indicator_score', 's');
    //             $query_unpublished->fields('s', ['indicator']);
    //             $query_unpublished->condition('s.period', $period_id);
    //             $query_unpublished->condition('s.country', $idCountry);
    //             $query_unpublished->condition('s.status', 0);
    //             $query_unpublished->condition('s.indicator', $validIndicatorIdList, 'IN');
    //             $query_unpublished->distinct();
    //             $unpublishedIds   = $query_unpublished->execute()->fetchCol();

    //             $unpublishedCount = count($unpublishedIds);
    //             $filledCount = count($filledIds);
    //             $totalCountFilled += $filledCount;
    //             $totalUnpublished += $unpublishedCount;
    //         }




    //         $coveragePct = round(($totalCountFilled / ($totalIndicators * $countCountries)) * 100, 2);

    //         // On utilise la string retournée par getExistingIndiscatorScoreYear()
    //         $periodLabel = $existing_periods[$period_id] ?? (string) $period_id;

    //         $rows[] = [
    //             'period_id'         => $period_id,
    //             'period_name'       => $periodLabel,
    //             'filled_indicators' => $totalCountFilled,
    //             'total_indicators'  => "$totalIndicators * $countCountries = " . $totalIndicators * $countCountries,
    //             'coverage_pct'      => $coveragePct,
    //             'total_unpublished'      => $totalUnpublished

    //         ];
    //     }

    //     // 4) Trier par année décroissante.
    //     usort($rows, static function (array $a, array $b): int {
    //         return $b['period_id'] <=> $a['period_id'];
    //     });


    //     return $rows;
    // }



    public function getCoverageByPeriod(): array
    {
        $connection = Database::getConnection();


        $validIndicatorMap = $this->getValidIndicatorTerms();   // [tid => TRUE]
        $validIndicatorIds = array_map('intval', array_keys($validIndicatorMap));
        if (empty($validIndicatorIds)) {
            return [];
        }
        $totalIndicators = count($validIndicatorIds);

        $countries      = $this->get_countries();
        $countCountries = count($countries);
        if ($countCountries === 0) {
            return [];
        }

        $existing_periods = $this->getExistingIndiscatorScoreYear(FALSE);
        if (empty($existing_periods)) {
            return [];
        }
        $period_ids = array_map('intval', array_keys($existing_periods));

        //  aggregated query :
        //    - filled_count      = nb (country, indicator) distincts with status = 1
        //    - unpublished_count = nb (country, indicator) distincts avec status = 0
        $query = $connection->select('indicator_score', 's');
        $query->addField('s', 'period');

        $query->addExpression(
            "COUNT(DISTINCT CASE WHEN s.status = 1 THEN CONCAT(s.country, '-', s.indicator) END)",
            'published_count'
        );
        $query->addExpression(
            "COUNT(DISTINCT CASE WHEN s.status = 0 THEN CONCAT(s.country, '-', s.indicator) END)",
            'unpublished_count'
        );

        $query->condition('s.period', $period_ids, 'IN');
        $query->condition('s.indicator', $validIndicatorIds, 'IN');
        $query->groupBy('s.period');

        /** @var array<int,\stdClass> $result */
        $result = $query->execute()->fetchAllAssoc('period');

        $rows = [];
        $total_possible = $totalIndicators * $countCountries;

        foreach ($period_ids as $period_id) {
            $period_id = (int) $period_id;

            $published_count = isset($result[$period_id]) ? (int) $result[$period_id]->published_count : 0;
            $unpublished_count = isset($result[$period_id]) ? (int) $result[$period_id]->unpublished_count : 0;

            $coveragePct = $total_possible > 0
                ? round((($published_count + $unpublished_count) / $total_possible) * 100, 2)
                : 0.0;

            $periodLabel = $existing_periods[$period_id] ?? (string) $period_id;

            $rows[] = [
                'period_id'         => $period_id,
                'period_name'       => $periodLabel,
                'published_indicators' => $published_count,
                'total_possible' => $total_possible,
                'total_indicators'  => "$totalIndicators * $countCountries = " . $total_possible,
                'coverage_pct'      => $coveragePct,
                'unpublished_indicators' => $unpublished_count,
            ];
        }

        // 6) Trier par année décroissante (sécurité).
        usort($rows, static function (array $a, array $b): int {
            return $b['period_id'] <=> $a['period_id'];
        });

        return $rows;
    }


    /**
     * Indicateurs manquants pour un pays et une période.
     *
     */
    public function getMissingIndicatorsForCountryPeriod(
        int $country_tid,
        int $period_tid
    ): array {
        // 1) Recréer la liste des indicateurs "valides" via le tree.
        $dimension_vocab      = 'dimension';
        $subdimension_vocab   = 'subdimension';
        $indicator_vocab      = 'indicator';
        $subdim_to_dim_field  = 'field_dimension';
        $ind_to_subdim_field  = 'field_subdimension';

        $dimTree = $this->buildDimensionTree(
            $dimension_vocab,
            $subdimension_vocab,
            $indicator_vocab,
            $subdim_to_dim_field,
            $ind_to_subdim_field,
            NULL,
            NULL
        );

        $validIndicatorIds = [];
        foreach ($dimTree as $dimension) {
            if (empty($dimension['subdimensions'])) {
                continue;
            }
            foreach ($dimension['subdimensions'] as $sub) {
                if (empty($sub['indicators'])) {
                    continue;
                }
                foreach ($sub['indicators'] as $ind_tid => $info) {
                    $validIndicatorIds[(int) $ind_tid] = TRUE;
                }
            }
        }

        if (empty($validIndicatorIds)) {
            return [];
        }

        // 2) Scores existants pour ce pays + cette période.
        $scoresByIndicator = $this->listAllScores($period_tid, $country_tid);
        $filledIds = array_keys($scoresByIndicator);

        // 3) Indicateurs manquants = ceux du tree qui ne sont pas dans $filledIds
        $allIndicatorIds = array_keys($validIndicatorIds);
        $missingIds = array_diff($allIndicatorIds, $filledIds);

        if (empty($missingIds)) {
            return [];
        }

    // 4) Charger les termes pour afficher les labels.
        /** @var \Drupal\taxonomy\Entity\Term[] $terms */
        $terms = Term::loadMultiple($missingIds);

        $missing = [];
        foreach ($terms as $tid => $term) {
            $missing[(int) $tid] = [
                'tid'   => (int) $tid,
                'label' => $term->label(),
            ];
        }

        return $missing;
    }

    /**
     * Simple and compllete Arbre Dimension -> Subdimension -> Indicators.
     */

    public function buildSimpleCompletedDimensionTree(
        string $dimension_vocab,
        string $subdimension_vocab,
        string $indicator_vocab,
        string $subdim_to_dim_field,
        string $ind_to_subdim_field,
    ): array {

        $tree = [];

        // Langue sélectionnée (via switcher)
        $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

        // Traduction d'un term selon $langcode
        $tr = static function (Term $term) use ($langcode): Term {
            return $term->hasTranslation($langcode) ? $term->getTranslation($langcode) : $term;
        };

        // machine_name (fallback si vide)
        $key = static function (Term $t): string {
            $m = (string) ($t->get('machine_name')->value ?? '');
            return $m !== '' ? $m : ('tid_' . (int) $t->id());
        };

        // label traduit
        $label = static function (Term $t) use ($tr): string {
            return $tr($t)->label();
        };

        // tri alpha selon label traduit
        $byLabel = static function (Term $a, Term $b) use ($label): int {
            return strcasecmp($label($a), $label($b));
        };

        // Load indicators for a subdimension
        $loadIndicatorsForSub = function (Term $sub) use ($indicator_vocab, $ind_to_subdim_field, $byLabel, $key, $label): array {
            $out = [
                'label' => $label($sub),
                'indicators' => [],
            ];

            $ind_ids = \Drupal::entityQuery('taxonomy_term')
                ->condition('vid', $indicator_vocab)
                ->condition('status', 1)
                ->condition("$ind_to_subdim_field.target_id", (int) $sub->id())
                ->accessCheck(TRUE)
                ->execute();

            if ($ind_ids) {
                $inds = Term::loadMultiple($ind_ids);
                $inds = array_values($inds);
                usort($inds, $byLabel);

                foreach ($inds as $ind) {
                    $out['indicators'][$key($ind)] = [
                        'label' => $label($ind),
                    ];
                }
            }

            return $out;
        };

        /** @var \Drupal\taxonomy\Entity\Term[] $dimensions */
        $dimensions = $this->etm
            ->getStorage('taxonomy_term')
            ->loadTree($dimension_vocab, 0, NULL, TRUE);

        // publiés uniquement
        $dimensions = array_values(array_filter($dimensions, fn(Term $t) => (int) $t->get('status')->value === 1));
        usort($dimensions, $byLabel);

        foreach ($dimensions as $dim) {
            $dimKey = $key($dim);

            $tree[$dimKey] = [
                'label' => $label($dim),
                'subdimensions' => [],
            ];

            $sub_ids = \Drupal::entityQuery('taxonomy_term')
                ->condition('vid', $subdimension_vocab)
                ->condition('status', 1)
                ->condition("$subdim_to_dim_field.target_id", (int) $dim->id())
                ->accessCheck(TRUE)
                ->execute();

            if (!$sub_ids) {
                continue;
            }

            $subs = Term::loadMultiple($sub_ids);
            $subs = array_values($subs);
            usort($subs, $byLabel);

            foreach ($subs as $sub) {
                $subKey = $key($sub);

                $tree[$dimKey]['subdimensions'][$subKey] = [
                    'label' => $label($sub),
                    'indicators' => $loadIndicatorsForSub($sub)['indicators'],
                ];
            }
        }

        return $tree;
    }

    public function getIndicatorScoreByCountryYear(int $country, int $period, int $indicatorId): float
    {
        if ($indicatorId !== NULL) {

            $conn  = \Drupal::database();
            $query = $conn->select('indicator_score', 's')
                ->fields('s', ['indicator', 'score'])
                ->condition('s.country', $country)
                ->condition('s.period',  $period)
                ->condition('s.indicator', $indicatorId, '=');
            if (!(\Drupal::currentUser()->hasRole('psdi_administrator') || \Drupal::currentUser()->hasRole('administrator'))) {
                $query->condition('s.status',  1);
            }
            //
            $rows = $query->execute()->fetch();

            if ($rows && isset($rows->score) && is_numeric($rows->score)) {
                return (float)$rows->score;
            }
        }

        return 0.0;
    }
}
