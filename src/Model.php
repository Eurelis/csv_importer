<?php

namespace Drupal\csv_importer;

use Drupal;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\file\Entity\File;
use Exception;

/**
 * Convenience class which stores data related to an item from the structure.yml file.
 */
class Model {
  /* Initialization states */

  const INIT_UNINITIALIZED = 'INIT_UNINITIALIZED';
  const INIT_INVALID = 'INIT_INVALID';
  const INIT_VALID = 'INIT_VALID';

  /* Processing states */
  const PROC_UNPROCESSED = 'PROC_UNPROCESSED';
  const PROC_ERROR = 'PROC_ERROR';
  const PROC_WARNING = 'PROC_WARNING';
  const PROC_SUCCESS = 'PROC_SUCCESS';

  /**
   * Name of the model to edit.
   * @var String
   */
  public $modelName;

  /**
   * Name of the table to edit.
   * @var String
   */
  public $tableName;

  /**
   * Row fields definitions of the table to edit. Not the names!
   * @var array
   */
  public $rowFields;

  /**
   * Row fields names of the table to edit.
   * @var array
   */
  public $rowFieldsNames;

  /**
   * Translated row fields names of the table to edit.
   * @var array
   */
  public $translatedFieldNames;

  /**
   * CSV file name.
   * @var String
   */
  public $csvFileName;

  /**
   * Array containing unique keys.
   * @var array
   */
  public $uniqueKeys;

  /**
   * File entity for temporary files uploads.
   * @var File
   */
  private $fileEntity;
  private $isCsvFileToImportTemporary = false;

  /**
   * Id of the temporary file, for uploaded file.
   * @var int
   */
  private $temporaryFileId;

  /**
   * Tells if this model has been successfully initialized.
   * 
   * Possible values:
   * - Model::INIT_UNINITIALIZED (default)
   * - Model::INIT_INVALID
   * - Model::INIT_VALID
   * 
   * @var String 
   */
  public $initializationState = self::INIT_UNINITIALIZED;

  /**
   * Tells if this model has been successfully processed.
   * 
   * Possible values:
   * - Model::PROC_UNPROCESSED (default)
   * - Model::PROC_ERROR
   * - Model::PROC_WARNING
   * - Model::PROC_SUCCESS
   * 
   * @var String 
   */
  public $processingState = self::PROC_UNPROCESSED;

  /**
   * Message for notice, error or warning.
   * @var String 
   */
  public $message;

  /**
   * Contains values of an item from the structure.yml file.
   * @param array $structure The structure array (from cache).
   * @param type $modelName The name of the model to load.
   */
  public function __construct($structure, $modelName, $temporaryFileId = -1) {
    $this->modelName = $modelName;

    if (!isset($structure[$this->modelName]['structure_schema_version'])) {
      $this->tableName = $this->modelName;
      $this->rowFields = $structure[$this->modelName];

      foreach ($this->rowFields as $field) {
        $this->translatedFieldNames[] = t($field);
        $this->rowFieldsNames[] = $field;
      }

      $this->csvFileName = $this->modelName;
    }
    else {
      switch ($structure[$this->modelName]['structure_schema_version']) {
        case '1':
          $this->tableName = $structure[$this->modelName]['table_name'];
          $this->rowFields = $structure[$this->modelName]['fields'];

          foreach ($this->rowFields as $field) {
            $this->translatedFieldNames[] = t($field['name']);
            $this->rowFieldsNames[] = $field['name'];

            if (isset($field['unique'])) {
              // This field is a unique key
              $this->uniqueKeys[] = $field['name'];
            }
          }

          $this->csvFileName = $structure[$this->modelName]['csv_file_name'];
          break;

        default:
          $message = t('Unknown supplied structure_schema_version: "@version".', ['@version' => $structure[$this->modelName]['structure_schema_version']]);
          $this->initializationState = self::INIT_INVALID;
          $this->message = $message;
          return;
      }
    }

    if ($temporaryFileId > -1) {
      $this->isCsvFileToImportTemporary = true;
      $this->temporaryFileId = $temporaryFileId;

      // Look for a temporary file
      $this->fileEntity = \Drupal\file\Entity\File::load($temporaryFileId);

      if ($this->fileEntity && file_exists($this->fileEntity->getFileUri())) {
        // The temporary file exists
        $this->message = t('Your CSV has been uploaded and will be used for @modelName', ['@modelName' => $this->modelName]);
      }
      else {
        // The temporary file does not exist
        $this->initializationState = self::INIT_INVALID;
        $this->message = t('Couldn\'t find the temporary file you just have uploaded.');

        return;
      }
    }

    $this->initializationState = self::INIT_VALID;
  }

