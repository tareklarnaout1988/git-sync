<?php

declare(strict_types=1);

namespace Drupal\psdi_perception\Entity;

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
use Drupal\psdi_perception\Form\PsdiPerceptionForm;
use Drupal\psdi_perception\PsdiPerceptionAccessControlHandler;
use Drupal\psdi_perception\PsdiPerceptionInterface;
use Drupal\psdi_perception\PsdiPerceptionListBuilder;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the psdi perception entity class.
 */
#[ContentEntityType(
  id: 'psdi_perception',
  label: new TranslatableMarkup('Psdi perception'),
  label_collection: new TranslatableMarkup('Psdi perceptions'),
  label_singular: new TranslatableMarkup('psdi perception'),
  label_plural: new TranslatableMarkup('psdi perceptions'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'label' => 'id',
    'owner' => 'uid',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => PsdiPerceptionListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => PsdiPerceptionAccessControlHandler::class,
    'form' => [
      'add' => PsdiPerceptionForm::class,
      'edit' => PsdiPerceptionForm::class,
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
    'collection' => '/admin/content/psdi-perception',
    'add-form' => '/psdi-perception/add',
    'canonical' => '/psdi-perception/{psdi_perception}',
    'edit-form' => '/psdi-perception/{psdi_perception}/edit',
    'delete-form' => '/psdi-perception/{psdi_perception}/delete',
    'delete-multiple-form' => '/admin/content/psdi-perception/delete-multiple',
    'revision' => '/psdi-perception/{psdi_perception}/revision/{psdi_perception_revision}/view',
    'revision-delete-form' => '/psdi-perception/{psdi_perception}/revision/{psdi_perception_revision}/delete',
    'revision-revert-form' => '/psdi-perception/{psdi_perception}/revision/{psdi_perception_revision}/revert',
    'version-history' => '/psdi-perception/{psdi_perception}/revisions',
  ],
  admin_permission: 'administer psdi_perception',
  base_table: 'psdi_perception',
  revision_table: 'psdi_perception_revision',
  show_revision_ui: TRUE,
  label_count: [
    'singular' => '@count psdi perceptions',
    'plural' => '@count psdi perceptions',
  ],
  field_ui_base_route: 'entity.psdi_perception.settings',
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
)]
class PsdiPerception extends EditorialContentEntityBase implements PsdiPerceptionInterface
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
      ->setDescription(t('The time that the psdi perception was created.'))
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
      ->setDescription(t('The time that the psdi perception was last edited.'));

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
