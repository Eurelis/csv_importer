<?php

namespace Drupal\csv_importer\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
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
    }
    else {
      $translatedFieldNames = [];

      if (!isset($this->structure[$this->modelName]['structure_schema_version'])) {
        $this->tableName = $this->modelName;
        $this->rowFields = $this->structure[$this->modelName];

        foreach ($this->rowFields as $field) {
          $listing_fields_sql[] = $field;
          $listing_fields_label[] = t($field);
        }
      }
      else {
        switch ($this->structure[$this->modelName]['structure_schema_version']) {
          case '1':
            $this->tableName = $this->structure[$this->modelName]['table_name'];
            $this->rowFields = $this->structure[$this->modelName]['fields'];

            foreach ($this->rowFields as $field) {
              $listing_fields_sql[] = $field['name'];
              $listing_fields_label[] = t($field['name']);
            }
            $this->csvFileName = $this->structure[$this->modelName]['csv_file_name'];
            break;

          default:
            drupal_set_message($this->t('Unknown supplied structure_schema_version: "@version".', ['@version' => $this->structure[$this->modelName]['structure_schema_version']]), 'error');
            return [];
        }
      }
      try {
        // Select & count the entries
        $query = $this->database
            ->select($this->tableName, 'x')
            ->fields('x', $listing_fields_sql);

        $num = $query->countQuery()->execute()->fetchField();

        if ($num == 0) {
          $build = array(
            '#markup' => t('This table is empty')
          );
        }
        else {
          // Prepare table header
          $header = $listing_fields_label;

          // Limitation for pager
          $table_sort = $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);
          $pager = $table_sort->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(20);
          $result = $pager->execute();
          $rows = [];

          // Fill the array with the data
          foreach ($result as $row) {
            $rows[] = array('data' => (array) $row);
          }

          // Init of the markup render array
          $build = array(
            '#markup' => t('<p> There are ' . $num . ' records in this table </p>')
          );

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
        }
      }
      catch (Exception $e) {
        drupal_set_message($this->t('View of @modelName failed. Error message: @err_mess', ['@modelName' => $this->modelName, '@err_mess' => $e]), 'error');
        return new RedirectResponse(Drupal::url('csv_importer.home_controller_content'));
      }
    }

    return $build;
  }

}
