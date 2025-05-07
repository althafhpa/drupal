<?php

namespace Drupal\url_analyzer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class RouteAnalyzerForm extends FormBase {

  public function getFormId() {
    return 'route_analyzer_form';
  }

  protected function getContentAccessRoutes() {
    $route_provider = \Drupal::service('router.route_provider');
    $all_routes = $route_provider->getAllRoutes();
    $content_access_routes = [];

    foreach ($all_routes as $route_name => $route) {
      $requirements = $route->getRequirements();
      $options = $route->getOptions();

      if (isset($requirements['_permission']) &&
        strpos($requirements['_permission'], 'access content') !== FALSE &&
        (!isset($options['_admin_route']) || $options['_admin_route'] === FALSE)) {

        $content_access_routes[$route_name] = [
          'name' => $route_name,
          'path' => $route->getPath(),
          'permission' => $requirements['_permission'],
        ];
      }
    }

    return $content_access_routes;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $content_access_routes = $this->getContentAccessRoutes();

    // Get path counts for selected routes
    $selected_routes = $form_state->getValue('routes', []);
    if (!empty($selected_routes)) {
      $path_counts = $this->getPathCountsForRoutes($selected_routes);
      $total_paths = array_sum($path_counts);

      $form['summary'] = [
        '#markup' => $this->t('Selected routes have @count total paths', ['@count' => $total_paths]),
        '#prefix' => '<div class="route-summary">',
        '#suffix' => '</div>',
      ];

      // Display individual route counts
      $form['route_counts'] = [
        '#type' => 'details',
        '#title' => $this->t('Path counts by route'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($path_counts as $route_name => $count) {
        $form['route_counts'][$route_name] = [
          '#markup' => $this->t('@route: @count paths', [
            '@route' => $route_name,
            '@count' => $count
          ]),
          '#prefix' => '<div>',
          '#suffix' => '</div>',
        ];
      }
    }

    $form['routes'] = [
      '#type' => 'tableselect',
      '#header' => [
        'name' => $this->t('Route Name'),
        'path' => $this->t('Path Pattern'),
        'permission' => $this->t('Permission'),
      ],
      '#options' => array_map(function($route) {
        return [
          'name' => $route['name'],
          'path' => $route['path'],
          'permission' => $route['permission'],
        ];
      }, $content_access_routes),
      '#empty' => $this->t('No public routes with "access content" permission found'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze Selected Routes'),
    ];

    return $form;
  }

  protected function getPathCountsForRoutes($routes) {
    $counts = [];
    $database = \Drupal::database();

    foreach ($routes as $route_name => $selected) {
      if ($selected) {
        $query = $database->select('path_alias', 'pa')
          ->condition('pa.status', 1);
        $query->addExpression('COUNT(*)', 'count');
        $count = $query->execute()->fetchField();
        $counts[$route_name] = $count;
      }
    }

    return $counts;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_routes = array_filter($form_state->getValue('routes'));

    if (!empty($selected_routes)) {
      $batch = [
        'title' => $this->t('Processing selected routes'),
        'operations' => [
          ['\Drupal\url_analyzer\BatchProcess::processSelectedRoutes', [$selected_routes]],
        ],
        'finished' => '\Drupal\url_analyzer\BatchProcess::finishRouteProcessing',
        'progress_message' => $this->t('Processed @current out of @total routes.'),
      ];

      batch_set($batch);
    }
  }

}
