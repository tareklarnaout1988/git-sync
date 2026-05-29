<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\indicator_score\Form\IndicatorScoreForm;
use Drupal\indicator_score\IndicatorScoreAccessControlHandler;
use Drupal\indicator_score\IndicatorScoreInterface;
use Drupal\indicator_score\IndicatorScoreListBuilder;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the indicator score entity class.
 */
#[ContentEntityType(
  id: 'indicator_score',
  label: new TranslatableMarkup('Indicator score'),
  label_collection: new TranslatableMarkup('Indicator scores'),
  label_singular: new TranslatableMarkup('indicator score'),
  label_plural: new TranslatableMarkup('indicator scores'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'label' => 'id',
    'owner' => 'uid',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => IndicatorScoreListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => IndicatorScoreAccessControlHandler::class,
    'form' => [
      'add' => IndicatorScoreForm::class,
      'edit' => IndicatorScoreForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/indicator-score',
    'add-form' => '/indicator-score/add',
    'canonical' => '/indicator-score/{indicator_score}',
    'edit-form' => '/indicator-score/{indicator_score}/edit',
    'delete-form' => '/indicator-score/{indicator_score}/delete',
    'delete-multiple-form' => '/admin/content/indicator-score/delete-multiple',
    'revision' => '/indicator-score/{indicator_score}/revision/{indicator_score_revision}/view',
    'revision-delete-form' => '/indicator-score/{indicator_score}/revision/{indicator_score_revision}/delete',
    'revision-revert-form' => '/indicator-score/{indicator_score}/revision/{indicator_score_revision}/revert',
    'version-history' => '/indicator-score/{indicator_score}/revisions',
  ],
  admin_permission: 'administer indicator_score',
  base_table: 'indicator_score',
  revision_table: 'indicator_score_revision',
  show_revision_ui: TRUE,
  label_count: [
    'singular' => '@count indicator scores',
    'plural' => '@count indicator scores',
  ],
  field_ui_base_route: 'entity.indicator_score.settings',
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
)]
class IndicatorScore extends EditorialContentEntityBase implements IndicatorScoreInterface
{

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void
  {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
  {

    $fields = parent::baseFieldDefinitions($entity_type);

    // $fields['label'] = BaseFieldDefinition::create('string')
    //   ->setRevisionable(TRUE)
    //   ->setLabel(t('Label'))
    //   ->setRequired(TRUE)
    //   ->setSetting('max_length', 255)
    //   ->setDisplayOptions('form', [
    //     'type' => 'string_textfield',
    //     'weight' => -5,
    //   ])
    //   ->setDisplayConfigurable('form', TRUE)
    //   ->setDisplayOptions('view', [
    //     'label' => 'hidden',
    //     'type' => 'string',
    //     'weight' => -5,
    //   ])
    //   ->setDisplayConfigurable('view', TRUE);


    $fields['indicator'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel('Indicator')
      ->setDescription('Select the indicator for this indicator.')
      ->setRequired(TRUE)
      ->setCardinality(1)
      // CIBLE : termes de taxonomie
      ->setSetting('target_type', 'taxonomy_term')
      // Sélection par handler "default" + restriction au vocabulaire
      ->setSetting('handler', 'default') // (équiv. à 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'indicator' => 'indicator',
        ],
        // Optionnel : tri alpha
        'sort' => [
          'field' => 'name',
          'direction' => 'ASC',
        ],
        // Optionnel : auto-création via widget
        'auto_create' => FALSE,
      ])
      // Widget de formulaire
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete', // ou 'options_select' si liste fermée
        'weight' => 2,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      // Affichage
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 2,
        'settings' => ['link' => TRUE],
      ])
      ->setDisplayConfigurable('view', TRUE);


    $fields['period'] = BaseFieldDefinition::create('list_integer')
      ->setRevisionable(TRUE)
      ->setLabel(t('Period (Year)'))
      ->setDescription(t('Select the year for this indicator.'))
      ->setRequired(TRUE)
      // Définir dynamiquement les valeurs possibles (callback).
      ->setSetting('allowed_values_function', static::class . '::getYearOptions')
      // Widget du formulaire → liste déroulante.
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      // Affichage de la valeur.
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['country'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel('Country')
      ->setDescription('Select the country for this indicator.')
      ->setRequired(TRUE)
      ->setCardinality(1)
      // CIBLE : termes de taxonomie
      ->setSetting('target_type', 'taxonomy_term')
      // Sélection par handler "default" + restriction au vocabulaire
      ->setSetting('handler', 'default') // (équiv. à 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'cit_countries_information' => 'cit_countries_information',
        ],
        // Optionnel : tri alpha
        'sort' => [
          'field' => 'name',
          'direction' => 'ASC',
        ],
        // Optionnel : auto-création via widget
        'auto_create' => FALSE,
      ])
      // Widget de formulaire
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete', // ou 'options_select' si liste fermée
        'weight' => 2,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      // Affichage
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 2,
        'settings' => ['link' => TRUE],
      ])
      ->setDisplayConfigurable('view', TRUE);

    //  Score (décimal, révisionnable, translatable si tu veux).
    $fields['score'] = BaseFieldDefinition::create('decimal')
      ->setRevisionable(TRUE)
      ->setLabel(t('Score'))
      ->setDescription(t('Score value for this indicator.'))
      // Précision/échelle : 10 chiffres dont 2 décimales (ex: 99.99).
      ->setSetting('precision', 18)
      ->setSetting('scale', 15)
      ->setRequired(TRUE)
      // Facultatif: valeur par défaut.
      ->setDefaultValue(NULL) // ou 0 si tu veux forcer 0
      // Contraintes min/max (ex: 0 à 100).
      // ->addConstraint('Range', ['min' => 0, 'max' => 100]) ceci crée une erreur donc voir form_alter
      // Widget du formulaire.
      ->setDisplayOptions('form', [
        'type' => 'number',        // widget number
        'weight' => 3,
        'settings' => [
          'scale' => 15,
          'prefix' => '',
          'suffix' => '',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      // Affichage en vue.
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_decimal',
        'weight' => 3,
        'settings' => [
          'scale' => 15,
          'decimal_separator' => '.',
          'thousand_separator' => ' ',
          'prefix_suffix' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);


    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setRevisionable(TRUE)
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the indicator was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the indicator was last edited.'));

    return $fields;
  }

  /**
   * return list of years.
   */
  public static function getYearOptions(): array
  {
    $years = [];
    $current = (int) date('Y');
    $start = 2000; // année de départ

    for ($year = $current; $year >= $start; $year--) {
      $years[$year] = (string) $year;
    }

    return $years;
  }
}
