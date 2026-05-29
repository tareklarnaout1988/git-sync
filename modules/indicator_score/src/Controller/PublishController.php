<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

final class PublishController extends ControllerBase {

  /**
   * Publie toutes les entités indicator_score non publiées pour une période,
   * via Batch API pour éviter les timeouts.
   */
  public function publishByPeriod(int $period): RedirectResponse {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage('indicator_score');

    // IDs non publiés.
    $ids = $storage->getQuery()
      ->condition('period', $period)
      ->condition('status', 0)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      $this->messenger()->addWarning(
        $this->t('No unpublished indicator scores found for period @p.', ['@p' => $period])
      );
      return $this->redirect('indicator_score.admin_dashboard');
    }

    // opérations en chunks.
    $operations = [];
    $chunk_size = 100;
    $chunks = array_chunk($ids, $chunk_size);

    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchPublishOperation'],
        [$chunk, $period],
      ];
    }

    //  batch.
    $batch = [
      'title' => $this->t('Publishing indicator scores for period @p', ['@p' => $period]),
      'operations' => $operations,
      'finished' => [static::class, 'batchPublishFinished'],
      'init_message' => $this->t('Starting publishing process...'),
      'progress_message' => $this->t('Processed @current out of @total publish batches.'),
      'error_message' => $this->t('An error occurred during the publishing process.'),
    ];

    batch_set($batch);

    $url = Url::fromRoute('indicator_score.admin_dashboard');
    return batch_process($url);
  }

  /**
   * Opération de batch : publie une liste d'IDs d'entités.
   *
   * @param int[] $ids
   * @param int $period
   * @param array $context
   */
  public static function batchPublishOperation(array $ids, int $period, array &$context): void {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('indicator_score');

    $entities = $storage->loadMultiple($ids);
    $count = 0;

    foreach ($entities as $entity) {
    //   if ((int) $entity->get('status')->value !== 1) {
        $entity->set('status', 1);
        $entity->save();
        $count++;
    //   }
    }

    // Stocker le nombre total publié.
    if (!isset($context['results']['published'])) {
      $context['results']['published'] = 0;
    }
    $context['results']['published'] += $count;

    $context['results']['period'] = $period;

    $context['message'] = t(
      'Publishing indicator scores for period @p (last batch: @c updated).',
      ['@p' => $period, '@c' => $count]
    );
  }

  public static function batchPublishFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();

    if ($success) {
      $count = $results['published'] ?? 0;
      $period = $results['period'] ?? null;

      if ($count > 0) {
        $messenger->addStatus(
          \Drupal::translation()->formatPlural(
            $count,
            '@count indicator score has been published for period @p.',
            '@count indicator scores have been published for period @p.',
            ['@p' => $period]
          )
        );
      }
      else {
        $messenger->addWarning(t('No unpublished indicator scores were found.'));
      }

      // Invalidation des caches liés.
      $tags = ['indicator_score_list'];
      // if ($period !== null) {
      //   $tags[] = 'indicator_score:period:' . $period;
      // }
      // dd($tags);
      \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
    }
    else {
      $error_operation = reset($operations);
      $messenger->addError(t('An error occurred while processing @op.', [
        '@op' => is_array($error_operation) ? $error_operation[0] : 'operation',
      ]));
    }
  }

  /**
   * Dépublie toutes les entités indicator_score pour une période,
   * via Batch API (status = 0).
   */
  public function unpublishByPeriod(int $period): RedirectResponse {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage('indicator_score');

    //IDs  (status = 1).
    $ids = $storage->getQuery()
      ->condition('period', $period)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      $this->messenger()->addWarning(
        $this->t('No published indicator scores found for period @p.', ['@p' => $period])
      );
      return $this->redirect('indicator_score.admin_dashboard');
    }

    // chunks.
    $operations = [];
    $chunk_size = 100;
    $chunks = array_chunk($ids, $chunk_size);

    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchUnpublishOperation'],
        [$chunk, $period],
      ];
    }

    // batch.
    $batch = [
      'title' => $this->t('Unpublishing indicator scores for period @p', ['@p' => $period]),
      'operations' => $operations,
      'finished' => [static::class, 'batchUnpublishFinished'],
      'init_message' => $this->t('Starting unpublishing process...'),
      'progress_message' => $this->t('Processed @current out of @total unpublish batches.'),
      'error_message' => $this->t('An error occurred during the unpublishing process.'),
    ];

    batch_set($batch);

    $url = Url::fromRoute('indicator_score.admin_dashboard');
    return batch_process($url);
  }

  /**
   * Opération de batch : dépublie une liste d'IDs d'entités.
   *
   * @param int[] $ids
   * @param int $period
   * @param array $context
   */
  public static function batchUnpublishOperation(array $ids, int $period, array &$context): void {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('indicator_score');

    $entities = $storage->loadMultiple($ids);
    $count = 0;

    foreach ($entities as $entity) {
    //   if ((int) $entity->get('status')->value !== 0) {
        $entity->set('status', 0);
        $entity->save();
        $count++;
    //   }
    }

    if (!isset($context['results']['unpublished'])) {
      $context['results']['unpublished'] = 0;
    }
    $context['results']['unpublished'] += $count;

    $context['results']['period'] = $period;

    $context['message'] = t(
      'Unpublishing indicator scores for period @p (last batch: @c updated).',
      ['@p' => $period, '@c' => $count]
    );
  }


  public static function batchUnpublishFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();

    if ($success) {
      $count = $results['unpublished'] ?? 0;
      $period = $results['period'] ?? null;

      if ($count > 0) {
        $messenger->addStatus(
          \Drupal::translation()->formatPlural(
            $count,
            '@count indicator score has been unpublished for period @p.',
            '@count indicator scores have been unpublished for period @p.',
            ['@p' => $period]
          )
        );
      }
      else {
        $messenger->addWarning(t('No published indicator scores were found to unpublish.'));
      }


      $tags = ['indicator_score_list'];
      // if ($period !== null) {
      //   $tags[] = 'indicator_score:period:' . $period;
      // }
      \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
    }
    else {
      $error_operation = reset($operations);
      $messenger->addError(t('An error occurred while processing @op.', [
        '@op' => is_array($error_operation) ? $error_operation[0] : 'operation',
      ]));
    }
  }

}
