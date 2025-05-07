<?php

namespace Drupal\pathauto_export_batch_plugin\Plugin\BatchPlugin;

use Drupal\batch_plugin\BatchPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the batch_plugin.
 *
 * @BatchPlugin(
 *   id = "pathauto_export_batch_plugin",
 *   label = @Translation("Pathauto export batch plugin"),
 *   description = @Translation("Exports aliases from pathauto module to CSV in batches.."),
 * )
 */
class PathautoExportBatchPlugin extends BatchPluginBase {

  public function __construct(
    array $configuration,
          $plugin_id,
          $plugin_definition,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  public function defaultConfiguration()
  {

//    $content = '"PATH","ALIAS","LANGCODE"' . "\n";
//    $filename = 'public://temporary/pathauto_aliases_export-' . rand() . '.csv';
//    file_put_contents($filename, $content);

    return [
      'chunk_size' => 50,
      'file_path' => 'public://pathauto_aliases_export.csv',
    ];
  }

  public function setupOperations(): void
  {

    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');

    $file_system->saveData('"PATH","ALIAS","LANGCODE"' . "\n", 'public://pathauto_aliases_export.csv', \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

    $aliases = [];
    $queryStatement = \Drupal::database()->select('path_alias')
      ->fields('path_alias');
    $query = $queryStatement
      ->orderBy('id', 'DESC')
      ->execute();
    if ($query) {
      $aliases = $query->fetchAll();
    }

    // Use only newest alias.
    $validAliases = [];
    foreach ($aliases as $alias) {
      if (!isset($validAliases[$alias->path])) {
        $validAliases[$alias->path] = $alias;
      }
    }
    // Restore aliases original order.
    sort($validAliases);

    $this->operations = $validAliases;
    $this->setBatchTitle('Getting aliases @count from @plugin');
  }

  public function processOperation($payload, &$context): void
  {
//    $date_time = date("d-m-y-h-i-s-A");
//    $filename = 'public://tmp/pathauto_aliases_export-' . $date_time . '.csv';
//    file_put_contents($filename, $payload);

    $file_system = \Drupal::service('file_system');
    $csv_data = '';

    foreach ($payload as $alias) {
//      $csv_data .= sprintf('"%s","%s","%s"' . "\n",
//        $alias->path,
//        $alias->alias,
//        $alias->langcode
//      );
      $csv_data .= '"' . $alias->path . '","' . $alias->alias . '","' . $alias->langcode . '"' . "\n";
    }
    $file_system->saveData($csv_data, $this->configuration['file_path'], \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
  }

  public function finished($success, $results, $operations): void
  {
    if ($success) {
      $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($this->configuration['file_path']);
      $message = $this->t('Exported aliases successfully. <a href="@url">Download CSV</a>', [
        '@url' => $file_url,
      ]);
    } else {
      $message = $this->t('Finished with an error.');
    }
    \Drupal::messenger()->addStatus($message);
  }
}
