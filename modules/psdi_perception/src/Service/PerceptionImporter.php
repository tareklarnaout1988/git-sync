<?php

declare(strict_types=1);

namespace Drupal\psdi_perception\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Import de scores de perception depuis Excel.
 *
 * Structure attendue :
 *  - Col A : Country (ISO2, ex. "DZ", "AO") -> vocab "cit_countries_information"
 *  - Col B : Year (TID de la période, ex. 476) -> vocab "period"
 *  - Col C : score de perception (0..100)
 *
 * Content entity cible : "psdi_perception" avec champs :
 *  - country (ER -> taxonomy_term: cit_countries_information)
 *  - period  (ER -> taxonomy_term: period)
 *  - score   (decimal)  // à adapter si ton champ a un autre nom
 */
final class PerceptionImporter {

  public function __construct(
    protected EntityTypeManagerInterface $etm,
    protected FileSystemInterface $fs,
  ) {}

  /**
   * Batch operation.
   * Signature : args positionnels ($uri), puis &$context.
   */
  public static function batchProcess(string $uri, array &$context): void {
    if (!$uri) {
      throw new \RuntimeException('Missing file URI.');
    }

    $sandbox = &$context['sandbox'];
    $results = &$context['results'];

    if (!isset($sandbox['initialized'])) {
      $sandbox['initialized'] = TRUE;
      $sandbox['row_index']   = 0;
      $sandbox['uri']         = $uri;

      // Caches pour éviter les sur-reqs.
      $sandbox['cache_country_by_iso2'] = []; // ISO2 => tid

      $results['created'] = 0;
      $results['updated'] = 0;
      $results['skipped'] = 0;
      $results['errors']  = []; // <== Accumulation des erreurs ici.
    }

    // Lecture du fichier (stateless entre requêtes).
    $realpath    = \Drupal::service('file_system')->realpath($sandbox['uri']);
    $spreadsheet = IOFactory::load($realpath);
    $sheet       = $spreadsheet->getActiveSheet();
    $data        = $sheet->toArray(null, true, true, true);

    if (!$data || count($data) === 0) {
      $results['errors'][] = 'Empty worksheet.';
      $context['message']  = t('Empty worksheet.');
      $context['finished'] = 1;
      return;
    }

    // En-tête en première ligne.
    $header = array_shift($data);

    // Traiter ~500 lignes par tick.
    $row_budget = 500;

    // ⚠ entité cible : psdi_perception
    $storage  = \Drupal::entityTypeManager()->getStorage('psdi_perception');
    $rowCount = count($data);

    for ($i = $sandbox['row_index']; $i < $rowCount; $i++) {
      $row = $data[$i];

      // Country ISO2 (A)
      $iso2 = strtoupper(trim((string) ($row['A'] ?? '')));

      if ($iso2 === '') {
        $results['skipped']++;
        $results['errors'][] = "Row " . ($i + 2) . ": missing ISO2 code.";
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

      // Score de perception (C)
      $rawScore = $row['C'] ?? null;
      if ($rawScore === null || $rawScore === '') {
        $results['skipped']++;
        $results['errors'][] = "Row " . ($i + 2) . ": empty perception score.";
        continue;
      }

      $score = self::normalizeNumber($rawScore);
      if ($score === null || $score < 0 || $score > 100) {
        $results['skipped']++;
        $results['errors'][] = "Row " . ($i + 2) . ": invalid score '{$rawScore}' (must be 0..100).";
        continue;
      }

      // UPSERT par (country, period) uniquement.
      // ⚠ adapte le nom du champ 'score' si nécessaire.
      $ids = \Drupal::entityQuery('psdi_perception')
        ->accessCheck(FALSE)
        ->condition('country', $country_tid)
        ->condition('period',  $period_tid)
        ->range(0, 1)
        ->execute();

      try {
        if ($ids) {
          $entity = $storage->load((int) reset($ids));
          $entity->set('Perception score', $score);
          $entity->save();
          $results['updated']++;
        }
        else {
          $entity = $storage->create([
            'country' => $country_tid,
            'period'  => $period_tid,
            'score'   => $score,
            'status' => 0
          ]);
          $entity->save();
          $results['created']++;
        }
      }
      catch (\Throwable $e) {
        $results['skipped']++;
        $results['errors'][] = "Row " . ($i + 2) . ": entity save failed - " . $e->getMessage();
      }

      $row_budget--;
      if ($row_budget <= 0) {
        $sandbox['row_index'] = $i + 1;
        $context['message'] = t('Processed @done / @total rows', [
          '@done'  => $sandbox['row_index'],
          '@total' => $rowCount,
        ]);
        $context['finished'] = min(0.99, $sandbox['row_index'] / max(1, $rowCount));
        return;
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

  /**
   * Callback de fin de batch.
   */
  public static function batchFinished($success, array $results, array $operations): void {
    $logger = \Drupal::logger('psdi_perception_import');

    if ($success) {
      \Drupal::messenger()->addStatus(t(
        'Perception import finished. Created: @c, Updated: @u, Skipped: @s',
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

        // Messenger message with clickable link to dblog filtré sur ce type.
        $url = Url::fromUserInput('/admin/reports/dblog?type[]=psdi_perception_import', ['absolute' => TRUE])->toString();
        $msg = Markup::create(
          'Some rows were skipped. <a href="' . $url . '">Check the logs for details</a>.'
        );
        \Drupal::messenger()->addWarning($msg);
      }
      else {
        $logger->info('Perception import completed successfully with no skipped entries.');
      }
    }
    else {
      \Drupal::messenger()->addError(t('The perception import did not complete.'));
      if (!empty($results['errors'])) {
        $escaped = array_map(static fn($e) => Html::escape($e), $results['errors']);
        $list = '<ul><li>' . implode('</li><li>', $escaped) . '</li></ul>';
        $html = Markup::create('<strong>Batch failed with errors:</strong>' . $list);
        $logger->error($html);
      }
      else {
        $logger->error('Batch failed before completion (no detailed errors captured).');
      }
    }
  }

  /* ========================= Helpers parsing ========================= */

  /** "1 234,56" -> 1234.56 | "1,234.56" -> 1234.56 | "98" -> 98.0 */
  protected static function normalizeNumber($value): ?float {
    if (is_numeric($value)) {
      return (float) $value;
    }
    if (is_string($value)) {
      $v = trim($value);
      $v = str_replace(["\u{00A0}", ' '], '', $v);
      if (preg_match('/^\d+,\d+$/u', $v)) {
        $v = str_replace(',', '.', $v);
      }
      else {
        $v = str_replace(',', '', $v);
      }
      return is_numeric($v) ? (float) $v : null;
    }
    return null;
  }


  /**
   * TID pays via ISO2 (vocab "cit_countries_information").
   */
  protected static function countryTidByIso2(string $iso2, array &$cache): ?int {
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

}
