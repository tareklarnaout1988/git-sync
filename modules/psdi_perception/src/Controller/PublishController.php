<?php

declare(strict_types=1);

namespace Drupal\psdi_perception\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

final class PublishController extends ControllerBase {

  public function publishByPeriod(int $period): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage('psdi_perception');

    $ids = $storage->getQuery()
      ->condition('period', $period)
      ->condition('status', 0)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      $this->messenger()->addWarning($this->t('Nothing to publish for @p.', ['@p' => $period]));
      return $this->redirect('indicator_score.admin_dashboard');
    }

    $operations = [];
    foreach (array_chunk($ids, 100) as $chunk) {
      $operations[] = [
        [static::class, 'batchPublish'],
        [$chunk, $period],
      ];
    }

    $batch = [
      'title' => $this->t('Publishing perception data for @p', ['@p' => $period]),
      'operations' => $operations,
      'finished' => [static::class, 'batchPublishFinished'],
    ];

    batch_set($batch);
    return batch_process(Url::fromRoute('indicator_score.admin_dashboard'));
  }

  public static function batchPublish(array $ids, int $period, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('psdi_perception');
    $items = $storage->loadMultiple($ids);

    $count = 0;
    foreach ($items as $item) {
      $item->set('status', 1);
      $item->save();
      $count++;
    }

    $context['results']['count'] = ($context['results']['count'] ?? 0) + $count;
    $context['results']['period'] = $period;
  }

  public static function batchPublishFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    $period = $results['period'] ?? null;
    $count  = $results['count'] ?? 0;

    if ($success) {
      if ($count > 0) {
        $messenger->addStatus(\Drupal::translation()->formatPlural(
          $count,
          '@count perception entry published for @p.',
          '@count perception entries published for @p.',
          ['@p' => $period]
        ));
      } else {
        $messenger->addWarning('Nothing was published.');
      }
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'psdi_perception_list',
        'psdi_perception:period:' . $period,
      ]);
    } else {
      $messenger->addError('Publishing failed.');
    }
  }


  /* --------------------------- UNPUBLISH --------------------------- */

  public function unpublishByPeriod(int $period): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage('psdi_perception');

    $ids = $storage->getQuery()
      ->condition('period', $period)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      $this->messenger()->addWarning($this->t('Nothing to unpublish for @p.', ['@p' => $period]));
      return $this->redirect('indicator_score.admin_dashboard');
    }

    $operations = [];
    foreach (array_chunk($ids, 100) as $chunk) {
      $operations[] = [
        [static::class, 'batchUnpublish'],
        [$chunk, $period],
      ];
    }

    $batch = [
      'title' => $this->t('Unpublishing perception data for @p', ['@p' => $period]),
      'operations' => $operations,
      'finished' => [static::class, 'batchUnpublishFinished'],
    ];

    batch_set($batch);
    return batch_process(Url::fromRoute('indicator_score.admin_dashboard'));
  }

  public static function batchUnpublish(array $ids, int $period, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('psdi_perception');
    $items = $storage->loadMultiple($ids);

    $count = 0;
    foreach ($items as $item) {
      $item->set('status', 0);
      $item->save();
      $count++;
    }

    $context['results']['count'] = ($context['results']['count'] ?? 0) + $count;
    $context['results']['period'] = $period;
  }

  public static function batchUnpublishFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    $period = $results['period'] ?? null;
    $count  = $results['count'] ?? 0;

    if ($success) {
      if ($count > 0) {
        $messenger->addStatus(\Drupal::translation()->formatPlural(
          $count,
          '@count perception entry unpublished for @p.',
          '@count perception entries unpublished for @p.',
          ['@p' => $period]
        ));
      } else {
        $messenger->addWarning('Nothing was unpublished.');
      }
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'psdi_perception_list',
        'psdi_perception:period:' . $period,
      ]);
    } else {
      $messenger->addError('Unpublishing failed.');
    }
  }

}
