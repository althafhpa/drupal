<?php

namespace Drupal\url_analyzer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

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

    // Add route URLs batch
//    $operations[] = [
//      '\Drupal\url_analyzer\BatchProcess::processRouteUrls',
//      []
//    ];

    // Add view URLs batch
//    $operations[] = [
//      '\Drupal\url_analyzer\BatchProcess::processViewUrls',
//      []
//    ];


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

//  public function displayUrls() {
//    $file_path = \Drupal::service('file_system')->realpath('public://') . '/url_list.json';
//    $urls = [];
//
//    if (file_exists($file_path)) {
//      $urls = json_decode(file_get_contents($file_path), TRUE);
//    }
//
//    $build = [
//      '#theme' => 'table',
//      '#header' => [
//        $this->t('URL Type'),
//        $this->t('Path'),
//      ],
//      '#rows' => [],
//      '#empty' => $this->t('No URLs collected yet.'),
//      '#cache' => [
//        'max-age' => 0,
//      ],
//    ];
//
//    if (!empty($urls)) {
//      foreach ($urls as $url) {
//        if (is_array($url) && isset($url['type']) && isset($url['path'])) {
//          $build['#rows'][] = [
//            $url['type'],
//            $url['path']
//          ];
//        }
//      }
//    }
//
//    // Add clear button
//    $build['clear_button'] = [
//      '#type' => 'link',
//      '#title' => $this->t('Clear URL List'),
//      '#url' => Url::fromRoute('url_analyzer.clear_urls'),
//      '#attributes' => [
//        'class' => ['button', 'button--danger'],
//      ],
//    ];
//
//    return $build;
//  }

  public function clearUrls() {
    // Clear from state
    \Drupal::state()->delete('url_analyzer.collected_urls');

    // Delete JSON file if exists
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/url_list.json';
    if (file_exists($file_path)) {
      unlink($file_path);
    }

    \Drupal::messenger()->addMessage($this->t('URL collection has been cleared.'));
    return $this->redirect('url_analyzer.list_urls');
  }

  public function displayNodeUrls(Request $request) {
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/node_urls.json';
    $urls = [];

    if (file_exists($file_path)) {
      $urls = json_decode(file_get_contents($file_path), TRUE);
    }

    // Pagination setup
    $total = count($urls);
    $per_page = 50;

    // Create pager
    $pager = \Drupal::service('pager.manager')->createPager($total, $per_page);
    $current_page = $pager->getCurrentPage();

    $urls_chunk = array_slice($urls, $current_page * $per_page, $per_page);

    $build['summary'] = [
      '#markup' => $this->t('Total Results: @total', ['@total' => $total]),
      '#prefix' => '<div class="node-urls-summary">',
      '#suffix' => '</div>',
    ];

    $build['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Type'),
        $this->t('Path'),
      ],
      '#rows' => [],
      '#empty' => $this->t('No node URLs collected yet.'),
      '#cache' => ['max-age' => 0],
    ];

    foreach ($urls_chunk as $url) {
      $build['table']['#rows'][] = [
        $url['type'],
        $url['path'],
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  public function displayPathAliases(Request $request) {
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/path_alias_urls.json';
    $urls = [];

    $query_params = $request->query->all();
    $selected_categories = $query_params['categories'] ?? [];

    if (file_exists($file_path)) {
      $urls = json_decode(file_get_contents($file_path), TRUE);
    }

    // Filter by selected categories
    if (!empty($selected_categories)) {
      $urls = array_filter($urls, function($url) use ($selected_categories) {
        return in_array($url['type'], $selected_categories);
      });
    }

    $build['filter_form'] = \Drupal::formBuilder()->getForm('Drupal\url_analyzer\Form\PathAliasFilterForm');

    // Total count display
    $total = count($urls);
    $build['summary'] = [
      '#markup' => $this->t('Total Results: @total', ['@total' => $total]),
      '#prefix' => '<div class="path-alias-summary">',
      '#suffix' => '</div>',
    ];

    // Pagination setup
    $per_page = 50;
    $pager = \Drupal::service('pager.manager')->createPager($total, $per_page);
    $current_page = $pager->getCurrentPage();
    $urls_chunk = array_slice($urls, $current_page * $per_page, $per_page);

    $build['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Category'),
        $this->t('Path'),
        $this->t('Alias'),
      ],
      '#rows' => [],
      '#empty' => $this->t('No path aliases found.'),
      '#cache' => ['max-age' => 0],
    ];

    foreach ($urls_chunk as $url) {
      $build['table']['#rows'][] = [
        $url['type'],
        $url['path'],
        $url['alias'],
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  public function displayMissingPaths(Request $request) {
    $node_file = \Drupal::service('file_system')->realpath('public://') . '/node_urls.json';
    $alias_file = \Drupal::service('file_system')->realpath('public://') . '/path_alias_urls.json';

    $node_urls = [];
    $alias_urls = [];

    if (file_exists($node_file)) {
      $node_urls = json_decode(file_get_contents($node_file), TRUE);
    }

    if (file_exists($alias_file)) {
      $alias_urls = json_decode(file_get_contents($alias_file), TRUE);
    }

    // Get node paths without aliases
    $missing_aliases = [];
    foreach ($node_urls as $node) {
      $has_alias = FALSE;
      foreach ($alias_urls as $alias) {
        if ($node['path'] === $alias['system_path']) {
          $has_alias = TRUE;
          break;
        }
      }
      if (!$has_alias) {
        $missing_aliases[] = $node;
      }
    }

    // Pagination setup
    $page = $request->query->get('page', 0);
    $per_page = 50;
    $total = count($missing_aliases);
    $urls_chunk = array_slice($missing_aliases, $page * $per_page, $per_page);

    $build = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Node Path'),
        $this->t('Node Type'),
      ],
      '#rows' => [],
      '#empty' => $this->t('No missing path aliases found.'),
      '#cache' => ['max-age' => 0],
    ];

    foreach ($urls_chunk as $path) {
      $build['#rows'][] = [
        $path['path'],
        $path['type'],
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
      '#quantity' => 5,
      '#total' => ceil($total / $per_page),
    ];

    return $build;
  }

  public function analyzePaths() {
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/path_alias_urls.json';
    $urls = [];
    $categories = [];

    if (file_exists($file_path)) {
      $urls = json_decode(file_get_contents($file_path), TRUE);
    }

    // Add user paths
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $uids = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->execute();

    foreach ($uids as $uid) {
      $user = $user_storage->load($uid);
      $urls[] = [
        'path' => '/user/' . $uid,
        'alias' => \Drupal::service('path_alias.manager')->getAliasByPath('/user/' . $uid),
      ];
    }

    // Analyze paths and group into categories
    foreach ($urls as $url) {
      $path = trim($url['path'], '/');
      $parts = explode('/', $path);
      $base_path = $parts[0];

      if (!isset($categories[$base_path])) {
        $categories[$base_path] = [
          'name' => ucfirst(str_replace('-', ' ', $base_path)),
          'count' => 0,
          'paths' => []
        ];
      }

      $categories[$base_path]['count']++;
      $categories[$base_path]['paths'][] = $url;
    }

    // Filter categories with more than 10 items
    $significant_categories = array_filter($categories, function($category) {
      return $category['count'] >= 10;
    });

    // Save categories to JSON
    $category_file = \Drupal::service('file_system')->realpath('public://') . '/categories.json';
    file_put_contents($category_file, json_encode($significant_categories, JSON_PRETTY_PRINT));

    // Build display
    $build['summary'] = [
      '#markup' => $this->t('Found @count significant path categories', ['@count' => count($significant_categories)]),
      '#prefix' => '<div class="category-summary">',
      '#suffix' => '</div>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Category'),
        $this->t('Count'),
      ],
      '#rows' => [],
    ];

    foreach ($significant_categories as $key => $category) {
      $sample_paths = array_slice($category['paths'], 0, 5);
      $paths_list = array_map(function($path) {
        return $path['path'];
      }, $sample_paths);

      $build['table']['#rows'][] = [
        $category['name'],
        $category['count'],
        [
          '#theme' => 'item_list',
          '#items' => $paths_list,
        ],
      ];
    }

    return $build;
  }

  public function displayContentAccessRoutes() {
    $route_provider = \Drupal::service('router.route_provider');
    $all_routes = $route_provider->getAllRoutes();
    $content_access_routes = [];

    foreach ($all_routes as $route_name => $route) {
      $requirements = $route->getRequirements();
      if (isset($requirements['_permission']) && strpos($requirements['_permission'], 'access content') !== FALSE) {
        $content_access_routes[] = [
          'name' => $route_name,
          'path' => $route->getPath(),
          'permission' => $requirements['_permission'],
        ];
      }
    }

    // Build the display
    $build['summary'] = [
      '#markup' => $this->t('Found @count routes with "access content" permission', [
        '@count' => count($content_access_routes)
      ]),
      '#prefix' => '<div class="route-summary">',
      '#suffix' => '</div>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Route Name'),
        $this->t('Path'),
        $this->t('Permission'),
      ],
      '#rows' => [],
    ];

    foreach ($content_access_routes as $route) {
      $build['table']['#rows'][] = [
        $route['name'],
        $route['path'],
        $route['permission'],
      ];
    }

    return $build;
  }

  public function displayRouteAnalyzerForm() {
    return \Drupal::formBuilder()->getForm('Drupal\url_analyzer\Form\RouteAnalyzerForm');
  }

  public function displayRoutePaths() {
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/route_paths_' . time() . '.json';
    $paths = [];

    if (file_exists($file_path)) {
      $data = json_decode(file_get_contents($file_path), TRUE);
      $paths = $data['paths'] ?? [];
    }

    // Group paths by route
    $grouped_paths = [];
    foreach ($paths as $path_info) {
      $grouped_paths[$path_info['route']][] = $path_info;
    }

    $build = [];

    // Summary
    $build['summary'] = [
      '#markup' => $this->t('Found @count paths across @routes routes', [
        '@count' => count($paths),
        '@routes' => count($grouped_paths)
      ]),
      '#prefix' => '<div class="path-summary">',
      '#suffix' => '</div>',
    ];

    // Display paths grouped by route
    foreach ($grouped_paths as $route => $route_paths) {
      $build[$route] = [
        '#type' => 'details',
        '#title' => $this->t('Route: @route (@count paths)', [
          '@route' => $route,
          '@count' => count($route_paths)
        ]),
        '#open' => FALSE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('System Path'),
            $this->t('Alias'),
          ],
          '#rows' => [],
        ],
      ];

      foreach ($route_paths as $path_info) {
        $build[$route]['table']['#rows'][] = [
          $path_info['path'],
          $path_info['alias'],
        ];
      }
    }

    return $build;
  }



}
