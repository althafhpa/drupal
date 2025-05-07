<?php

namespace Drupal\url_analyzer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class PathAliasFilterForm extends FormBase {

  public function getFormId() {
    return 'path_alias_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $file_path = \Drupal::service('file_system')->realpath('public://') . '/path_alias_urls.json';
    $urls = [];

    if (file_exists($file_path)) {
      $urls = json_decode(file_get_contents($file_path), TRUE);
    }

    $categories = array_unique(array_column($urls, 'type'));
    sort($categories);

    // Get current filter values from URL parameters
    $current_request = \Drupal::request();
    $current_filters = [];
    if ($current_request->query->has('categories')) {
      $current_filters = $current_request->query->all()['categories'];
    }

    $form['categories'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Category'),
      '#options' => array_combine($categories, $categories),
      '#multiple' => TRUE,
      '#default_value' => $current_filters,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Filter'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $categories = $form_state->getValue('categories');
    $form_state->setRedirect('url_analyzer.path_aliases', [], [
      'query' => ['categories' => $categories],
    ]);
  }
}
