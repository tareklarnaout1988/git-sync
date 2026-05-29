<?php

declare(strict_types=1);

namespace Drupal\indicator_score;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the indicator score entity type.
 */
final class IndicatorScoreListBuilder extends EntityListBuilder
{

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array
  {
    $header['id'] = $this->t('ID');
    $header['status'] = $this->t('Status');
    $header['period'] = $this->t('period');
    $header['indicator'] = $this->t('indicator');
    $header['country'] = $this->t('country');

    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array
  {
    /** @var \Drupal\indicator_score\IndicatorScoreInterface $entity */
    $row['id'] = $entity->toLink();
    $row['status'] = $entity->get('status')->value ? $this->t('Enabled') : $this->t('Disabled');
    $row['period'] = $entity->get('period')->value;
    $indicator_tid = $entity->get('indicator')->target_id;
    $country_tid = $entity->get('country')->target_id;

    $indicator_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($indicator_tid);
    $country_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($country_tid);

    $row['indicator'] = $indicator_term ? $indicator_term->label() : '';
    $row['country'] = $country_term ? $country_term->label() : '';


    $username_options = [
      'label' => 'hidden',
      'settings' => ['link' => $entity->get('uid')->entity->isAuthenticated()],
    ];
    $row['uid']['data'] = $entity->get('uid')->view($username_options);
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }
}
