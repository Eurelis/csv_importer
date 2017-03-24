<?php

namespace Drupal\csv_importer\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\csv_importer\Model;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\csv_importer\CsvImporterHelper;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class ViewRecordsController.
 *
 * @package Drupal\csv_importer\Controller
 */
class ViewRecordsController extends ControllerBase {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var Connection
   */
  protected $database;

  /**
   * Current route match service.
   * @var CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, CurrentRouteMatch $current_route_match) {
    $this->database = $database;
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('database'), $container->get('current_route_match')
    );
  }

  /**
   * Content.
   *
   * @return string
   *   Return Hello string.
   */
  public function content($model) {
    $this->structure = CsvImporterHelper::getYmlFromCache();
    $this->modelName = $this->currentRouteMatch->getParameter('model');
    $build = [];

    if ($this->structure == null) {
      // The yaml failed to parse.
      drupal_set_message($this->t('The structure data couldn\'t be found.'), 'error');
      return [];
    }

    if (!in_array($this->modelName, array_keys($this->structure))) {
      drupal_set_message($this->t('Data model "@modelName" doesn\'t exist.', ['@modelName' => $this->modelName]), 'error');
      return [];
    }

    $model = new Model($this->structure, $this->modelName);

    switch ($model->initializationState) {
      case Model::INIT_VALID:
        break;

      case Model::INIT_INVALID:
        drupal_set_message($model->message, 'error');
        return [];

      default:
        drupal_set_message(t('Unknown error occurred on Model initialization.'), 'error');
        return [];
    }

    $model->retrieveContent($this->database);

    switch ($model->processingState) {
      case Model::PROC_SUCCESS:
        if ($model->recordsCountInTable == 0) {
          $build = [
            '#markup' => $model->message
          ];

          return $build;
        }

        // Prepare table header
        $header = $model->translatedFieldNames;

        $rows = [];

        // Fill the data array with the data
        foreach ($model->result as $row) {
          $rows[] = ['data' => (array) $row];
        }

        // Init of the markup render array
        $build = [
          '#markup' => $model->message
        ];

        // The table to render
        $build['location_table'] = array(
          '#theme' => 'table',
          '#header' => $header,
          '#rows' => $rows
        );

        // The pager
        $build['pager'] = array(
          '#type' => 'pager'
        );

        return $build;

      case Model::PROC_ERROR:
        drupal_set_message($model->message, 'error');
        return new RedirectResponse(Drupal::url('csv_importer.home_controller_content'));

      default:
        drupal_set_message(t('Unknown error occurred on Model processing.'), 'error');
        return [];
    }
  }

}
