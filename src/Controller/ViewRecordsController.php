<?php

namespace Drupal\csv_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Driver\mysql\Connection;

/**
 * Class ViewRecordsController.
 *
 * @package Drupal\csv_importer\Controller
 */
class ViewRecordsController extends ControllerBase {

    /**
     * Drupal\Core\Database\Driver\mysql\Connection definition.
     *
     * @var \Drupal\Core\Database\Driver\mysql\Connection
     */
    protected $database;

    /**
     * {@inheritdoc}
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('database')
        );
    }

    /**
     * Content.
     *
     * @return string
     *   Return Hello string.
     */
    public function content($model)
    {
        $structure = \Drupal\csv_importer\getYmlFromCache();

        if (!isset($structure[$model])) {
            drupal_set_message($this->t('The structure data couldn\'t be found.'), 'error');
            return [];
        }

        $tableName = array_keys($structure)[0];
        $rowFields = $structure[$tableName];

        // Define structure
        foreach ($rowFields as $field) {
            $listing_fields_sql[] = $field;
            $listing_fields_label[] = t($field);
        }

        // Select & count the entries
        $query = $this->database
            ->select($tableName, 'x')
            ->fields('x', $listing_fields_sql);
        $num = $query->countQuery()->execute()->fetchField();

        if ($num == 0) {
            $build = array(
                '#markup' => t('This table is empty')
            );
        } else {

            // Prepare table header
            $header = $listing_fields_label;

            // Limitation for pager
            $table_sort = $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);
            $pager = $table_sort->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(20);
            $result = $pager->execute();
            $rows = [];
            // Fill the array with the data
            foreach ($result as $row) {
                $rows[] = array('data' => (array)$row);
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
        return $build;
    }

}
