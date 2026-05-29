<?php

declare(strict_types=1);

namespace Drupal\indicator_score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class PublishController extends ControllerBase
{

    /**
     * Publie tous les indicator_score non publiés pour une période.
     */
    public function publishByPeriod(int $period): RedirectResponse
    {
        $database = \Drupal::database();

        // Update direct en base.
        $count = $database->update('indicator_score')
            ->fields(['status' => 1])
            ->condition('period', $period)
            ->condition('status', 0)
            ->execute();

        \Drupal::service('cache_tags.invalidator')->invalidateTags([
            'indicator_score_list',
            'indicator_score:period:' . $period, // si tu veux être plus fin
        ]);

        if ($count > 0) {
            $this->messenger()->addStatus(
                $this->formatPlural(
                    $count,
                    '@count indicator score has been published for period @p.',
                    '@count indicator scores have been published for period @p.',
                    ['@p' => $period]
                )
            );
        } else {
            $this->messenger()->addWarning(
                $this->t('No unpublished indicator scores found for period @p.', ['@p' => $period])
            );
        }

        return $this->redirect('indicator_score.admin_dashboard');
    }

    public function unpublishByPeriod(int $period): RedirectResponse
    {
        $database = \Drupal::database();

        // Update direct en base.
        $count = $database->update('indicator_score')
            ->fields(['status' => 0])
            ->condition('period', $period)
            ->condition('status', 1)
            ->execute();

        \Drupal::service('cache_tags.invalidator')->invalidateTags([
            'indicator_score_list',
            'indicator_score:period:' . $period,
        ]);

        if ($count > 0) {
            $this->messenger()->addStatus(
                $this->formatPlural(
                    $count,
                    '@count indicator score has been unpublished for period @p.',
                    '@count indicator scores have been unpublished for period @p.',
                    ['@p' => $period]
                )
            );
        } else {
            $this->messenger()->addWarning(
                $this->t('No published indicator scores found for period @p.', ['@p' => $period])
            );
        }

        return $this->redirect('indicator_score.admin_dashboard');
    }
}
