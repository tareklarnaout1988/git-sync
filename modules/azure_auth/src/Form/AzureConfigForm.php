<?php

declare(strict_types=1);

namespace Drupal\azure_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure azure_auth settings for this site.
 */
final class AzureConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'azure_auth_azure_config';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['azure_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {

    $tenant_id = $this->configFactory->getEditable('azure_auth.azure_configs')->get('tenant_id');
    $client_id = $this->configFactory->getEditable('azure_auth.azure_configs')->get('client_id');
    $client_secret = $this->configFactory->getEditable('azure_auth.azure_configs')->get('client_secret');
    $userinfo_endpoint  = $this->configFactory->getEditable('azure_auth.azure_configs')->get('userinfo_endpoint');

    $form['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AD Tenant ID'),
      '#default_value' => $tenant_id,
      '#required' => TRUE,
    ];

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AD Client ID'),
      '#default_value' => $client_id,
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AD Client Secret'),
      '#default_value' => $client_secret,
      '#required' => TRUE,
    ];

    $form['userinfo_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UserInfo Endpoint'),
      '#default_value' => $userinfo_endpoint,
      '#required' => TRUE,
    ];

    $redirectUri = Url::fromRoute('azure_auth.callback', [], ['absolute' => TRUE])->toString();



    $form['redirect_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect URI'),
      '#default_value' => $redirectUri,
      '#required' => TRUE,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);
    $this->configFactory->getEditable('azure_auth.azure_configs')
    ->set('tenant_id', $form_state->getValue('tenant_id'))
    ->set('client_id', $form_state->getValue('client_id'))
    ->set('client_secret', $form_state->getValue('client_secret'))
    ->set('userinfo_endpoint', $form_state->getValue('userinfo_endpoint'))

    ->save();
    $this->messenger()->addMessage($this->t('Configuration saved successfully.'));
  }
}

