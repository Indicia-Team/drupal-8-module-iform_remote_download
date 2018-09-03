<?php

/**
 * @file
 * Contains \Drupal\iform\Form\SettingsForm.
 */

namespace Drupal\iform_remote_download\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Indicia settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'iform_remote_download_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = \Drupal::config('iform_remote_download.settings');

    $form['appsecret'] = [
      '#type' => 'textfield',
      '#title' => t('Appsecret'),
      '#description' => t('Application secret.'),
      '#required' => TRUE,
      '#default_value' => $config->get('appsecret'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::configFactory()->getEditable('iform_remote_download.settings');
    $values = $form_state->getValues();

    $config->set('appsecret', $values['appsecret']);

    $config->save();
    drupal_set_message(t('Indicia remote download settings saved.'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'iform_remote_download.settings',
    ];
  }

}
