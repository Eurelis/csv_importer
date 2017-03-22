<?php

namespace Drupal\csv_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\csv_importer\CsvImporterHelper;

/**
 * Class ConfigForm.
 *
 * @package Drupal\csv_importer\Form
 */
class ConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'csv_importer.config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_importer_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('csv_importer.structureconfig');

    if (!CsvImporterHelper::isYmlInCache()) {
      $warning = t('Failed to parse structure YAML file. Please put the file at this location or change the file location.');
    }

    $form['yml_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('YML Location'),
      '#default_value' => $this->config('csv_importer.structureconfig')->get('yml_location'),
      '#description' => $this->t('Important: Set a private location or your YAML structure file may be exposed to the public!'),
      '#suffix' => $warning
    ];

    $form['csv_base_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSV files base path'),
      '#default_value' => $this->config('csv_importer.structureconfig')->get('csv_base_path'),
      '#description' => $this->t('Important: Set a private location or your CSV files may be exposed to the public!')
    ];

    $form['preview_length'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview length'),
      '#default_value' => $this->config('csv_importer.structureconfig')->get('preview_length'),
      '#description' => $this->t('This value controls how many entries from a CSV file are rendered in the preview section when importing. A reasonable amount is 20.')
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$this->validateNumericValue($form_state->getValue('preview_length'))) {
      $form_state->setErrorByName('preview_length', $this->t('The preview length value is not numeric.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $editableConfig = $this->configFactory->getEditable('csv_importer.structureconfig');
    $editableConfig->set('yml_location', $form_state->getValue('yml_location'));
    $editableConfig->set('csv_base_path', $form_state->getValue('csv_base_path'));
    $editableConfig->set('preview_length', $form_state->getValue('preview_length'));

    $editableConfig->save();


    // Reimport YAML file
    CsvImporterHelper::refreshYmlFromCache();
  }

  private function validateNumericValue($value) {
    return is_numeric($value);
  }

}
