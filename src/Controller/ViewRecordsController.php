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
  public function content($model) {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Implement method: content with parameter(s): $model'),
    ];
  }

}
