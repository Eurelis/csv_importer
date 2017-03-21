<?php

namespace Drupal\csv_importer\Form;

use Drupal\file\Entity ;
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
   * Array containing unique keys.
   * @var array
   */
  private $uniqueKeys;

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
      $mode_temporary = 0 ;
      $this->temp_file_id = $this->currentRouteMatch->getParameter('temp_file_id');

    if ($this->temp_file_id != NULL && is_numeric($this->temp_file_id )) {
        $mode_temporary = 1 ;
    }
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

              if (isset($field['unique'])) {
                // This field is a unique key
                $this->uniqueKeys[] = $field['name'];
              }
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


          if (ISSET($mode_temporary) && $mode_temporary == 1) {

              $file = \Drupal\file\Entity\File::load($this->temp_file_id);
              $path = $file->getFileUri();
              $this->csvFullPath = $path ;
              // kint($this->csvFullPath);
          } else {
              // Get CSV full path
              $this->csvFullPath = realpath(Drupal::config('csv_importer.structureconfig')->get('csv_base_path') . $this->csvFileName . '.csv');
          }
        if ($this->csvFullPath === FALSE) {
          $form[] = [
            '#markup' => '<p>' . $this->t('CSV file not found.') . '</p>'
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
              '@tableName' => $this->tableName]) . '</p>'
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
            '#markup' => '<p>' . $this->t('Couldn\'t open this file: <em>@csvFullPath</em>', ['@csvFullPath' => $this->csvFullPath]) . '</p>'
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
        drupal_set_message('Cannot import a CSV file for now. There is already an ongoing operation.', 'warning');
        $form_state->setRedirect('csv_importer.home_controller_content');
      }
      else {
        // Lock import
        $this->lockImport();

        // Get CSV handle
        if ($handle = fopen($this->csvFullPath, 'r')) {
          $processedRowsCount = 0;

          // Transaction
          $transaction = $this->database->startTransaction();

          try {
            $startTime = microtime(true);
            set_time_limit(0);

            // Get fields names as a SQL list
            $fieldsNamesAsSqlList = '';

            foreach ($this->rowFieldsNames as $s) {
              $fieldsNamesAsSqlList .= $s . ',';
            }

            // No field to write in => nothing to do
            if (count($fieldsNamesAsSqlList) == 0) {
              drupal_set_message($this->t('No field to write in. Please check your structure.yml file.'));
            }
            else {
              // Remove last comma
              $fieldsNamesAsSqlList = rtrim($fieldsNamesAsSqlList, ',');

              while (true) {
                // Query string to build
                $queryString = "INSERT INTO $this->tableName($fieldsNamesAsSqlList) VALUES";

                $values = '';

                // Get each row and insert its values into $values
                $currentValuesCount = 0;

                // Used to uniquely identify a placeholder
                $currentParamCount = 0;

                // Stores params to bind as an associative array placeholder_id => $value
                $paramsToBind = [];

                while ($currentValuesCount < 100) {
                  if (!(($row = fgetcsv($handle)) !== FALSE)) {
                    break;
                  }

                  $queryString .= '(';

                  foreach ($row as $v) {
                    $queryString .= ':ph_' . $processedRowsCount . '_' . $currentParamCount . ',';
                    $paramsToBind[':ph_' . $processedRowsCount . '_' . $currentParamCount] = $v;
                    $currentParamCount++;
                  }

                  $queryString = rtrim($queryString, ',');

                  $queryString .= '),';

                  $currentValuesCount++;

                  $processedRowsCount++;
                }

                // Break if no value is added
                if ($currentValuesCount == 0) {
                  break;
                }

                $queryString = rtrim($queryString, ',');

                // Unique keys
                if (count($this->uniqueKeys) != 0) {
                  // Add unique keys here
                  $queryString .= ' ON DUPLICATE KEY UPDATE';

                  foreach ($this->uniqueKeys as $uk) {
                    $queryString .= " $uk = VALUES($uk),";
                  }

                  // Remove trailing comma
                  $queryString = rtrim($queryString, ',');
                }

                $queryString .= ';';

                $statement = $this->database->prepare($queryString);

                $statement->execute($paramsToBind);
              }

              $totalTime = microtime(true) - $startTime;

              $message = $this->t('Import of @processedRowsCount entrie(s) on @modelName in @sec seconds.', [
                '@processedRowsCount' => $processedRowsCount,
                '@modelName' => $this->modelName,
                '@sec' => $totalTime
              ]);

              drupal_set_message($message);

              // Log
              $this->loggerFactory->get('csv_importer')->notice($message);
            }
          }
          catch (Exception $e) {
            $transaction->rollback();

            $this->loggerFactory->get('csv_importer')->error($this->t('Import of @modelName failed. The target table has not been modified. Error message: @err_mess', ['@modelName' => $this->modelName, '@err_mess' => $e]));
            drupal_set_message($this->t('Import of @modelName failed. The target table has not been modified. Error message: @err_mess', ['@modelName' => $this->modelName, '@err_mess' => $e]), 'error');

            // Unlock
            $this->unlockImport();
          }

          fclose($handle);
        }

        // Redirect to csv_importer home
        $form_state->setRedirect('csv_importer.home_controller_content');

        // Unlock
        $this->unlockImport();
      }
    }
    else {
      drupal_set_message('The module encountered an Error', 'error');
    }
  }

  private function lockImport() {
    Drupal::state()->set('csv_importer.is_busy', 1);
  }

  private function unlockImport() {
    Drupal::state()->set('csv_importer.is_busy', 0);
  }

}
