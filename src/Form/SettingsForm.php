<?php

namespace Drupal\icon_businessos\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * ICON BusinessOS admin settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['icon_businessos.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'icon_businessos_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('icon_businessos.settings');
    $is_registered = !empty($config->get('silo_api_key'));

    // Status display
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Status'),
      '#open' => TRUE,
    ];

    if ($is_registered) {
      $last_score = \Drupal::state()->get('icon_businessos.last_heartbeat_score', '—');
      $form['status']['info'] = [
        '#markup' => '<p><strong>' . $this->t('Status:') . '</strong> ✅ ' . $this->t('Connected') . '</p>'
          . '<p><strong>' . $this->t('Tenant ID:') . '</strong> <code>' . $config->get('tenant_id') . '</code></p>'
          . '<p><strong>' . $this->t('Registered:') . '</strong> ' . $config->get('registered_at') . '</p>'
          . '<p><strong>' . $this->t('Last Health Score:') . '</strong> ' . $last_score . '/100</p>',
      ];
    }
    else {
      $form['status']['info'] = [
        '#markup' => '<p><strong>' . $this->t('Status:') . '</strong> ❌ ' . $this->t('Not Registered') . '</p>',
      ];
    }

    // Settings
    $form['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant ID'),
      '#default_value' => $config->get('tenant_id'),
      '#description' => $this->t('Your BusinessOS tenant identifier. Provided during onboarding.'),
      '#required' => TRUE,
    ];

    $form['fleet_master_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Fleet Master URL'),
      '#default_value' => $config->get('fleet_master_url') ?: 'https://os.theicon.ai/api/silo',
      '#description' => $this->t('Default: https://os.theicon.ai/api/silo — only change for testing.'),
    ];

    $form['phone_home_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Phone Home Interval'),
      '#options' => [
        5 => $this->t('5 minutes'),
        10 => $this->t('10 minutes'),
        15 => $this->t('15 minutes (default)'),
        30 => $this->t('30 minutes'),
        60 => $this->t('60 minutes'),
      ],
      '#default_value' => $config->get('phone_home_interval') ?: 15,
    ];

    // Register button
    if (!$is_registered && $config->get('tenant_id')) {
      $form['register'] = [
        '#type' => 'submit',
        '#value' => $this->t('Register with Fleet Master'),
        '#submit' => ['::registerSubmit'],
        '#weight' => 100,
      ];
    }

    // Manual heartbeat button
    if ($is_registered) {
      $form['heartbeat'] = [
        '#type' => 'submit',
        '#value' => $this->t('Send Heartbeat Now'),
        '#submit' => ['::heartbeatSubmit'],
        '#weight' => 101,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('icon_businessos.settings')
      ->set('tenant_id', $form_state->getValue('tenant_id'))
      ->set('fleet_master_url', $form_state->getValue('fleet_master_url'))
      ->set('phone_home_interval', $form_state->getValue('phone_home_interval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Register submit handler.
   */
  public function registerSubmit(array &$form, FormStateInterface $form_state) {
    // Save settings first
    $this->submitForm($form, $form_state);

    /** @var \Drupal\icon_businessos\Service\PhoneHome $phone_home */
    $phone_home = \Drupal::service('icon_businessos.phone_home');
    $result = $phone_home->register();

    if ($result['success']) {
      $this->messenger()->addStatus($this->t('Successfully registered with fleet master!'));
    }
    else {
      $this->messenger()->addError($this->t('Registration failed: @error', ['@error' => $result['error']]));
    }
  }

  /**
   * Heartbeat submit handler.
   */
  public function heartbeatSubmit(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\icon_businessos\Service\PhoneHome $phone_home */
    $phone_home = \Drupal::service('icon_businessos.phone_home');
    $result = $phone_home->execute();

    if ($result['success']) {
      $this->messenger()->addStatus($this->t('Heartbeat sent. Score: @score/100', ['@score' => $result['score']]));
    }
    else {
      $this->messenger()->addError($this->t('Heartbeat failed: @error', ['@error' => $result['error']]));
    }
  }

}
