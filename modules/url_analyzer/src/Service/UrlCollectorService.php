<?php

namespace Drupal\url_analyzer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;

class UrlAnalyzer extends ControllerBase {

  protected $entityTypeManager;
  protected $routeProvider;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, RouteProviderInterface $routeProvider) {
    $this->entityTypeManager = $entityTypeManager;
    $this->routeProvider = $routeProvider;
  }

  public function getUrls() {
    $operations = [];

    // Add node URLs batch
    $operations[] = [
      '\Drupal\url_analyzer\BatchProcess::processNodeUrls',
      []
    ];

    // Add view URLs batch
    $operations[] = [
      '\Drupal\url_analyzer\BatchProcess::processViewUrls',
      []
    ];

    // Add route URLs batch
    $operations[] = [
      '\Drupal\url_analyzer\BatchProcess::processRouteUrls',
      []
    ];

    // Add custom paths batch
    $operations[] = [
      '\Drupal\url_analyzer\BatchProcess::processCustomPaths',
      []
    ];

    $batch = [
      'title' => $this->t('Collecting All URLs'),
      'operations' => $operations,
      'finished' => '\Drupal\url_analyzer\BatchProcess::finished',
      'init_message' => $this->t('Starting URL collection...'),
      'progress_message' => $this->t('Processed @current out of @total operations.'),
      'error_message' => $this->t('URL collection has encountered an error.'),
    ];

    batch_set($batch);
    return batch_process('/admin/reports/url-list');
  }

  public function displayUrls() {
    $urls = \Drupal::state()->get('url_analyzer.collected_urls', []);

    $build = [
      '#theme' => 'table',
      '#header' => [
        $this->t('URL Type'),
        $this->t('Path'),
      ],
      '#rows' => array_map(function($url) {
        return [
          $url['type'],
          $url['path'],
        ];
      }, $urls),
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    return $build;
  }

}
