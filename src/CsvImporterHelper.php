<?php

namespace Drupal\csv_importer;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CsvImporterHelper {

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

        drupal_set_message(t('Failed to parse structure YAML file from this location: @ymlLocation . Please put the file at this location or change the file location.', ['@ymlLocation' => $ymlLocation]), 'warning');

        return null;
      }

      // Put full structure in cache
      $cache->set('csv_importer_ymlFromCache', $structure);

      drupal_set_message(t('CSV Importer: The structure YAML cache has been refreshed successfully.'));

      return $structure;
    }
    catch (ParseException $e) {
      CsvImporterHelper::flushYmlCache();

      drupal_set_message(t('Unable to parse the structure YAML file: @s', $e->getMessage()), 'error');

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

}
