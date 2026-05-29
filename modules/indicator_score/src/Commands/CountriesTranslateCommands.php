<?php

namespace Drupal\indicator_score\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

class CountriesTranslateCommands extends DrushCommands
{

    protected string $vocabulary = 'cit_countries_information';
    protected string $isoField   = 'field_citf_iso2_code'; // adapte si besoin

    public function __construct(
        protected EntityTypeManagerInterface $etm
    ) {}

    /**
     * Traduction automatique EN → FR des pays via CSV (ISO2).
     *
     * @command psdi:countries-translate-fr
     * @aliases ctrfr
     * @usage drush psdi:countries-translate-fr
     */
    public function translateCountriesToFr(): void
    {

        $csvPath = DRUPAL_ROOT . '/modules/indicator_score/data/countries_en_fr.csv';

        if (!file_exists($csvPath)) {
            $this->logger()->error("CSV not found: {$csvPath}");
            return;
        }

        $map = $this->loadCsv($csvPath); // [ISO2 => name_fr]

        if (empty($map)) {
            $this->logger()->error('CSV empty or invalid.');
            return;
        }

        $storage = $this->etm->getStorage('taxonomy_term');
        $terms = $storage->loadTree($this->vocabulary, 0, NULL, TRUE);

        $translated = 0;
        $skipped    = 0;

        foreach ($terms as $term) {
            if (!$term->isPublished()) {
                continue;
            }
            // ISO2 du terme
            $iso2 = strtoupper(trim($term->get($this->isoField)->value ?? ''));

            if (!$iso2 || !isset($map[$iso2])) {
                $this->logger()->warning("Missing FR mapping for ISO2: {$iso2}");
                $skipped++;
                continue;
            }

            // Traduction déjà existante → skip
            if ($term->hasTranslation('fr')) {
                $skipped++;
                continue;
            }

            // Créer traduction FR
            $fr = $term->addTranslation('fr');
            $fr->setName($map[$iso2]);
            $source = $term;

            // Liste des champs à copier
            $fieldsToCopy = [
                'field_citf_continent',
                'field_citf_iso2_code',
                'field_citf_iso3_code',
                'field_citf_iso_num_code',
                'field_citf_official_name',
            ];

            foreach ($fieldsToCopy as $fieldName) {
                if ($source->hasField($fieldName)) {
                    // Copie complète des valeurs (fonctionne aussi en multi-value)
                    $fr->set($fieldName, $source->get($fieldName)->getValue());
                }
            }

            $fr->save();

            $translated++;
            $this->logger()->notice("Translated {$iso2}: {$term->getName()} → {$map[$iso2]}");
        }

        $this->logger()->success("Done. {$translated} translated, {$skipped} skipped.");
    }

    /**
     * Charge le CSV et retourne [ISO2 => name_fr]
     */
    protected function loadCsv(string $path): array
    {
        $map = [];

        if (($handle = fopen($path, 'r')) === FALSE) {
            return [];
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return [];
        }

        $indexes = array_flip($header);

        while (($row = fgetcsv($handle)) !== FALSE) {
            $iso2    = strtoupper(trim($row[$indexes['iso2']] ?? ''));
            $name_fr = trim($row[$indexes['name_fr']] ?? '');

            if ($iso2 && $name_fr) {
                $map[$iso2] = $name_fr;
            }
        }

        fclose($handle);
        return $map;
    }

    /**
     * Supprime toutes les traductions FR du vocabulaire des pays.
     *
     * @command psdi:countries-delete-fr
     * @aliases ctdelfr
     */
    public function deleteFrenchTranslations(): void
    {
        $storage = $this->etm->getStorage('taxonomy_term');
        $terms = $storage->loadTree($this->vocabulary, 0, NULL, TRUE);

        $deleted = 0;

        foreach ($terms as $term) {

            if ($term->hasTranslation('fr')) {

                // Supprimer uniquement la traduction FR
                $term->removeTranslation('fr');
                $term->save();

                $deleted++;
                $this->logger()->notice("Deleted FR translation for term ID {$term->id()}");
            }
        }

        $this->logger()->success("Done. {$deleted} French translations deleted.");
    }



