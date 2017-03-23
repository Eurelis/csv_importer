<?php

use Drupal\csv_importer\CsvImporterHelper;

/**
 * Implements hook_drush_command().
 */
function csv_importer_drush_command() {
  $items['csv-importer-import-all'] = [
    'description' => 'Imports all models defined in structure.yml.',
    'aliases' => ['csvimpall']
  ];
  
  $items['csv-importer-import'] = [
    'description' => 'Imports all models defined in structure.yml.',
    'aliases' => ['csvi', 'csvimp'],
    'arguments' => [
      'model' => 'The name of the model to import.'
    ],
    'options' => [
      'log-type' => 'Choose one of the following: none, message, log, message+log'
    ]
  ];

  return $items;
}

/**
 * Callback for the dcsv-importer-import-all command
 */
function drush_csv_importer_import_all() {
  CsvImporterHelper::importAllModels();
}

/**
 * Callback for the csv-importer-import command
 */
function drush_csv_importer_import($model = null) {
  if($model == null) {
    drupal_set_message(t('Missing argument "model". Use drush csv-import-all to import all models or set the model argument of this command.'), 'error');
  }
  
  $logType = drush_get_option('log-type', 'message');
  
  switch($logType) {
    case 'none':
      CsvImporterHelper::$logType = CsvImporterHelper::LOG_NONE;
      break;
    
    case 'log':
      CsvImporterHelper::$logType = CsvImporterHelper::LOG_LOGGER_FACTORY;
      break;
    
    case 'message+log':
      CsvImporterHelper::$logType = CsvImporterHelper::LOG_DRUPAL_SET_MESSAGE_AND_LOGGER_FACTORY;
      break;
    
    case 'message':
      CsvImporterHelper::$logType = CsvImporterHelper::LOG_DRUPAL_SET_MESSAGE;
      break;
    
    default:
      drupal_set_message(t('Unknown log-type option. Operation aborted. Input was: @val', ['@val' => $logType]));
      return;
  }
  
  CsvImporterHelper::importSingleModel($model);
}
