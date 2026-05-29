<?php

namespace Drupal\psdi_export\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\indicator_score\Service\IndicatorRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Drupal\psdi_dashboard\Service\DimensionRepository;
use Drupal\psdi_perception\Service\PerceptionRepository;

final class PsdiExportController extends ControllerBase
{

  /** @var \Drupal\indicator_score\Service\IndicatorRepository */
  protected IndicatorRepository $indicatorRepository;


  protected DimensionRepository $dimensionRepository;
  protected PerceptionRepository $perceptionRepository;

  public function __construct(
    IndicatorRepository $indicatorRepository,
    DimensionRepository $dimensionRepository,
    PerceptionRepository $perceptionRepository
  ) {
    $this->indicatorRepository = $indicatorRepository;
    $this->dimensionRepository = $dimensionRepository;
    $this->perceptionRepository = $perceptionRepository;
  }

  public static function create(ContainerInterface $container): self
  {
    return new self(
      $container->get('indicator_score.indicator_repository'),
      $container->get('psdi_dashboard.dimension_repository'),
      $container->get('psdi_perception.perception_repository')
    );
  }

  // export function Only subdimensions by dimensions
  public function exportSubdimensionXlsx(int $period, string $dimension_machine_name, string $format = 'xlsx', Request $request): Response
  {
    $dimensions = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'dimension',
        'machine_name' => $dimension_machine_name,
      ]);

    if (empty($dimensions)) {
      return $this->emptyXlsx('No dimension found');
    }

    $dimension     = reset($dimensions);
    $dimensionId   = (int) $dimension->id();
    $dimensionName = $dimension->label();


    $rawRows = $this->dimensionRepository
      ->getDataSubdimensionScores($period, $dimension_machine_name);

    if (empty($rawRows)) {
      return $this->emptyXlsx('No data from DimensionRepository');
    }

    $countries     = [];
    $subdimensions = [];
    $scores        = [];

    foreach ($rawRows as $item) {
      $country      = $item['country'];
      $subdimension = $item['subdimension'];
      $score        = $item['score'];

      $countryId   = (int) $country['id'];
      $countryName = $country['name'];
      $subMachine  = $subdimension['machine_name'];
      $subLabel    = $subdimension['label'];


      $countries[$countryId]       = $countryName;
      $subdimensions[$subMachine]  = $subLabel;

      if (is_numeric($score)) {
        $scores[$countryId][$subMachine] = round((float) $score, 2);
      }
    }


    if (!$countries || !$subdimensions) {
      return $this->emptyXlsx('No countries or subdimensions after mapping');
    }


    $trees = $this->indicatorRepository->buildDimensionTree(
      'dimension',
      'subdimension',
      'indicator',
      'field_dimension',
      'field_subdimension',
      $dimensionId
    );

    if (empty($trees)) {
      return $this->emptyXlsx('No tree for dimension');
    }

    // $rows for excel
    $rows = [];

    $subColLabels = array_values($subdimensions);
    $header       = array_merge(['Country'], $subColLabels);
    $header[]     = $dimensionName;
    $rows[]       = $header;

    // Lignes pays
    foreach ($countries as $countryId => $countryName) {
      $line = [$countryName];

      // Colonnes sous-dimensions
      foreach ($subdimensions as $subMachine => $subLabel) {
        $line[] = $scores[$countryId][$subMachine] ?? '';
      }

      // Score de dimension
      $dimensionScore = $this->indicatorRepository->scoreDimensionEngine(
        $countryId,
        $period,
        $dimensionId,
        $trees
      );
      $line[] = is_numeric($dimensionScore) ? round((float) $dimensionScore, 2) : '';

      $rows[] = $line;
    }
    if ($format === 'csv') {
      // 5) CSV output (UTF-8 BOM pour Excel)
      $fh = fopen('php://temp', 'r+');

      // BOM => Excel ouvre bien en UTF-8
      fwrite($fh, "\xEF\xBB\xBF");

      // séparateur ; si tu veux (Excel FR), sinon garde ,
      $delimiter = ','; // ou ';'

      foreach ($rows as $r) {
        fputcsv($fh, $r, $delimiter);
      }

      rewind($fh);
      $csv = stream_get_contents($fh);
      fclose($fh);

      $filename = sprintf('psdi_%s_%d.csv', $dimension_machine_name, $period);

      return new Response($csv, 200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Cache-Control' => 'max-age=0',
      ]);
    }
    // 5) Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
      ->setCreator('PSDI Export')
      ->setTitle(sprintf('PSDI %s - %d', $dimension_machine_name, $period));

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Countries x Subdims');

    // Dump d’un coup
    $sheet->fromArray($rows, null, 'A1');

    // ==== STYLE DES EN-TÊTES ====

    // Coordonnées globales
    $totalCols  = count($header);
    $lastColLtr = Coordinate::stringFromColumnIndex($totalCols);
    $lastRow    = count($rows);

    // Style NORMAL pour toute la ligne 1
    $sheet->getStyle("A1:{$lastColLtr}1")->getFont()
      ->setBold(true)
      ->setSize(11);

    // Style SPÉCIAL pour la colonne Dimension (dernière colonne)
    $dimensionColIndex  = count($header);
    $dimensionColLetter = Coordinate::stringFromColumnIndex($dimensionColIndex);

    $styleDimHeader = $sheet->getStyle($dimensionColLetter . '1');
    $styleDimHeader->getFont()
      ->setBold(true)
      ->setSize(13)
      ->setName('Calibri')      // police personnalisée
      ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1F4E79')); // Bleu foncé

    // On veut styliser de la ligne 2 à la dernière ligne
    $startRow = 2;
    $endRow   = $lastRow;

    $sheet->getStyle("{$dimensionColLetter}{$startRow}:{$dimensionColLetter}{$endRow}")
      ->getFont()->setBold(true);

    // ==== AUTRES STYLES ====

    // Colonne A large
    $sheet->getColumnDimension('A')->setWidth(32);
    $sheet->getStyle("A1:A{$lastRow}")
      ->getAlignment()->setWrapText(true);

    // Autosize colonnes B..fin
    for ($i = 2; $i <= $totalCols; $i++) {
      $col = Coordinate::stringFromColumnIndex($i);
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Freeze + filtre
    $sheet->setAutoFilter("A1:{$lastColLtr}1");
    $sheet->freezePane('B2');

    // Alignement & format
    if ($totalCols >= 2 && $lastRow >= 2) {
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
    }

    // 6) Réponse
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $bin = ob_get_clean();

    $filename = sprintf('psdi_%s_%d.xlsx', $dimension_machine_name, $period);

    return new Response($bin, 200, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'max-age=0',
    ]);
  }

  // export function all dimensions and PSDI
  public function exportDimensionsXlsx(int $period, string $format = 'xlsx', Request $request): Response
  {
    // 1) Récupération des scores via le service
    $rawRows = $this->dimensionRepository
      ->getDataDimensionScores($period, TRUE, FALSE); // TRUE => calcule aussi le PSDI

    if (empty($rawRows['dimensions'])) {
      return $this->emptyXlsx('No dimension data from DimensionRepository');
    }

    $countries    = [];
    $dimensions   = [];
    $scores       = [];
    $psdiScores   = [];

    // 2) Mapping des données de dimensions
    foreach ($rawRows['dimensions'] as $item) {
      // item = [
      //   'country' => ['id' => X, 'name' => 'Algeria'],
      //   'dimension' => ['label' => 'Light up...', 'machine_name' => 'light_up_and_power_africa'],
      //   'score' => 15.77,
      // ]

      $country    = $item['country'];
      $dimension  = $item['dimension'];
      $score      = $item['score'];

      $countryId   = (int) $country['id'];
      $countryName = $country['name'];

      $dimMachine  = $dimension['machine_name'];
      $dimLabel    = $dimension['label'];

      // Liste unique des pays & dimensions
      $countries[$countryId]     = $countryName;
      $dimensions[$dimMachine]   = $dimLabel;

      if (is_numeric($score)) {
        $scores[$countryId][$dimMachine] = round((float) $score, 2);
      }
    }

    if (!$countries || !$dimensions) {
      return $this->emptyXlsx('No countries or dimensions after mapping');
    }

    // 3) Mapping des scores PSDI (si présents)
    if (!empty($rawRows['psdi'])) {
      foreach ($rawRows['psdi'] as $psdiRow) {
        // psdiRow = [
        //   'country' => ['id' => X, 'name' => 'Algeria'],
        //   'psdi' => 23.45,
        //   'ignored_dimensions' => [...],
        // ]
        $cId  = (int) $psdiRow['country']['id'];
        $psdi = $psdiRow['psdi'];

        $psdiScores[$cId] = is_numeric($psdi) ? round((float) $psdi, 2) : null;
      }
    }

    // 4) Construction des lignes pour Excel
    $rows = [];

    // En-tête : Country + dimensions + PSDI
    $dimColLabels = array_values($dimensions);
    $header       = array_merge(['Country'], $dimColLabels);
    $header[]     = 'PSDI';
    $rows[]       = $header;

    // Lignes par pays
    // On peut trier par nom de pays si tu veux un ordre stable
    ksort($countries);

    foreach ($countries as $countryId => $countryName) {
      $line = [$countryName];

      // Colonnes dimensions
      foreach ($dimensions as $dimMachine => $dimLabel) {
        $line[] = $scores[$countryId][$dimMachine] ?? '';
      }

      // Colonne PSDI
      $line[] = $psdiScores[$countryId] ?? '';

      $rows[] = $line;
    }

    if ($format === 'csv') {
      // 5) CSV output (UTF-8 BOM pour Excel)
      $fh = fopen('php://temp', 'r+');

      // BOM => Excel ouvre bien en UTF-8
      fwrite($fh, "\xEF\xBB\xBF");

      // séparateur ; si tu veux (Excel FR), sinon garde ,
      $delimiter = ','; // ou ';'

      foreach ($rows as $r) {
        fputcsv($fh, $r, $delimiter);
      }

      rewind($fh);
      $csv = stream_get_contents($fh);
      fclose($fh);

      $filename = sprintf('psdi_dimensions_%d.csv', $period);

      return new Response($csv, 200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Cache-Control' => 'max-age=0',
      ]);
    }

    // 5) Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
      ->setCreator('PSDI Export')
      ->setTitle(sprintf('PSDI dimensions - %d', $period));

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Countries x Dimensions');

    // Dump d’un coup
    $sheet->fromArray($rows, null, 'A1');

    // ==== STYLE DES EN-TÊTES ====
    $totalCols  = count($header);
    $lastColLtr = Coordinate::stringFromColumnIndex($totalCols);
    $lastRow    = count($rows);

    // Ligne d’en-tête en gras
    $sheet->getStyle("A1:{$lastColLtr}1")->getFont()
      ->setBold(true)
      ->setSize(11);

    // Style spécial pour la colonne PSDI (dernière colonne)
    $psdiColIndex  = $totalCols;
    $psdiColLetter = Coordinate::stringFromColumnIndex($psdiColIndex);

    $stylePsdiHeader = $sheet->getStyle($psdiColLetter . '1');
    $stylePsdiHeader->getFont()
      ->setBold(true)
      ->setSize(13)
      ->setName('Calibri')
      ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1F4E79')); // Bleu foncé

    // On rend la colonne PSDI en gras pour les lignes de données aussi
    if ($lastRow >= 2) {
      $sheet->getStyle("{$psdiColLetter}2:{$psdiColLetter}{$lastRow}")
        ->getFont()->setBold(true);
    }

    // ==== AUTRES STYLES ====

    // Colonne A large (pays)
    $sheet->getColumnDimension('A')->setWidth(32);
    $sheet->getStyle("A1:A{$lastRow}")
      ->getAlignment()->setWrapText(true);

    // Autosize colonnes B..fin
    for ($i = 2; $i <= $totalCols; $i++) {
      $col = Coordinate::stringFromColumnIndex($i);
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Freeze + filtre
    $sheet->setAutoFilter("A1:{$lastColLtr}1");
    $sheet->freezePane('B2');

    // Alignement & format numérique
    if ($totalCols >= 2 && $lastRow >= 2) {
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
    }

    // 6) Réponse
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $bin = ob_get_clean();

    $filename = sprintf('psdi_dimensions_%d.xlsx', $period);

    return new Response($bin, 200, [
      'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control'       => 'max-age=0',
    ]);
  }

  // export function all dimensions with subdimensionsand PSDI
  public function exportDimensionsAndDimensionsXlsx(int $period, string $format = 'xlsx', Request $request): Response
  {
    $rawRows = $this->dimensionRepository
      ->getDataDimensionScores($period, TRUE, TRUE);

    if (empty($rawRows['dimensions'])) {
      return $this->emptyXlsx('No dimension data from DimensionRepository');
    }

    $countries      = [];
    $dimensions     = [];
    $subdimensions  = [];
    $dimScores      = [];
    $subdimScores   = [];
    $psdiScores     = [];

    // Map dimensions + subdimensions
    foreach ($rawRows['dimensions'] as $item) {

      $country    = $item['country'];
      $dimension  = $item['dimension'];
      $score      = $item['score'];
      $subdims    = $item['subdimensions'] ?? [];

      $countryId   = (int) $country['id'];
      $countryName = $country['name'];

      $dimMachine  = $dimension['machine_name'];
      $dimLabel    = $dimension['label'];

      $countries[$countryId]   = $countryName;
      $dimensions[$dimMachine] = $dimLabel;

      if (is_numeric($score)) {
        $dimScores[$countryId][$dimMachine] = round((float) $score, 2);
      }

      foreach ($subdims as $sd) {
        $subMachine = $sd['machine_name'] ?? null;
        $subLabel   = $sd['label'] ?? $subMachine;
        $subScore   = $sd['score'] ?? null;

        if (!$subMachine) continue;

        $subdimensions[$dimMachine][$subMachine] = $subLabel;

        if (is_numeric($subScore)) {
          $subdimScores[$countryId][$dimMachine][$subMachine] =
            round((float) $subScore, 2);
        }
      }
    }

    if (!$countries || !$dimensions) {
      return $this->emptyXlsx('No countries or dimensions after mapping');
    }

    //Map PSDI
    if (!empty($rawRows['psdi'])) {
      foreach ($rawRows['psdi'] as $psdiRow) {
        $cId  = (int) $psdiRow['country']['id'];
        $psdi = $psdiRow['psdi'];

        $psdiScores[$cId] =
          is_numeric($psdi) ? round((float) $psdi, 2) : null;
      }
    }

    // Nuild header : Country | PSDI | Dimensions + subdimensions
    $rows   = [];
    $header = ['Country', 'PSDI'];

    $dimensionFirstColIndex = [];
    $currentColIndex = 3;

    foreach ($dimensions as $dimMachine => $dimLabel) {
      $header[] = $dimLabel;
      $dimensionFirstColIndex[$dimMachine] = $currentColIndex;
      $currentColIndex++;

      if (!empty($subdimensions[$dimMachine])) {
        foreach ($subdimensions[$dimMachine] as $subMachine => $subLabel) {
          $header[] = $dimLabel . ' - ' . $subLabel;
          $currentColIndex++;
        }
      }
    }

    $rows[] = $header;

    // Rows
    ksort($countries);

    foreach ($countries as $countryId => $countryName) {
      $line = [$countryName];

      // PSDI
      $line[] = $psdiScores[$countryId] ?? '';

      foreach ($dimensions as $dimMachine => $dimLabel) {
        $line[] = $dimScores[$countryId][$dimMachine] ?? '';

        if (!empty($subdimensions[$dimMachine])) {
          foreach ($subdimensions[$dimMachine] as $subMachine => $subLabel) {
            $line[] =
              $subdimScores[$countryId][$dimMachine][$subMachine] ?? '';
          }
        }
      }

      $rows[] = $line;
    }


    if ($format === 'csv') {
      // 5) CSV output (UTF-8 BOM pour Excel)
      $fh = fopen('php://temp', 'r+');

      // BOM => Excel ouvre bien en UTF-8
      fwrite($fh, "\xEF\xBB\xBF");

      // séparateur ; si tu veux (Excel FR), sinon garde ,
      $delimiter = ','; // ou ';'

      foreach ($rows as $r) {
        fputcsv($fh, $r, $delimiter);
      }

      rewind($fh);
      $csv = stream_get_contents($fh);
      fclose($fh);

      $filename = sprintf('psdi_dimensions_subdimensions_%d.csv', $period);

      return new Response($csv, 200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Cache-Control' => 'max-age=0',
      ]);
    }

    // Excel sheet
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
      ->setCreator('PSDI Export')
      ->setTitle("PSDI export $period");

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Countries x Dimensions');

    $sheet->fromArray($rows, null, 'A1');

    $totalCols  = count($header);
    $lastColLtr = Coordinate::stringFromColumnIndex($totalCols);
    $lastRow    = count($rows);

    // HEader
    $sheet->getStyle("A1:{$lastColLtr}1")->getFont()
      ->setBold(true)
      ->setSize(11);

    // === PSDI style (col 2 = b) ===
    $psdiColIndex = 2;
    $psdiColLetter = Coordinate::stringFromColumnIndex($psdiColIndex);

    $sheet->getStyle("{$psdiColLetter}1")->getFont()
      ->setBold(true)->setSize(13)
      ->setName('Calibri')
      ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1F4E79'));

    $sheet->getStyle("{$psdiColLetter}2:{$psdiColLetter}{$lastRow}")
      ->getFont()->setBold(true);

    //  DIMENSIONS (green, bold, bigger)
    foreach ($dimensionFirstColIndex as $dimMachine => $colIndex) {
      $colLetter = Coordinate::stringFromColumnIndex($colIndex);

      $sheet->getStyle("{$colLetter}1")->getFont()
        ->setBold(true)->setSize(12)
        ->setName('Calibri')
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('006100'));

      $sheet->getStyle("{$colLetter}2:{$colLetter}{$lastRow}")
        ->getFont()->setBold(true);
    }

    // Subdim italic
    $subCols = [];
    $colPointer = 3;

    foreach ($dimensions as $dimMachine => $dimLabel) {
      $colPointer++;

      if (!empty($subdimensions[$dimMachine])) {
        foreach ($subdimensions[$dimMachine] as $subMachine => $subLabel) {
          $subCols[] = $colPointer;
          $colPointer++;
        }
      }
    }

    foreach ($subCols as $colIndex) {
      $colLetter = Coordinate::stringFromColumnIndex($colIndex);

      // Header italic
      $sheet->getStyle("{$colLetter}1")->applyFromArray([
        'font' => [
          'italic' => true,
          'bold' => false,
        ],
      ]);

      // Data italic
      $sheet->getStyle("{$colLetter}2:{$colLetter}{$lastRow}")
        ->applyFromArray([
          'font' => [
            'italic' => true,
            'bold' => false,
          ],
        ]);
    }

    //

    $sheet->getColumnDimension('A')->setWidth(32);
    $sheet->getStyle("A1:A{$lastRow}")
      ->getAlignment()->setWrapText(true);

    for ($i = 2; $i <= $totalCols; $i++) {
      $col = Coordinate::stringFromColumnIndex($i);
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $sheet->setAutoFilter("A1:{$lastColLtr}1");
    $sheet->freezePane('B2');

    if ($totalCols >= 2 && $lastRow >= 2) {
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet->getStyle("B2:{$lastColLtr}{$lastRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
    }

    // Output de l'xxcel
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $bin = ob_get_clean();

    $filename = "psdi_dimensions_subdimensions_{$period}.xlsx";

    return new Response($bin, 200, [
      'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control'       => 'max-age=0',
    ]);
  }

  public function exportPerceptionXlsx(int $period, string $format = 'xlsx', Request $request): Response
  {
    $rows = $this->perceptionRepository->getPerceptionsByPeriod($period);

    if (empty($rows)) {
      return $this->emptyXlsx('No perception data from PerceptionRepository');
    }

    // Optionnel: si tu veux exporter aussi le label de région (North Africa, etc.)
    // Sinon tu peux supprimer region_label partout.
    $regionsMap = [
      'north'    => 'North Africa',
      'west'     => 'West Africa',
      'central'  => 'Central Africa',
      'east'     => 'East Africa',
      'southern' => 'Southern Africa',
    ];

    // 1) Normalize (en respectant le format réel)
    $data = [];
    foreach ($rows as $row) {
      $name   = (string) ($row['country_name'] ?? '');
      $iso2   = (string) ($row['country_iso2'] ?? '');
      $iso3   = (string) ($row['country_iso3'] ?? '');
      $region = (string) ($row['region'] ?? '');
      $score  = $row['score'] ?? null;



      if ($iso3 === '') {
        continue;
      }

      $scoreVal = is_numeric($score) ? round((float) $score, 2) : '';

      $data[] = [
        'label'        => $name,
        'iso2'         => $iso2,
        'iso3'         => $iso3,
        'region'       => $region,
        'region_label' => $regionsMap[$region] ?? $region,
        'score'        => $scoreVal,

      ];
    }

    if (empty($data)) {
      return $this->emptyXlsx('Perception export: no valid rows after mapping');
    }

    // 2) Sort: region then label
    usort($data, static function ($a, $b) {
      $r = strcmp((string) $a['region'], (string) $b['region']);
      if ($r !== 0) return $r;
      return strcmp((string) $a['label'], (string) $b['label']);
    });

    // 3) Matrix
    //Colonnes alignées avec ton nouveau modèle (full_data items)
    $header = ['Label', 'ISO2', 'ISO3', 'Region', 'Region label', 'Score'];

    $matrix = [];
    $matrix[] = $header;

    foreach ($data as $r) {
      $matrix[] = [
        $r['label'],
        $r['iso2'],
        $r['iso3'],
        $r['region'],
        $r['region_label'],
        $r['score'],

      ];
    }

    // 4) CSV
    if ($format === 'csv') {
      $fh = fopen('php://temp', 'r+');
      fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8

      $delimiter = ','; // ou ';'
      foreach ($matrix as $line) {
        fputcsv($fh, $line, $delimiter);
      }

      rewind($fh);
      $csv = stream_get_contents($fh);
      fclose($fh);

      $filename = sprintf('psdi_perceptions_%d.csv', $period);

      return new Response($csv, 200, [
        'Content-Type'        => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Cache-Control'       => 'max-age=0',
      ]);
    }

    // 5) XLSX
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
      ->setCreator('PSDI Export')
      ->setTitle("PSDI perceptions export $period");

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Perceptions');
    $sheet->fromArray($matrix, null, 'A1');

    $totalCols  = count($header);
    $lastColLtr = Coordinate::stringFromColumnIndex($totalCols);
    $lastRow    = count($matrix);

    // Header bold
    $sheet->getStyle("A1:{$lastColLtr}1")->getFont()
      ->setBold(true)
      ->setSize(11);

    // Widths
    $sheet->getColumnDimension('A')->setWidth(32); // Label
    $sheet->getColumnDimension('B')->setWidth(10); // ISO2
    $sheet->getColumnDimension('C')->setWidth(10); // ISO3
    $sheet->getColumnDimension('D')->setWidth(12); // Region key
    $sheet->getColumnDimension('E')->setWidth(18); // Region label
    $sheet->getColumnDimension('F')->setWidth(12); // Score
    $sheet->getColumnDimension('G')->setWidth(14); // Status

    $sheet->getStyle("A1:A{$lastRow}")
      ->getAlignment()->setWrapText(true);

    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$lastColLtr}1");

    if ($lastRow >= 2) {
      // Score col F
      $sheet->getStyle("F2:F{$lastRow}")
        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);

      // Center align some cols
      $sheet->getStyle("B2:G{$lastRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    $writer = new Xlsx($spreadsheet);

    // Écrire dans un flux mémoire (pas php://output)
    $fp = fopen('php://temp', 'r+');
    $writer->save($fp);
    rewind($fp);
    $bin = stream_get_contents($fp);
    fclose($fp);

    $filename = sprintf('psdi_perceptions_%d.xlsx', $period);

    return new Response($bin, 200, [
      'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control'       => 'max-age=0',
    ]);
  }



  private function emptyXlsx(string $message): Response
  {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Empty');

    // Un simple message en A1
    $sheet->setCellValue('A1', $message);

    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $bin = ob_get_clean();

    return new Response($bin, 200, [
      'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="psdi_empty.xlsx"',
      'Cache-Control'       => 'max-age=0',
    ]);
  }
}
