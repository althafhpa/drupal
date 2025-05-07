<?php

namespace Drupal\url_analyzer;

use Drupal\Core\Url;

class BatchProcess {

  public static function processNodeUrls(&$context) {
    if (!isset($context['results']['urls'])) {
      $context['results']['urls'] = [];
    }

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $context['sandbox']['nids'] = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->execute();
      $context['sandbox']['max'] = count($context['sandbox']['nids']);
    }

    $limit = 50;
    $nids = array_slice($context['sandbox']['nids'], $context['sandbox']['progress'], $limit);

    if ($nids) {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $alias_manager = \Drupal::service('path_alias.manager');
      $nodes = $node_storage->loadMultiple($nids);

      foreach ($nodes as $node) {
        $system_path = '/node/' . $node->id();
        $alias = $alias_manager->getAliasByPath($system_path);

        $context['results']['urls'][] = [
          'type' => 'Node',
          'path' => $system_path,
          'alias' => $alias
        ];
        $context['sandbox']['progress']++;
      }
    }

    $context['message'] = t('Processing node URLs (@progress of @total)', [
      '@progress' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }

  public static function processCustomPaths(&$context) {
    if (!isset($context['results']['urls'])) {
      $context['results']['urls'] = [];
    }

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;

      // Load categories
      $categories_file = \Drupal::service('file_system')->realpath('public://') . '/categories.json';
      $context['sandbox']['categories'] = [];
      if (file_exists($categories_file)) {
        $context['sandbox']['categories'] = json_decode(file_get_contents($categories_file), TRUE);
      }

      // Get paths to process
      $database = \Drupal::database();
      $query = $database->select('path_alias', 'pa')
        ->fields('pa', ['path', 'alias'])
        ->condition('pa.status', 1);
      $context['sandbox']['paths'] = $query->execute()->fetchAll();
      $context['sandbox']['max'] = count($context['sandbox']['paths']);
    }

    $limit = 50;
    $paths = array_slice($context['sandbox']['paths'], $context['sandbox']['progress'], $limit);

    foreach ($paths as $record) {
      $path = trim($record->path, '/');
      $parts = explode('/', $path);
      $base_path = $parts[0];

      // Set category based on categories.json
      if (isset($context['sandbox']['categories'][$base_path])) {
        $category = $context['sandbox']['categories'][$base_path]['name'];
      } else {
        $category = 'Other';
      }

      $context['results']['urls'][] = [
        'type' => $category,
        'path' => $record->path,
        'alias' => $record->alias,
      ];

      $context['sandbox']['progress']++;
    }

    $context['message'] = t('Processing path aliases (@progress of @total)', [
      '@progress' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }

  public static function finished($success, $results, $operations) {
    if ($success) {
      $node_urls = [];
      $path_alias_urls = [];
      $categorized_urls = [];

      // Load categories from categories.json
      $categories_file = \Drupal::service('file_system')->realpath('public://') . '/categories.json';
      $categories = [];
      if (file_exists($categories_file)) {
        $categories = json_decode(file_get_contents($categories_file), TRUE);
      }

      foreach ($results['urls'] as $url) {
        $path = trim($url['path'], '/');
        $parts = explode('/', $path);
        $base_path = $parts[0];

        // Set category based on categories.json
        if (isset($categories[$base_path])) {
          $category = $categories[$base_path]['name'];
        } else {
          $category = 'Other';
        }

        $categorized_urls[] = [
          'type' => $category,
          'path' => $url['path'],
          'alias' => $url['alias'] ?? $url['path']
        ];
      }

      // Save categorized URLs JSON
      $categorized_file = \Drupal::service('file_system')->realpath('public://') . '/path_alias_urls.json';
      file_put_contents($categorized_file, json_encode($categorized_urls, JSON_PRETTY_PRINT));

      \Drupal::messenger()->addMessage(t('Successfully collected @total_count URLs across categories.', [
        '@total_count' => count($categorized_urls)
      ]));
    }
  }

  public static function processSelectedRoutes($selected_routes, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['routes'] = array_keys($selected_routes);
      $context['sandbox']['max'] = count($selected_routes);
      $context['sandbox']['current_file'] = 'route_paths_' . time() . '.json';
      $context['results']['paths'] = [];
    }

    $current_route = $context['sandbox']['routes'][$context['sandbox']['progress']];

    if (!isset($context['sandbox']['path_offset'])) {
      $context['sandbox']['path_offset'] = 0;
    }

    $database = \Drupal::database();
    $query = $database->select('path_alias', 'pa')
      ->fields('pa', ['path', 'alias'])
      ->condition('pa.status', 1)
      ->range($context['sandbox']['path_offset'], 50);

    $results = $query->execute()->fetchAll();

    // Write chunk to JSON file
    if (!empty($results)) {
      $paths = [];
      foreach ($results as $record) {
        $paths[] = [
          'route' => $current_route,
          'path' => $record->path,
          'alias' => $record->alias,
        ];
      }

      $file_path = \Drupal::service('file_system')->realpath('public://') . '/' . $context['sandbox']['current_file'];
      if (!file_exists($file_path)) {
        file_put_contents($file_path, json_encode(['paths' => []], JSON_PRETTY_PRINT));
      }

      $current_data = json_decode(file_get_contents($file_path), TRUE);
      $current_data['paths'] = array_merge($current_data['paths'], $paths);
      file_put_contents($file_path, json_encode($current_data, JSON_PRETTY_PRINT));
    }

    // Update progress
    if (count($results) < 50) {
      $context['sandbox']['progress']++;
      $context['sandbox']['path_offset'] = 0;
    } else {
      $context['sandbox']['path_offset'] += 50;
    }

    $context['message'] = t('Processing route @route (@current of @total)', [
      '@route' => $current_route,
      '@current' => $context['sandbox']['progress'] + 1,
      '@total' => $context['sandbox']['max'],
    ]);

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }


  public static function finishRouteProcessing($success, $results, $operations) {
    if ($success) {
      $file_path = \Drupal::service('file_system')->realpath('public://') . '/route_paths.json';
      file_put_contents($file_path, json_encode($results['paths'], JSON_PRETTY_PRINT));

      \Drupal::messenger()->addMessage(t('Successfully collected @count paths from selected routes.', [
        '@count' => count($results['paths'])
      ]));
    }
  }

}
