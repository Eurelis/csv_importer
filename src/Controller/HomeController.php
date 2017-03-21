<?php

namespace Drupal\csv_importer\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class HomeController.
 *
 * @package Drupal\csv_importer\Controller
 */
class HomeController extends ControllerBase {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
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
  public function content() {
    $structures = \Drupal\csv_importer\getYmlFromCache();

    if (!isset($structures)) {
      drupal_set_message($this->t('The structure data couldn\'t be found.'), 'error');
      return [];
    }

    $build = [];

    // Link options are optional
    $link_options = [
      'absolute' => FALSE,
      'attributes' => [
        'class' => []
      ],
    ];

    // Link to refresh structure cache
    $url_refreshYml = Url::fromRoute('csv_importer.home_controller_refresh_structure', array(), $link_options);
    $linkObject_refreshYml = Link::fromTextAndUrl($this->t('Refresh structure'), $url_refreshYml);
    $build[] = $linkObject_refreshYml->toRenderable();

    // Define structure
    $tableNames = array_keys($structures);
    $rows = [];

    // List the different models as tablenames
    foreach ($tableNames as $tableName) {
      $url_view = Url::fromUri('internal:/admin/csv_importer/viewrecords/' . $tableName, $link_options);
      $linkObject_view = Link::fromTextAndUrl($this->t('View'), $url_view);

      $url_import = Url::fromUri('internal:/admin/csv_importer/import/' . $tableName, $link_options);
      $linkObject_import = Link::fromTextAndUrl($this->t('Import'), $url_import);

      $url_purge = Url::fromUri('internal:/admin/csv_importer/purge/' . $tableName, $link_options);
      $linkObject_purge = Link::fromTextAndUrl($this->t('Purge'), $url_purge);

      // Prepare each row
      $rows[] = [
        'data' => [
          $tableName,
          $linkObject_view,
          $linkObject_import,
          $linkObject_purge
        ]
      ];
    }

    // Build table header and rows
    $build[] = [
      '#type' => 'table',
      '#header' => [
        [
          'data' => $this->t('Model name'),
          'style' => 'width: 100%'
        ],
        [
          'colspan' => 3,
          'data' => $this->t('Actions')
        ],
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

  public function refreshStructure() {
    \Drupal\csv_importer\refreshYmlFromCache();

    return new RedirectResponse(Drupal::url('csv_importer.home_controller_content'));
  }

}
