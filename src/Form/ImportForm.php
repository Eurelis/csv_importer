<?php

namespace Drupal\csv_importer\Form;

use Drupal;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Url;
use Drupal\csv_importer\CsvImporterHelper;
use Drupal\csv_importer\Model;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class CsvImporterImportForm.
 *
 * @package Drupal\csv_importer\Form
 */
class ImportForm extends FormBase {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var Connection
   */
  protected $database;

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var AccountProxy
   */
  protected $currentUser;

  /**
   * Logger service.
   * @var LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Current route match service.
   * @var CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * Structures array copy from cache.
   * @var array
   */
  private $structure;

  /**
   * Name of the model to edit.
   * @var String
   */
  private $modelName;

  /**
   * CSV full path.
   * @var String
   */
  private $csvFullPath;

  /**
   * Object containing the values of the model to process.
   * @var Model
   */
  private $model;

  public function __construct(Connection $database, AccountProxy $current_user, LoggerChannelFactory $loggerFactory, CurrentRouteMatch $current_route_match) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->loggerFactory = $loggerFactory;
    $this->currentRouteMatch = $current_route_match;
  }

  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('database'), $container->get('current_user'), $container->get('logger.factory'), $container->get('current_route_match')
    );
  }

  /**
   * Validate callback on form cancellation.
   * This callback is static: call_user_func_array() expects static methods.
   */
  public static function validateCancelledForm(array &$form, FormStateInterface $form_state) {
    
  }

  /**
   * Submit callback on form cancellation.
   * This callback is static: call_user_func_array() expects static methods.
   */
  public static function submitCancelledForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('csv_importer.home_controller_content');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_importer_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the GET PARAMS
    $mode_temporary = 0;
    $temp_file_id = $this->currentRouteMatch->getParameter('temp_file_id');
    $this->modelName = $this->currentRouteMatch->getParameter('model');

    // Retrieve the YAML STRUCTURE
    $this->structure = CsvImporterHelper::getYmlFromCache();

    // Check STRUCTURE
    if ($this->structure == null) {
      // The yaml failed to parse.
      $form[] = ['#markup' => '<p>' . $this->t('The structure yaml file cannot be found or be parsed.') . '</p>'];

      return $form;
    }

    if (!in_array($this->modelName, array_keys($this->structure))) {
      drupal_set_message($this->t('Data model "@modelName" doesn\'t exist.', ['@modelName' => $this->modelName]), 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];

      return $form;
    }
    if ($temp_file_id && $temp_file_id > -1) {
      $this->model = new Drupal\csv_importer\Model($this->structure, $this->modelName, $temp_file_id);
    }
    else {
      $this->model = new Drupal\csv_importer\Model($this->structure, $this->modelName);
    }

    if ($this->model->initializationState != Drupal\csv_importer\Model::INIT_VALID) {
      drupal_set_message($this->model->message, 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];

      return $form;
    }
    elseif ($this->model->message != '') {
      drupal_set_message($this->model->message, 'status');
    }

    if (!$this->database->schema()->tableExists($this->model->tableName)) {
      // Table doesn't exists, so we have to prevent import action
      drupal_set_message($this->t('Table "@tableName" does not exist or does not exist anymore.', ['@tableName' => $this->model->tableName]), 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];
    }
    else {
      // Table exists
      $form['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#validate' => ['\Drupal\csv_importer\Form\ImportForm::validateCancelledForm'],
        '#submit' => ['\Drupal\csv_importer\Form\ImportForm::submitCancelledForm']
      ];

      // Get CSV full path
      $this->csvFullPath = $this->model->getCsvFullPath();

      if ($this->csvFullPath === FALSE) {
        $form[] = [
          '#markup' => '<p>' . $this->t('CSV file not found on the server.') . '</p>'
        ];

        return $form;
      }

      // Parse data CSV and display first lines
      $previewLength = Drupal::config('csv_importer.structureconfig')->get('preview_length');

      if ($previewLength == null) {
        // Config variable is unexpectedly unset
        $previewLength = 20;
        drupal_set_message($this->t('Couldn\'t retrieve preview_length config variable. Previewing @previewLength values.', ['@previewLength' => $previewLength]));
      }

      if ($handle = fopen($this->csvFullPath, 'r')) {
        $data = [];
        $processedRowsCount = 0;

        while (($row = fgetcsv($handle)) !== FALSE && $processedRowsCount < $previewLength) {
          $data[] = $row;

          $processedRowsCount++;
        }

        // CSV total row count
        $csvRowCount = 1;

        // Rewind the pointer
        rewind($handle);

        while (!feof($handle)) {
          $chunk = fgets($handle, 4096);
          $csvRowCount += substr_count($chunk, PHP_EOL);
        }

        fclose($handle);

        // Display data as table
        $form['import'] = [
          '#type' => 'submit',
          '#value' => $this->t('Import'),
          '#description' => $this->t('Import')
        ];

        $form[] = [
          '#markup' => '<p>' . $this->t('Do you really want to import contents (@csvRowCount lines) into the table <em>@tableName</em>?', [
            '@csvRowCount' => $csvRowCount,
            '@tableName' => $this->model->tableName]) . '</p>'
        ];

        $form[] = [
          '#markup' => '<h2>' . $this->t('Preview') . '</h2>'
        ];

        $previewTable = [
          '#type' => 'table',
          '#header' => $this->model->translatedFieldNames
        ];

        $previewTable['#rows'] = $data;
      }
      else {
        $form[] = [
          '#markup' => '<p>' . $this->t('Couldn\'t open this file: <em>@csvFullPath</em>', ['@csvFullPath' => $this->csvFullPath]) . '</p>'
        ];
      }
      $form[] = $previewTable;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->structure) {
      // Lock check
      $appIsBusy = Drupal::state()->get('csv_importer.is_busy');

      if ($appIsBusy == 1) {
        drupal_set_message('Cannot import a CSV file for now. There is already an ongoing operation.', 'warning');
        $form_state->setRedirect('csv_importer.home_controller_content');
      }
      else {
        // Lock import
        $this->model->import($this->database);

        switch ($this->model->processingState) {
          case Model::PROC_ERROR:
            drupal_set_message($this->model->message, 'error');
            break;

          case Model::PROC_WARNING:
            drupal_set_message($this->model->message, 'warning');
            break;

          case Model::PROC_SUCCESS:
            if ($this->model->message != '') {
              drupal_set_message($this->model->message);
            }
            break;

          default:
            $this->loggerFactory->critical(t('An unexpected error prevented the processing of the model "@model". Please contact the developer.', ['@model' => $this->modelName]));
            break;
        }

        // Redirect to csv_importer home
        $form_state->setRedirect('csv_importer.home_controller_content');
      }
    }
    else {
      drupal_set_message('The module encountered an Error', 'error');
    }
  }

}
