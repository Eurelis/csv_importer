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
use Exception;
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
   * Name of the table to edit.
   * @var String
   */
  private $tableName;

  /**
   * Row fields definitions of the table to edit. Not the names!
   * @var array
   */
  private $rowFields;

  /**
   * Row fields names of the table to edit.
   * @var array
   */
  private $rowFieldsNames;

  /**
   * CSV file name.
   * @var String
   */
  private $csvFileName;

  /**
   * CSV full path.
   * @var String
   */
  private $csvFullPath;

  /**
   * Indicates if the form build has errored.
   * @var boolean
   */
  private $hasBuildError = true;

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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_importer_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // recup de la structure
    $this->structure = \Drupal\csv_importer\getYmlFromCache();

    if ($this->structure == null) {
      // The yaml failed to parse.
      $form[] = ['#markup' => '<p>' . $this->t('The structure yaml file cannot be found or be parsed.') . '</p>'];

      return $form;
    }

    $this->modelName = $this->currentRouteMatch->getParameter('model');

    if (!in_array($this->modelName, array_keys($this->structure))) {
      drupal_set_message($this->t('Data model "@modelName" doesn\'t exist.', ['@modelName' => $this->modelName]), 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];
    }
    else {
      $translatedFieldNames = [];

      if (!isset($this->structure[$this->modelName]['structure_schema_version'])) {
        $this->tableName = $this->modelName;
        $this->rowFields = $this->structure[$this->modelName];

        foreach ($this->rowFields as $field) {
          $translatedFieldNames[] = $this->t($field);
          $this->rowFieldsNames[] = $field;
        }

        $this->csvFileName = $this->modelName;
      }
      else {
        switch ($this->structure[$this->modelName]['structure_schema_version']) {
          case '1':
            $this->tableName = $this->structure[$this->modelName]['table_name'];
            $this->rowFields = $this->structure[$this->modelName]['fields'];

            foreach ($this->rowFields as $field) {
              $translatedFieldNames[] = $this->t($field['name']);
              $this->rowFieldsNames[] = $field['name'];
            }

            $this->csvFileName = $this->structure[$this->modelName]['csv_file_name'];
            break;

          default:
            drupal_set_message($this->t('Unknown supplied structure_schema_version: "@version".', ['@version' => $this->structure[$this->modelName]['structure_schema_version']]), 'error');
            return $form;
        }
      }

      if (!$this->database->schema()->tableExists($this->tableName)) {
        // Table doesn't exists, so we have to prevent import action
        drupal_set_message($this->t('Table "@tableName" does not exist or does not exist anymore.', ['@tableName' => $this->tableName]), 'error');

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
        $this->csvFullPath = Drupal::config('csv_importer.structureconfig')->get('csv_base_path') . $this->csvFileName . '.csv';

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




          //ini_set('memory_limit', '256M');
          // CSV total row count
          $csvRowCount = 0;

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
            '#markup' => '<p>' . $this->t('Do you really want to import contents ( ' . $csvRowCount . ' lines) into the table <em>@tableName</em>?', ['@tableName' => $this->tableName]) . '</p>'
          ];

          $form[] = [
            '#markup' => '<h2>' . $this->t('Preview') . '</h2>'
          ];

          $previewTable = [
            '#type' => 'table',
            '#header' => $translatedFieldNames
          ];

          $previewTable['#rows'] = $data;
        }
        else {
          $form[] = [
            '#markup' => '<p>' . $this->t('There is no content to import into <em>@tableName</em>', ['@tableName' => $this->tableName]) . '</p>'
          ];
        }
        $form[] = $previewTable;
      }
    }

    $this->hasBuildError = false;

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
        drupal_set_message('The module is BUSY ', 'error');
        $form_state->setRedirect('csv_importer.home_controller_content');
        drupal_set_message($this->t('NO import  on @modelName.', ['@modelName' => $this->modelName]));
      }
      else {
        drupal_set_message('The module is FREE', 'status');
        Drupal::state()->set('csv_importer.is_busy', 1);

        // Get CSV handle
        if ($handle = fopen($this->csvFullPath, 'r')) {
          $data = [];
          $processedRowsCount = 0;

          // Transaction
          $transaction = $this->database->startTransaction();

          try {
            while (($row = fgetcsv($handle)) !== FALSE) {
              $this->validateColumnCountEquality($row, count($this->rowFieldsNames));
              
              // TODO: replace by a better inserter
              $this->database
                  ->insert($this->tableName)
                  ->fields($this->rowFieldsNames, $row)
                  ->execute();

              $processedRowsCount++;
            }

            drupal_set_message($this->t('Import of ' . $processedRowsCount . ' entrie(s) on @modelName.', ['@modelName' => $this->modelName]));

            // Log
            $this->loggerFactory->get('csv_importer')->notice($this->t('Import of ' . $processedRowsCount . ' entrie(s) on @modelName.', ['@modelName' => $this->modelName]));
          }
          catch (Exception $e) {
            $transaction->rollback();
            
            $this->loggerFactory->get('csv_importer')->error($this->t('Import of @modelName failed. The target table has not been modified. Error message: @err_mess', ['@modelName' => $this->modelName, '@err_mess' => $e]));
            drupal_set_message($this->t('Import of @modelName failed. The target table has not been modified. Error message: @err_mess', ['@modelName' => $this->modelName, '@err_mess' => $e]), 'error');

            // Unlock
            Drupal::state()->set('csv_importer.is_busy', 0);
          }

          fclose($handle);
        }

        // Redirect to csv_importer home
        $form_state->setRedirect('csv_importer.home_controller_content');

        // Unlock
        Drupal::state()->set('csv_importer.is_busy', 0);
      }
    }
    else {
      drupal_set_message('The module encountered an Error', 'error');
    }
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
   * Throws an error when the array doesn't have the expected count.
   * 
   * @param array $row
   * @param int $expectedCount
   * @throws Exception When the array doesn't have the expected count.
   */
  private function validateColumnCountEquality($array, $expectedCount, $currentRowNumber) {
    if (count($array) != $expectedCount) {
      kint($array);
      throw new Exception($this->t('Column count mismatch. The row @currentRowNumber has @colCount columns; @expectedCount expected from model.', [
        '@currentRowNumber' => $currentRowNumber,
        '@colCount' => count($array),
        '@expectedCount' => $expectedCount
      ]));
    }
  }

}
