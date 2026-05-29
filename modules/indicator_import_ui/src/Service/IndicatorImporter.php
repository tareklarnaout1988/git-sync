<?php

declare(strict_types=1);

namespace Drupal\indicator_import_ui\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Import d'indicateurs depuis Excel.
 *
 * Structure attendue :
 *  - Col A : Country (ISO2, ex. "DZ", "AO") -> vocab "cit_countries_information", champ "machine_name" (ou field_iso2 si mappé)
 *  - Col B : Year (en fait TID de la période, ex. 476) -> vocab bundle "period"
 *  - Col C.. : machine_name des indicateurs (ex. "of_population_with_access_to_electricity")
 *  - Cellules C2.. : score (décimal, virgule/point gérées)
 *
 * Content entity cible : "indicator" (custom) avec champs :
 *  - indicator (ER -> taxonomy_term: indicator)      // via field_machine_name
 *  - country   (ER -> taxonomy_term: cit_countries_information) // via ISO2
 *  - period    (ER -> taxonomy_term: period)         // TID lu en Col B
 *  - score     (decimal)
 */
final class IndicatorImporter
{

    public function __construct(
        protected EntityTypeManagerInterface $etm,
        protected FileSystemInterface $fs,
    ) {}

    /**
     * Batch operation.
     * Signature : args positionnels ($uri), puis &$context.
     */
    public static function batchProcess(string $uri, array &$context): void
    {
        if (!$uri) {
            throw new \RuntimeException('Missing file URI.');
        }

        $sandbox = &$context['sandbox'];
        $results = &$context['results'];

        if (!isset($sandbox['initialized'])) {
            $sandbox['initialized'] = TRUE;
            $sandbox['row_index']   = 0;
            $sandbox['col_map']     = []; // 'C' => indicator_tid, 'D' => indicator_tid, ...
            $sandbox['total_cells'] = 0;
            $sandbox['processed_cells'] = 0;
            $sandbox['uri'] = $uri;

            // Caches pour éviter les sur-reqs.
            $sandbox['cache_indicator_by_machine'] = []; // machine => tid
            $sandbox['cache_country_by_iso2']      = []; // ISO2 => tid
            $sandbox['cache_period_checked']       = []; // period_tid => period_tid|null (validé)

            $results['created'] = 0;
            $results['updated'] = 0;
            $results['skipped'] = 0;
            $results['errors']  = []; // <== Accumulation des erreurs ici.
        }

        // Lecture du fichier (stateless entre requêtes).
        $realpath = \Drupal::service('file_system')->realpath($sandbox['uri']);
        $spreadsheet = IOFactory::load($realpath);
        $sheet = $spreadsheet->getActiveSheet();
        $data  = $sheet->toArray(null, true, true, true);

        if (!$data || count($data) === 0) {
            $results['errors'][] = 'Empty worksheet.';
            $context['message']  = t('Empty worksheet.');
            $context['finished'] = 1;
            return;
        }

        // En-têtes : A=Country_iso2, B=Year(TID period), C..=machine names.
        $header = array_shift($data);

        // Construire le col_map (C.. => indicator_tid) une fois.
        if (empty($sandbox['col_map'])) {
            foreach ($header as $col => $labelRaw) {
                if ($col === 'A' || $col === 'B') {
                    continue;
                }
                $label = (string) $labelRaw;
                $machine = self::normalizeMachineName($label);
                if (!$machine) {
                    // Entête vide/non exploitable : on note l’info, mais on continue.
                    $results['errors'][] = "Header column {$col}: empty/invalid indicator label.";
                    continue;
                }

                $indicator_tid = self::indicatorTidByMachine($machine, $sandbox['cache_indicator_by_machine']);
                if ($indicator_tid) {
                    $sandbox['col_map'][$col] = $indicator_tid;
                } else {
                    // Colonne d’indicateur inconnue => le signaler.
                    $results['errors'][] = "Header column {$col}: unknown indicator machine name '{$machine}'.";
                }
            }

            // Compter les cellules non vides pour la progression.
            $total = 0;
            foreach ($data as $r_index => $r) {
                foreach ($sandbox['col_map'] as $col => $_tid) {
                    $cell = $r[$col] ?? null;
                    if ($cell !== null && $cell !== '') {
                        $total++;
                    }
                }
            }
            $sandbox['total_cells'] = $total;

            if (empty($sandbox['col_map'])) {
                $results['errors'][] = 'No valid indicator columns found (C..).';
            }
        }

        // Traiter ~1000 cellules par tick.
        $cell_budget = 1000;

        $storage = \Drupal::entityTypeManager()->getStorage('indicator_score');
        $rowCount = count($data);

        for ($i = $sandbox['row_index']; $i < $rowCount; $i++) {
            $row = $data[$i];

            // Country ISO2 (A)
            $iso2 = strtoupper(trim((string) ($row['A'] ?? '')));

            if ($iso2 === '') {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($i + 2) . ": missing ISO2 code."; // +2 pour tenir compte de l'en-tête (ligne 1) et index 0-based.
                continue;
            }

            $country_tid = self::countryTidByIso2($iso2, $sandbox['cache_country_by_iso2']);
            if (!$country_tid) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($i + 2) . ": unknown country ISO2 '{$iso2}'.";
                continue;
            }

            // Period TID (B)
            $period_raw = trim((string) ($row['B'] ?? ''));
            if ($period_raw === '' || !ctype_digit($period_raw)) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($i + 2) . ": invalid period '{$period_raw}'.";
                continue;
            }
            $period_tid = (int) $period_raw;
            if (!$period_tid) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($i + 2) . ": period TID {$period_raw} not found or not in bundle 'period'.";
                continue;
            }

            // Pour chaque indicateur (C..)
            foreach ($sandbox['col_map'] as $col => $indicator_tid) {
                $rawScore = $row[$col] ?? null;
                if ($rawScore === null || $rawScore === '') {
                    // Cellule vide : pas une erreur fonctionnelle — on n'incrémente pas skipped.
                    continue;
                }

                $score = self::normalizeNumber($rawScore);

                if ($score === null || $score < 0 || $score > 100) {

                    $results['skipped']++;
                    $results['errors'][] = "Row " . ($i + 2) . ", col {$col}: invalid score '{$rawScore}' (must be 0..100).";
                    continue;
                }

                // UPSERT par (indicator_tid, country_tid, period_tid).
                $ids = \Drupal::entityQuery('indicator_score')
                    ->accessCheck(FALSE)
                    ->condition('indicator', $indicator_tid)
                    ->condition('country',   $country_tid)
                    ->condition('period',    $period_tid)
                    ->range(0, 1)
                    ->execute();

                try {
                    if ($ids) {
                        $entity = $storage->load((int) reset($ids));
                        $entity->set('score', $score);
                        $entity->save();
                        $results['updated']++;
                    } else {
                        $entity = $storage->create([
                            'indicator' => $indicator_tid,
                            'country'   => $country_tid,
                            'period'    => $period_tid,
                            'score'     => $score,
                            'status' => 0
                        ]);
                        $entity->save();
                        $results['created']++;
                    }
                } catch (\Throwable $e) {
                    $results['skipped']++;
                    $results['errors'][] = "Row " . ($i + 2) . ", col {$col}: entity save failed - " . $e->getMessage();
                }

                $sandbox['processed_cells']++;
                $cell_budget--;

                if ($cell_budget <= 0) {
                    $sandbox['row_index'] = $i;
                    $context['message'] = t('Processed @done / @total cells', [
                        '@done' => $sandbox['processed_cells'],
                        '@total' => $sandbox['total_cells'],
                    ]);
                    $context['finished'] = $sandbox['total_cells'] > 0
                        ? min(0.99, $sandbox['processed_cells'] / max(1, $sandbox['total_cells']))
                        : 1;
                    return;
                }
            }
        }

        // Terminé.
        $sandbox['row_index'] = $rowCount;
        $context['message'] = t('Done (@created created, @updated updated, @skipped skipped)', [
            '@created' => $results['created'],
            '@updated' => $results['updated'],
            '@skipped' => $results['skipped'],
        ]);
        $context['finished'] = 1;
    }



    public static function batchFinished($success, array $results, array $operations): void
    {
        $logger = \Drupal::logger('indicator_import');

        if ($success) {
            \Drupal::messenger()->addStatus(t(
                'Import finished. Created: @c, Updated: @u, Skipped: @s',
                [
                    '@c' => (int) ($results['created'] ?? 0),
                    '@u' => (int) ($results['updated'] ?? 0),
                    '@s' => (int) ($results['skipped'] ?? 0),
                ]
            ));

            if (!empty($results['errors']) && is_array($results['errors'])) {
                // Escape and format as HTML bullet list.
                $escaped = array_map(static fn($e) => Html::escape($e), $results['errors']);
                $list = '<ul><li>' . implode('</li><li>', $escaped) . '</li></ul>';
                $count = count($escaped);

                // HTML message for the dblog entry.
                $html = Markup::create('<strong>Import skipped entries (' . $count . '):</strong>' . $list);
                $logger->warning($html);

                // Messenger message with clickable link to dblog filtered by this module.
                $url = Url::fromUserInput('/admin/reports/dblog?type[]=indicator_import', ['absolute' => TRUE])->toString();
                $msg = Markup::create(
                    'Some rows were skipped. <a href="' . $url . '">Check the logs for details</a>.'
                );
                \Drupal::messenger()->addWarning($msg);
            } else {
                $logger->info('Import completed successfully with no skipped entries.');
            }
        } else {
            \Drupal::messenger()->addError(t('The import did not complete.'));
            if (!empty($results['errors'])) {
                $escaped = array_map(static fn($e) => Html::escape($e), $results['errors']);
                $list = '<ul><li>' . implode('</li><li>', $escaped) . '</li></ul>';
                $html = Markup::create('<strong>Batch failed with errors:</strong>' . $list);
                $logger->error($html);
            } else {
                $logger->error('Batch failed before completion (no detailed errors captured).');
            }
        }
    }




    /* ========================= Helpers parsing ========================= */

    /** Normalise un libellé en machine_name : minuscules + underscores. */
    protected static function normalizeMachineName(string $label): ?string
    {
        $machine = mb_strtolower(trim($label));
        $machine = preg_replace('/\s+/u', '_', $machine);
        return $machine !== '' ? $machine : null;
    }

    /** "1 234,56" -> 1234.56 | "1,234.56" -> 1234.56 | "98" -> 98.0 */
    protected static function normalizeNumber($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $v = trim($value);
            $v = str_replace(["\u{00A0}", ' '], '', $v);
            if (preg_match('/^\d+,\d+$/u', $v)) {
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
            return is_numeric($v) ? (float) $v : null;
        }
        return null;
    }

    /* ==================== Helpers Taxonomy lookups ===================== */

    /**
     * TID indicateur via field_machine_name (vocab "indicator").
     */
    protected static function indicatorTidByMachine(string $machine, array &$cache): ?int
    {
        if (isset($cache[$machine])) {
            return $cache[$machine];
        }
        $ids = \Drupal::entityQuery('taxonomy_term')
            ->accessCheck(FALSE)
            ->condition('machine_name', $machine)
            ->range(0, 1)
            ->execute();

        $tid = $ids ? (int) reset($ids) : null;
        $cache[$machine] = $tid;
        return $tid;
    }

    /**
     * TID pays via ISO2 (vocab "cit_countries_information").
     */
    protected static function countryTidByIso2(string $iso2, array &$cache): ?int
    {
        $iso2 = strtoupper(trim($iso2));

        if (isset($cache[$iso2])) {
            return $cache[$iso2];
        }

        if ($iso2 === '') {
            $cache[$iso2] = null;
            return null;
        }

        $ids = \Drupal::entityQuery('taxonomy_term')
            ->accessCheck(FALSE)
            ->condition('vid', 'cit_countries_information')
            ->condition('field_citf_iso2_code.value', $iso2)
            ->range(0, 1)
            ->execute();

        $tid = $ids ? (int) reset($ids) : null;
        $cache[$iso2] = $tid;

        return $tid;
    }

    /**
     * Valide qu’un TID existe et appartient au bundle "period".
     */
    // protected static function validatePeriodTid(int $tid, array &$cache): ?int
    // {
    //     if (isset($cache[$tid])) {
    //         return $cache[$tid];
    //     }
    //     if ($tid <= 0) {
    //         $cache[$tid] = null;
    //         return null;
    //     }

    //     $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
    //     if ($term && $term->bundle() === 'period') {
    //         $cache[$tid] = $tid;
    //         return $tid;
    //     }
    //     $cache[$tid] = null;
    //     return null;
    // }
}
