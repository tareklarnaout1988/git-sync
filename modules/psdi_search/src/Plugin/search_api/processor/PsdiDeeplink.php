<?php

namespace Drupal\psdi_search\Plugin\search_api\processor;

use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Adds a deeplink to open the React chart filtered by country/indicator/year.
 *
 * @SearchApiProcessor(
 *   id = "psdi_deeplink",
 *   label = @Translation("PSDI Deeplink"),
 *   description = @Translation("Adds a computed URL for PSDI React charts."),
 *   stages = {
 *     "add_properties" = 0,
 *     "preprocess_index" = -10
 *   }
 * )
 */
final class PsdiDeeplink extends ProcessorPluginBase {

  /**
   * Declare custom indexed properties (fields).
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    // We only add fields for the main datasource (not per-language/other).
    if ($datasource) {
      return [];
    }

    $definitions['psdi_deeplink'] = DataDefinition::create('string')
      ->setLabel($this->t('PSDI deeplink'))
      ->setDescription($this->t('Computed URL to open the React chart.'))
      ->setComputed(TRUE);

    return $definitions;
  }

  /**
   * Fill the computed field value.
   */
  public function addFieldValues(ItemInterface $item) : void {
    $original = $item->getOriginalObject()->getValue();

    if (!$original || $original->getEntityTypeId() !== 'indicator_score') {
      return;
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $original;

    $year = (string) $entity->get('period')->value;

    // Country term.
    $country_term = $entity->get('country')->entity;
    $country_code = NULL;
    if ($country_term) {
      // Priorité: ISO2 si dispo, sinon fallback TID.
      $country_code = $country_term->hasField('field_iso2') && !$country_term->get('field_iso2')->isEmpty()
        ? $country_term->get('field_iso2')->value
        : (string) $country_term->id();
    }

    // Indicator term.
    $indicator_term = $entity->get('indicator')->entity;
    $indicator_key = NULL;
    if ($indicator_term) {
      // Priorité: machine_name si dispo, sinon fallback TID.
      $indicator_key = $indicator_term->hasField('field_machine_name') && !$indicator_term->get('field_machine_name')->isEmpty()
        ? $indicator_term->get('field_machine_name')->value
        : (string) $indicator_term->id();
    }

    if (!$country_code || !$indicator_key || !$year) {
      return;
    }

    //  URL de page React/Drupal (route réelle).
    $url = "/psdi-dashboard?country={$country_code}&indicator={$indicator_key}&year={$year}#charts";

    // Push into the Search API field.
    $fields = $item->getFields(FALSE);
    foreach ($fields as $field) {
      if ($field->getPropertyPath() === 'psdi_deeplink') {
        $field->addValue($url);
      }
    }
  }

}
