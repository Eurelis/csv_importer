<?php

namespace Drupal\csv_importer;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CsvImporterHelper {

  const LOG_NONE = 'LOG_NONE';
  const LOG_DRUPAL_SET_MESSAGE = 'LOG_DRUPAL_SET_MESSAGE';
  const LOG_LOG_FACTORY = 'LOG_LOG_FACTORY';
  const LOG_DRUPAL_SET_MESSAGE_AND_LOGGER_FACTORY = 'LOG_DRUPAL_SET_MESSAGE_AND_LOG_FACTORY';

  /**
   * Possible values are:
   * 
   * - LOG_NONE
   * - LOG_DRUPAL_SET_MESSAGE (default)
   * - LOG_LOG_FACTORY
   * - LOG_DRUPAL_SET_MESSAGE_AND_LOG_FACTORY
   * 
   * @var string Log type as a string.
   */
  public static $logType = self::LOG_DRUPAL_SET_MESSAGE;

  /**
   * Gets the structure Yaml file used by the csv_importer module from cache.
   * 
   * @return array Yaml config file.
   */
  public static function getYmlFromCache() {
    /* @var $cache CacheBackendInterface */
    $cache = Drupal::cache();

    if ($valueFromCache = $cache->get('csv_importer_ymlFromCache')) {
      return $valueFromCache->data;
    }

    return CsvImporterHelper::refreshYmlFromCache();
  }

  /**
   * Refreshes the cache of the structure Yaml file used by the csv_importer module.
   * 
   * @return array Yaml config file.
   */
  public static function refreshYmlFromCache() {
    /* @var $cache CacheBackendInterface */
    $cache = Drupal::cache();

    try {
      // Structure file location
      $ymlLocation = Drupal::config('csv_importer.structureconfig')->get('yml_location');

      // Parse YAML file!
      $structure = Yaml::parse(file_get_contents($ymlLocation));

      if ($structure == null) {
        // Yaml::parse(...) returns null if the file does not exist
        CsvImporterHelper::flushYmlCache();

        self::log(t('Failed to parse structure YAML file from this location: @ymlLocation . Please put the file at this location or change the file location.', ['@ymlLocation' => $ymlLocation]), 'warning');

        return null;
      }

      // Put full structure in cache
      $cache->set('csv_importer_ymlFromCache', $structure);

      self::log(t('CSV Importer: The structure YAML cache has been refreshed successfully.'));

      return $structure;
    }
    catch (ParseException $e) {
      CsvImporterHelper::flushYmlCache();

      self::log(t('Unable to parse the structure YAML file: @s', $e->getMessage()), 'error');

      return null;
    }
  }

  /**
   * Flushes the structure cache.
   */
  public static function flushYmlCache() {
    $cache = Drupal::cache();

    if ($cache->get('csv_importer_ymlFromCache')) {
      $cache->delete('csv_importer_ymlFromCache');
    }
  }

  /**
   * Checks if the structure is currently cached.
   * 
   * @return boolean TRUE if the structure is in the cache. FALSE otherwise.
   */
  public static function isYmlInCache() {
    $cache = Drupal::cache();

    if ($cache->get('csv_importer_ymlFromCache')) {
      return true;
    }

    return false;
  }

  private static function log($message, $severity = 'status') {
    switch (self::$logType) {
      case self::LOG_DRUPAL_SET_MESSAGE:
        drupal_set_message($message, $severity);
        break;

      case self::LOG_LOG_FACTORY:
        self::logWithLoggerFactory($message, $severity);
        break;

      case self::LOG_DRUPAL_SET_MESSAGE_AND_LOGGER_FACTORY:
        drupal_set_message($message, $severity);
        self::logWithLoggerFactory($message, $severity);
        break;
    }
  }
  
  private static function logWithLoggerFactory($message, $severity) {
    switch($severity) {
      case 'error':
        \Drupal::logger('csv_importer')->error($message);
        break;
      
      case 'warning':
        \Drupal::logger('csv_importer')->warning($message);
        break;
      
      default:
        \Drupal::logger('csv_importer')->notice($message);
        break;
    }
  }

}
