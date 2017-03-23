<?php

namespace Drupal\csv_importer\Form;

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
 * Class PurgeForm.
 *
 * @package Drupal\csv_importer\Form
 */
class PurgeForm extends FormBase {

  /**
   * Drupal\Core\Database\Connection definition.
   *
   * @var Connection
   */
  protected $database;

  /**
   * Current route match service.
   *
   * @var CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * Logger service.
   * @var LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Current user obtained from service.
   * @var AccountProxy
   */
  protected $currentUser;

  /**
   * Name of the model to edit.
   * @var String
   */
  private $modelName;

  /**
   * Object containing the model values.
   * @var Model
   */
  private $model;

  /**
   * Indicates if the form build has errored.
   * @var boolean
   */
  private $hasBuildError = true;

  public function __construct(
  Connection $database, CurrentRouteMatch $current_route_match, LoggerChannelFactory $logger_factory, AccountProxy $current_user
  ) {
    $this->database = $database;
    $this->currentRouteMatch = $current_route_match;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('database'), $container->get('current_route_match'), $container->get('logger.factory'), $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csv_importer_purge_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Parse YML file to get table names
    $structure = CsvImporterHelper::getYmlFromCache();

    if ($structure == null) {
      // The yaml failed to parse.
      $form[] = ['#markup' => '<p>' . $this->t('The structure yaml file cannot be found or be parsed.') . '</p>'];

      return $form;
    }

    $this->modelName = $this->currentRouteMatch->getParameter('model');

    $form = [];

    if (!in_array($this->modelName, array_keys($structure))) {
      drupal_set_message($this->t('Data model "@modelName" doesn\'t exist.', ['@modelName' => $this->modelName]), 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];

      $this->hasBuildError = false;
      return $form;
    }

    $this->model = new Model($structure, $this->modelName);

    switch ($this->model->initializationState) {
      case Model::INIT_VALID:
        break;

      default:
        drupal_set_message($this->model->message, 'error');
        return $form;
    }

    if (!$this->database->schema()->tableExists($this->model->tableName)) {
      // Table doesn't exists, so we have to prevent purge action
      drupal_set_message($this->t('Table "@tableName" does not exist or does not exist anymore.', ['@tableName' => $this->model->tableName]), 'error');

      $form[] = [
        '#type' => 'link',
        '#title' => $this->t('Go back'),
        '#url' => Url::fromRoute('csv_importer.home_controller_content')
      ];

      $this->hasBuildError = false;
      return $form;
    }

    // Table exists, allow purge action
    try {
      $query = $this->database
          ->query('SELECT COUNT(*) AS count FROM ' . $this->model->tableName . ';');
      $query->allowRowCount = TRUE;
      $numRows = $query->fetchObject()->count;

      if ($numRows == 0) {
        $form[] = [
          '#markup' => '<p>' . $this->t('<em>@tableName</em> is empty', ['@tableName' => $this->model->tableName]) . '</p>'
        ];
      }
      else {
        $form[] = [
          '#markup' => '<p>' . $this->t('Do you really want to purge ( ' . $numRows . ' entries) contents of the table <em>@tableName</em>?', ['@tableName' => $this->model->tableName]) . '</p>'
        ];

        $form['purge'] = [
          '#type' => 'submit',
          '#value' => $this->t('Purge'),
          '#description' => $this->t('Purge this content'),
        ];
      }
      $form['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Go back'),
        '#validate' => ['\Drupal\csv_importer\Form\PurgeForm::validateCancelledForm'],
        '#submit' => ['\Drupal\csv_importer\Form\PurgeForm::submitCancelledForm']
      ];

      $this->hasBuildError = false;
    }
    catch (Exception $e) {
      drupal_set_message($this->t('An unknown error occured. Error message: @errmess', ['@errmess' => $e]));
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->hasBuildError) {
      // To investigate: is this really triggered somewhere?...
      $form_state->setErrorByName('', $this->t('An error while building the form has been detected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->model->purge($this->database);

    switch ($this->model->processingState) {
      case Model::PROC_SUCCESS:
        drupal_set_message($this->model->message);
        $this->loggerFactory->get('csv_importer')->notice($this->model->message);
        break;

      default:
        drupal_set_message($this->model->message, 'error');
        $this->loggerFactory->get('csv_importer')->error($this->model->message);
        break;
    }

    // Redirect to module home
    $form_state->setRedirect('csv_importer.home_controller_content');
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

}
