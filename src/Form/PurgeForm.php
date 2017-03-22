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
   * Name of the module which contains the model to edit.
   * @var String
   */
  private $moduleName;

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
    }
    else {
      if (!isset($structure[$this->modelName]['structure_schema_version'])) {
        $this->tableName = $this->modelName;
      }
      else {
        switch ($structure[$this->modelName]['structure_schema_version']) {
          case '1':
            $this->tableName = $structure[$this->modelName]['table_name'];
            break;

          default:
            drupal_set_message($this->t('Unknown supplied structure_schema_version: "@version".', ['@version' => $structure[$this->modelName]['structure_schema_version']]), 'error');
            return $form;
        }
      }

      if (!$this->database->schema()->tableExists($this->tableName)) {
        // Table doesn't exists, so we have to prevent purge action
        drupal_set_message($this->t('Table "@tableName" does not exist or does not exist anymore.', ['@tableName' => $this->tableName]), 'error');

        $form[] = [
          '#type' => 'link',
          '#title' => $this->t('Go back'),
          '#url' => Url::fromRoute('csv_importer.home_controller_content')
        ];
      }
      else {
        // Table exists, allow purge action
        $query = $this->database
            ->query('SELECT COUNT(*) AS count FROM ' . $this->tableName . ';');
        $query->allowRowCount = TRUE;
        $numRows = $query->fetchObject()->count;

        if ($numRows == 0) {
          $form[] = [
            '#markup' => '<p>' . $this->t('<em>@tableName</em> is empty', ['@tableName' => $this->tableName]) . '</p>'
          ];
        }
        else {
          $form[] = [
            '#markup' => '<p>' . $this->t('Do you really want to purge ( ' . $numRows . ' entries) contents of the table <em>@tableName</em>?', ['@tableName' => $this->tableName]) . '</p>'
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
      }
    }

    $this->hasBuildError = false;

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
    // Purge
    $query = $this->database->delete($this->tableName);

    $entriesCount = $query->execute();

    drupal_set_message($this->t('Table @tableName has been purged. (@entriesCount entries removed)', ['@tableName' => $this->tableName, '@entriesCount' => $entriesCount]));

    // Redirect to module home
    $form_state->setRedirect('csv_importer.home_controller_content');

    // Log
    $this->loggerFactory->get('csv_importer')->notice($this->t('Table @tableName has been purged.', ['@tableName' => $this->tableName]));
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