    /**
     * Crée automatiquement le champ Africa Region.
     *
     * @command psdi:countries-create-region-field
     * @aliases ctregion
     */
    public function createRegionField(): void
    {
        $fieldName = 'field_africa_region';
        $entityType = 'taxonomy_term';
        $bundle = $this->vocabulary;

        // Vérifie si le field storage existe déjà
        $storage = FieldStorageConfig::loadByName($entityType, $fieldName);

        if (!$storage) {

            FieldStorageConfig::create([
                'field_name' => $fieldName,
                'entity_type' => $entityType,
                'type' => 'list_string',
                'cardinality' => 1,
                'settings' => [
                    'allowed_values' => [
                        'north' => 'North Africa',
                        'west' => 'West Africa',
                        'central' => 'Central Africa',
                        'east' => 'East Africa',
                        'southern' => 'Southern Africa',
                    ],
                ],
            ])->save();

            $this->logger()->notice("Field storage created.");
        }

        // Vérifie si le champ est attaché au vocabulaire
        $field = FieldConfig::loadByName($entityType, $bundle, $fieldName);

        if (!$field) {

            FieldConfig::create([
                'field_name' => $fieldName,
                'entity_type' => $entityType,
                'bundle' => $bundle,
                'label' => 'Africa Region',
                'required' => FALSE,
                'translatable' => FALSE,
            ])->save();

            $this->logger()->notice("Field attached to vocabulary.");
        }

        

        $this->logger()->success("Africa Region field is ready.");
    }

    /**
     * Populate Africa regions (north/west/central/east/southern) based on ISO2.
     *
     * @command psdi:countries-populate-africa-region
     * @aliases ctareg
     * @option overwrite Écrase la valeur existante si déjà renseignée.
     * @usage drush psdi:countries-populate-africa-region
     * @usage drush psdi:countries-populate-africa-region --overwrite
     */
    public function populateAfricaRegions(array $options = ['overwrite' => FALSE]): void
    {
        $regionField = 'field_africa_region';
        $overwrite = (bool) ($options['overwrite'] ?? FALSE);

        // Mapping ISO2 -> region (UNSD M49: Northern/Western/Middle/Eastern/Southern Africa). :contentReference[oaicite:1]{index=1}
        $map = [
            // Northern Africa (015)
            'DZ' => 'north',
            'EG' => 'north',
            'LY' => 'north',
            'MA' => 'north',
            'SD' => 'north',
            'TN' => 'north',

            // Western Africa (011)
            'BJ' => 'west',
            'BF' => 'west',
            'CV' => 'west',
            'CI' => 'west',
            'GM' => 'west',
            'GH' => 'west',
            'GN' => 'west',
            'GW' => 'west',
            'LR' => 'west',
            'ML' => 'west',
            'MR' => 'west',
            'NE' => 'west',
            'NG' => 'west',
            'SN' => 'west',
            'SL' => 'west',
            'TG' => 'west',

            // Middle Africa (017) -> on mappe vers "central"
            'AO' => 'central',
            'CM' => 'central',
            'CF' => 'central',
            'TD' => 'central',
            'CG' => 'central',
            'CD' => 'central',
            'GQ' => 'central',
            'GA' => 'central',
            'ST' => 'central',

            // Eastern Africa (014)
            'BI' => 'east',
            'KM' => 'east',
            'DJ' => 'east',
            'ER' => 'east',
            'ET' => 'east',
            'KE' => 'east',
            'MG' => 'east',
            'MW' => 'east',
            'MU' => 'east',
            'MZ' => 'east',
            'RW' => 'east',
            'SC' => 'east',
            'SO' => 'east',
            'SS' => 'east',
            'TZ' => 'east',
            'UG' => 'east',
            'ZM' => 'east',
            'ZW' => 'east',

            // Southern Africa (018)
            'BW' => 'southern',
            'SZ' => 'southern',
            'LS' => 'southern',
            'NA' => 'southern',
            'ZA' => 'southern',
        ];

        $storage = $this->etm->getStorage('taxonomy_term');
        $terms = $storage->loadTree($this->vocabulary, 0, NULL, TRUE);

        $updated = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($terms as $term) {
            if (!$term->isPublished()) {
                continue;
            }

            if (!$term->hasField($this->isoField)) {
                $this->logger()->warning("Term {$term->id()} missing ISO2 field {$this->isoField}");
                $missing++;
                continue;
            }

            $iso2 = strtoupper(trim($term->get($this->isoField)->value ?? ''));
            if (!$iso2 || !isset($map[$iso2])) {
                $this->logger()->warning("No region mapping for ISO2: {$iso2} (term {$term->id()})");
                $missing++;
                continue;
            }

            if (!$term->hasField($regionField)) {
                $this->logger()->error("Region field not found on term bundle: {$regionField}");
                return;
            }

            $current = $term->get($regionField)->value ?? NULL;
            if (!$overwrite && !empty($current)) {
                $skipped++;
                continue;
            }

            $term->set($regionField, $map[$iso2]);
            $term->save();
            $updated++;

            $this->logger()->notice("{$iso2} -> {$map[$iso2]} (term {$term->id()})");
        }

        $this->logger()->success("Done. {$updated} updated, {$skipped} skipped, {$missing} missing/unmapped.");
    }
}
