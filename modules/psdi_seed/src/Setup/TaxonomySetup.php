<?php

namespace Drupal\psdi_seed\Setup;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;

class TaxonomySetup {

  public function ensure(): void {
    $this->ensureVocab('dimension', 'Dimension');
    $this->ensureVocab('subdimension', 'SubDimension');
    $this->ensureVocab('indicator', 'Indicator');

    // Dimension fields.
    $this->ensureDecimalField('taxonomy_term', 'dimension', 'field_weight', 'Weight', 10, 4);

    // SubDimension fields.
    $this->ensureDecimalField('taxonomy_term', 'subdimension', 'field_weight', 'Weight', 10, 4);
    $this->ensureEntityRefField('taxonomy_term', 'subdimension', 'field_dimension', 'Parent Dimension', 'taxonomy_term', 'dimension');

    // Indicator fields (⚠️ NO custom machine field; Taxonomy Machine Name provides base field).
    $this->ensureDecimalField('taxonomy_term', 'indicator', 'field_weight', 'Weight', 10, 4);
    $this->ensureEntityRefField('taxonomy_term', 'indicator', 'field_subdimension', 'Parent SubDimension', 'taxonomy_term', 'subdimension');
  }

  private function ensureVocab(string $vid, string $label): void {
    if (!Vocabulary::load($vid)) {
      Vocabulary::create(['vid' => $vid, 'name' => $label])->save();
    }
  }

  private function ensureDecimalField(string $entityType, string $bundle, string $name, string $label, int $precision, int $scale): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => 'decimal',
        'settings' => ['precision' => $precision, 'scale' => $scale],
      ])->save();
    }
    if (!FieldConfig::loadByName($entityType, $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => $label,
      ])->save();
    }
  }

  private function ensureEntityRefField(string $entityType, string $bundle, string $name, string $label, string $targetType, string $targetBundle): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => $targetType,
          'handler' => 'default:taxonomy_term',
          'handler_settings' => ['target_bundles' => [$targetBundle => $targetBundle]],
        ],
      ])->save();
    }
    if (!FieldConfig::loadByName($entityType, $bundle, $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => $bundle,
        'label' => $label,
      ])->save();
    }
  }
}
