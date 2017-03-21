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
use Drupal\file\Entity ;

/**
 * Class CsvImporterImportForm.
 *
 * @package Drupal\csv_importer\Form
 */
class UploadForm extends FormBase {

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
    return 'csv_importer_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

      $this->modelName = $this->currentRouteMatch->getParameter('model');

      $form['uploaded_file'] = array(
          '#type' => 'managed_file',
          '#title' => t('Upload your file of "@modelName"', ['@modelName' => $this->modelName]),
          '#required' => true,
          '#upload_validators' => array(
              'file_validate_extensions' => array('csv'),
          ),
          '#upload_location' => 'public://',
      );

        // Table exists
        $form['cancel'] = [
          '#type' => 'submit',
          '#value' => $this->t('Cancel'),
          '#validate' => ['\Drupal\csv_importer\Form\UploadForm::validateCancelledForm'],
          '#submit' => ['\Drupal\csv_importer\Form\UploadForm::submitCancelledForm']
        ];

          $form['import'] = [
            '#type' => 'submit',
            '#value' => $this->t('Upload'),
            '#description' => $this->t('Upload')
          ];


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
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
      $this->modelName = $this->currentRouteMatch->getParameter('model');
      $validators = array(
          'file_validate_extensions' => array('csv'),
      );
     // $temp_file = file_save_upload('uploaded_file', $validators);
      $fid = $form_state->getValue('uploaded_file');

      // $file = \Drupal\file\Entity\File::load(reset($fid));


      $url = \Drupal\Core\Url::fromRoute('csv_importer.import_form', ['model' => $this->modelName, 'temp_file_id' => $fid[0]] );

      return $form_state->setRedirectUrl($url);




  }

  private function lockImport() {
    Drupal::state()->set('csv_importer.is_busy', 1);
  }

  private function unlockImport() {
    Drupal::state()->set('csv_importer.is_busy', 0);
  }

}
