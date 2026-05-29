<?php

declare(strict_types=1);

namespace Drupal\indicator_import_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\indicator_import_ui\Service\IndicatorImporter;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Component\Render\FormattableMarkup;

/**
 * UI form to upload an XLSX and trigger Batch import.
 */
final class IndicatorImportForm extends FormBase
{

    public static function create(ContainerInterface $container): static
    {
        // Pas d'injection nécessaire pour ce formulaire.
        return new static();
    }

    public function getFormId(): string
    {
        return 'indicator_import_ui_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        // Champ fichier avec validateurs "Constraint" de Drupal 10.2+/11.
        $form['file'] = [
            '#type' => 'managed_file',
            '#title' => $this->t('Excel file (.xlsx)'),
            '#upload_location' => 'public://',
            '#upload_validators' => [
                // ✅ Drupal 11 : utiliser les plugins "Constraint", pas file_validate_*.
                'FileExtension' => ['extensions' => 'xlsx'],
                // Optionnel : limite de taille (ex. 20 Mo)
                'FileSizeLimit' => ['fileLimit' => 20 * 1024 * 1024],
                // Autres possibles : 'FileNameLength' => [], 'FileEncoding' => [], etc.
            ],
            '#required' => TRUE,
            '#description' => $this->t('Col A = Country ISO2 | Col B = period year | Other columns = indicator machine names'),
        ];

        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Import now'),
        ];

        // -- Bloc d’affichage des derniers logs (si dblog est actif) --
        $module_handler = \Drupal::service('module_handler');
        if ($module_handler->moduleExists('dblog')) {
            $limit = 20;
            $conn = \Drupal::database();
            $query = $conn->select('watchdog', 'w')
                ->fields('w', ['wid', 'type', 'message', 'variables', 'severity', 'timestamp'])
                ->condition('w.type', 'indicator_import')
                ->orderBy('w.wid', 'DESC')
                ->range(0, $limit);

            $rows = $query->execute()->fetchAll();
            $date_formatter = \Drupal::service('date.formatter');
            $items = [];

            foreach ($rows as $row) {
                $vars = [];
                if (!empty($row->variables)) {
                    $vars = @unserialize($row->variables) ?: [];
                    if (!is_array($vars)) {
                        $vars = [];
                    }
                }

                $formatted = new FormattableMarkup($row->message, $vars);
                $date = $date_formatter->format((int) $row->timestamp, 'short');

                $url = Url::fromRoute('dblog.event', ['event_id' => (int) $row->wid]);
                $link = Link::fromTextAndUrl($this->t('View full entry'), $url)->toString();

                switch ((int) $row->severity) {
                    case 0: // Emergency
                        $icon = '🟥';
                        $label = $this->t('Emergency');
                        break;
                    case 1: // Alert
                        $icon = '🟥';
                        $label = $this->t('Alert');
                        break;
                    case 2: // Critical
                        $icon = '🟥';
                        $label = $this->t('Critical');
                        break;
                    case 3: // Error
                        $icon = '🔴';
                        $label = $this->t('Error');
                        break;
                    case 4: // Warning
                        $icon = '🟧';
                        $label = $this->t('Warning');
                        break;
                    case 5: // Notice
                        $icon = '🟩';
                        $label = $this->t('Notice');
                        break;
                    case 6: // Info
                        $icon = '🟩';
                        $label = $this->t('Info');
                        break;
                    case 7: // Debug
                    default:
                        $icon = '⚪️';
                        $label = $this->t('Debug');
                        break;
                }

                $items[] = [
                    '#type' => 'details',
                    '#title' => $icon . ' ' . $this->t('Log from @date', ['@date' => $date]),
                    '#open' => FALSE,
                    'content' => [
                        '#markup' => '<div class="log-message">' . $formatted->__toString() . '</div><div>' . $link . '</div>',
                    ],
                ];
            }

            $form['logs'] = [
                '#type' => 'details',
                '#title' => $this->t('Recent import logs'),
                '#open' => TRUE,
                'list' => [
                    '#theme' => 'item_list',
                    '#items' => $items ?: [$this->t('No logs yet.')],
                ],
                'more' => [
                    '#type' => 'link',
                    '#title' => $this->t('Open full log'),
                    '#url' => Url::fromUserInput('/admin/reports/dblog', [
                        'query' => ['type[]' => 'indicator_import'],
                    ]),
                    '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
                ],
            ];
        } else {
            $form['logs'] = [
                '#type' => 'details',
                '#title' => $this->t('Recent import logs'),
                '#open' => TRUE,
                'note' => [
                    '#markup' => $this->t('The Database Logging (dblog) module is disabled. Enable it to display recent logs.'),
                ],
            ];
        }

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $fids = $form_state->getValue('file');
        if (empty($fids)) {
            $form_state->setErrorByName('file', $this->t('Please upload a file.'));
            return;
        }
        $file = File::load((int) reset($fids));
        if (!$file) {
            $form_state->setErrorByName('file', $this->t('File could not be loaded.'));
            return;
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $fid = (int) ($form_state->getValue('file')[0] ?? 0);
        $file = $fid ? File::load($fid) : NULL;

        if (!$file) {
            $this->messenger()->addError($this->t('No file.'));
            return;
        }

        // Rendre permanent et sauver.
        $file->setPermanent();
        $file->save();
        $uri = $file->getFileUri();

        // (Optionnel) s’assurer que le dossier cible existe si tu déplaces ensuite :
        // \Drupal::service('file_system')->prepareDirectory(
        //   'private://imports',
        //   FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        // );

        // Batch : tous les arguments sont positionnels.
        $batch = [
            'title' => $this->t('Importing indicators…'),
            'operations' => [
                [[IndicatorImporter::class, 'batchProcess'], [$uri]],
            ],
            'finished' => [IndicatorImporter::class, 'batchFinished'],
            'progress_message' => $this->t('Processed @current of @total items…'),
            'error_message' => $this->t('Import encountered an error.'),
            'file' => \Drupal::service('extension.list.module')
                ->getPath('indicator_import_ui') . '/src/Service/IndicatorImporter.php',
        ];

        batch_set($batch);
    }
}