  /**
   * Imports this model into its corresponding table.
   * @param Connection $connection Connection object which handles the database operations.
   */
  public function import(Connection $connection) {
    if ($connection == null) {
      $this->processingState = self::PROC_ERROR;
      $this->message = t('No Connection object provided.');
      return;
    }

    // Lock check
    $appIsBusy = Drupal::state()->get('csv_importer.is_busy');

    if ($appIsBusy == 1) {
      $this->processingState = self::PROC_WARNING;
      $this->message = t('Cannot import a CSV file for now. There is already an ongoing operation.');
      return;
    }

    // Lock import
    $this->lockImport();

    // Get CSV handle
    $filePath = $this->getCsvFullPath();

    if ($handle = fopen($filePath, 'r')) {
      $processedRowsCount = 0;

      // Transaction
      $transaction = $connection->startTransaction();

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
          $this->processingState = self::PROC_ERROR;
          $this->message = t('No field to write in. Please check your structure.yml file.');
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

            $statement = $connection->prepare($queryString);

            $statement->execute($paramsToBind);
          }

          $totalTime = microtime(true) - $startTime;

          $message = t('Import of @processedRowsCount entrie(s) from model "@modelName" in @sec seconds.', [
            '@processedRowsCount' => $processedRowsCount,
            '@modelName' => $this->modelName,
            '@sec' => $totalTime
          ]);

          $this->processingState = self::PROC_SUCCESS;
          $this->message = $message;
        }
      }
      catch (Exception $e) {
        $transaction->rollback();

        $message = t('Import of @modelName failed. The target table has not been modified. Error message: @err_mess', [
          '@modelName' => $this->modelName, '@err_mess' => $e
        ]);

        $this->processingState = self::PROC_ERROR;
        $this->message = $message;
      }

      fclose($handle);
    }

    // Unlock
    $this->unlockImport();
  }
  
  /**
   * Imports this model into its corresponding table.
   * @param Connection $connection Connection object which handles the database operations.
   */
  public function purge(Connection $connection) {
    try {
      // Purge
      $query = $this->database->delete($this->tableName);

      $entriesCount = $query->execute();

      $this->processingState = self::PROC_SUCCESS;
      $this->message = t('Table @tableName has been purged. (@entriesCount entries removed)', ['@tableName' => $this->tableName, '@entriesCount' => $entriesCount]);
    }
    catch (Exception $e) {
      $this->processingState = self::PROC_ERROR;
      $this->message = t('An unknown error occured. Error message: @errmess', ['@errmess' => $e]);
    }
  }

  /**
   * CSV full path.
   * @return mixed Returns false if the path is wrong, else returns a string.
   */
  public function getCsvFullPath() {
    if ($this->initializationState != self::INIT_VALID) {
      return false;
    }

    if ($this->isCsvFileToImportTemporary) {
      return $this->fileEntity->getFileUri();
    }

    return realpath(Drupal::config('csv_importer.structureconfig')->get('csv_base_path') . $this->csvFileName . '.csv');
  }

  private function lockImport() {
    Drupal::state()->set('csv_importer.is_busy', 1);
  }

  private function unlockImport() {
    Drupal::state()->set('csv_importer.is_busy', 0);
  }

}
