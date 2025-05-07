<?php

namespace Drupal\entity_display_json\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\entity_display_json\Service\ContentBatchProcessor;
use Drupal\entity_display_json\Service\ContentExporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a multi-step form for exploring content.
 */
class ContentExplorerForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The pager manager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The content batch processor service.
   *
   * @var \Drupal\entity_display_json\Service\ContentBatchProcessor
   */
  protected $batchProcessor;

  /**
   * The content exporter service.
   *
   * @var \Drupal\entity_display_json\Service\ContentExporter
   */
  protected $contentExporter;

  /**
   * Constructs a new ContentExplorerForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   * @param \Drupal\entity_display_json\Service\ContentBatchProcessor $batch_processor
   *   The content batch processor service.
   * @param \Drupal\entity_display_json\Service\ContentExporter $content_exporter
   *   The content exporter service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    PagerManagerInterface $pager_manager,
    ContentBatchProcessor $batch_processor,
    ContentExporter $content_exporter
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->pagerManager = $pager_manager;
    $this->batchProcessor = $batch_processor;
    $this->contentExporter = $content_exporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('pager.manager'),
      $container->get('entity_display_json.batch_processor'),
      $container->get('entity_display_json.content_exporter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_display_json_content_explorer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Add CSS for the toggle switches
    $form['#attached']['library'][] = 'entity_display_json/content_explorer';
    
    // Check if we're coming back from batch processing
    $show_results = \Drupal::request()->query->get('show_results');
    if ($show_results) {
      // Load results from tempstore
      $tempstore = \Drupal::service('tempstore.private')->get('entity_display_json');
      $results = $tempstore->get('explorer_results_' . $this->currentUser()->id());
      
      if (!empty($results)) {
        // Set form state values from tempstore
        $form_state->set('step', 3);
        $form_state->set('selected_content_type', $results['content_type']);
        $form_state->set('selected_fields', $results['selected_fields']);
        $form_state->set('processed_nodes', $results['processed_nodes']);
        $form_state->set('items_per_page', $results['items_per_page'] ?? 25);
      }
    }
    
    // Determine the current step
    $step = $form_state->get('step') ?: 1;
    $form_state->set('step', $step);
    
    // Add a wrapper for AJAX
    $form['#prefix'] = '<div id="content-explorer-form-wrapper">';
    $form['#suffix'] = '</div>';
    
    // Step 1: Select content type
    if ($step == 1) {
      $form['content_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Select Content Type'),
        '#options' => $this->getContentTypeOptions(),
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'content-explorer-form-wrapper',
        ],
      ];
      
      // If content type is selected, show the Next button
      if ($form_state->getValue('content_type')) {
        $form_state->set('selected_content_type', $form_state->getValue('content_type'));
        
        // Get total count of nodes for this content type
        $count = $this->getNodeCount($form_state->getValue('content_type'));
        $form['node_count'] = [
          '#markup' => '<div class="node-count">' . $this->t('Total nodes of this type: @count', ['@count' => $count]) . '</div>',
        ];
        
        // Add batch size option
        $form['batch_size'] = [
          '#type' => 'select',
          '#title' => $this->t('Batch Size'),
          '#description' => $this->t('Number of nodes to process in each batch. Use a smaller number for complex content types.'),
          '#options' => [
            25 => '25',
            50 => '50',
            100 => '100',
            200 => '200',
            500 => '500',
          ],
          '#default_value' => 50,
        ];
        
        // Add items per page option for results table
        $form['items_per_page'] = [
          '#type' => 'select',
          '#title' => $this->t('Items per page'),
          '#description' => $this->t('Number of items to display per page in results table.'),
          '#options' => [
            10 => '10',
            25 => '25',
            50 => '50',
            100 => '100',
          ],
          '#default_value' => 25,
        ];
        
        // Add node limit option
        $form['node_limit'] = [
          '#type' => 'number',
          '#title' => $this->t('Node limit'),
          '#description' => $this->t('Maximum number of nodes to process. Leave empty to process all nodes.'),
          '#min' => 1,
          '#max' => $count,
          '#step' => 1,
        ];

        // Add starting offset option
        $form['node_offset'] = [
          '#type' => 'number',
          '#title' => $this->t('Starting offset'),
          '#description' => $this->t('Start processing from this node number (0 = start from beginning).'),
          '#min' => 0,
          '#max' => $count - 1,
          '#default_value' => 0,
          '#step' => 1,
        ];

        $form['next'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#submit' => ['::nextStep'],
          '#ajax' => [
            'callback' => '::ajaxCallback',
            'wrapper' => 'content-explorer-form-wrapper',
          ],
        ];
      }
    }
    
    // Step 2: Select fields
    elseif ($step == 2) {
      $content_type = $form_state->get('selected_content_type');
      $fields = $this->getContentTypeFields($content_type);
      
      $form['content_type_display'] = [
        '#markup' => '<h3>' . $this->t('Content Type: @type', ['@type' => $this->getContentTypeLabel($content_type)]) . '</h3>',
      ];
      
      // Important: Use a flat structure for field checkboxes to make form state values easier to access
      $form['field_selection'] = [
        '#type' => 'details',
        '#title' => $this->t('Select Fields'),
        '#open' => TRUE,
      ];
      
      // Create checkboxes directly in the form, not nested in a container
      foreach ($fields as $field_name => $field_label) {
        $form['field_selection'][$field_name] = [
          '#type' => 'checkbox',
          '#title' => $field_label,
          '#default_value' => 1,
          '#attributes' => ['class' => ['field-toggle']],
        ];
      }
      
      $form['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::prevStep'],
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'content-explorer-form-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
      
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Process Content'),
        '#submit' => ['::processFieldsAndStartBatch'],
        // No AJAX for batch processing
      ];
    }
    
    // Step 3: Display results in a table
    elseif ($step == 3) {
      $content_type = $form_state->get('selected_content_type');
      $selected_fields = $form_state->get('selected_fields');
      $processed_nodes = $form_state->get('processed_nodes') ?: [];
      $items_per_page = $form_state->get('items_per_page') ?: 25;
      
      $form = $this->buildResultsForm(
        $form, 
        $content_type, 
        $selected_fields, 
        $processed_nodes, 
        $items_per_page
      );
    }
    
    return $form;
  }

  /**
   * Builds the results form.
   */
  protected function buildResultsForm(array $form, $content_type, $selected_fields, $processed_nodes, $items_per_page) {
    $form['results_header'] = [
      '#markup' => '<h3>' . $this->t('Results for @type', ['@type' => $this->getContentTypeLabel($content_type)]) . '</h3>',
    ];
    
    if (empty($processed_nodes)) {
      $form['no_results'] = [
        '#markup' => '<p>' . $this->t('No content was processed. Please try again.') . '</p>',
      ];
    }
    else {
      // Create table headers with selected field names
      $headers = ['Title', 'UUID', 'Path', 'Metatags'];
      $field_labels = [];
      
      // Get field labels for headers
      if (!empty($selected_fields)) {
        foreach ($selected_fields as $field_name) {
          $field_definition = $this->entityFieldManager->getFieldDefinitions('node', $content_type)[$field_name] ?? NULL;
          if ($field_definition) {
            $field_labels[$field_name] = $field_definition->getLabel();
            $headers[] = $field_definition->getLabel();
          }
        }
      }
      
      // Set up pagination
      $total_items = count($processed_nodes);
      $current_page = $this->pagerManager->createPager($total_items, $items_per_page)->getCurrentPage();
      $chunks = array_chunk($processed_nodes, $items_per_page, TRUE);
      
      // Get the current page's items
      $current_page_items = $chunks[$current_page] ?? [];
      
      // Create table rows with node data for the current page
      $rows = [];
      foreach ($current_page_items as $node_data) {
        $rows[] = $node_data['row'];
      }
      
      // Build the table
      $form['results'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $rows,
        '#empty' => $this->t('No content available.'),
        '#attributes' => [
          'class' => ['content-explorer-table'],
        ],
      ];
      
      // Add pager
      $form['pager'] = [
        '#type' => 'pager',
      ];
      
      // Display total count
      $form['total_count'] = [
        '#markup' => '<div class="total-count">' . $this->t('Total items: @count', ['@count' => $total_items]) . '</div>',
      ];
      
      // Add export options
      $form['export_options'] = [
        '#type' => 'details',
        '#title' => $this->t('Export Options'),
        '#open' => TRUE,
        '#description' => $this->t('Export all @count items, not just the current page.', ['@count' => $total_items]),
      ];
      
      $form['export_options']['export_csv'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export CSV'),
        '#submit' => ['::exportCsv'],
        '#attributes' => [
          'class' => ['export-with-refresh'],
        ],
        
      ];
      
      $form['export_options']['export_json'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export JSON'),
        '#submit' => ['::exportJson'],
        '#attributes' => [
          'class' => ['export-with-refresh'],
        ],
      ];
    }
    
    $form['new_search'] = [
      '#type' => 'submit',
      '#value' => $this->t('New Search'),
      '#submit' => ['::resetForm'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'content-explorer-form-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];
    
    return $form;
  }

  /**
   * Ajax callback for form updates.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Submit handler for the "Next" button.
   */
  public function nextStep(array &$form, FormStateInterface $form_state) {
    $current_step = $form_state->get('step');
    $form_state->set('step', $current_step + 1);
    
    if ($current_step == 1) {
      $form_state->set('batch_size', $form_state->getValue('batch_size'));
      $form_state->set('items_per_page', $form_state->getValue('items_per_page'));
      $form_state->set('node_limit', $form_state->getValue('node_limit'));
      $form_state->set('node_offset', $form_state->getValue('node_offset'));
    }
    
    $form_state->setRebuild(TRUE);
  }
  
  /**
   * Submit handler for the "Back" button.
   */
  public function prevStep(array &$form, FormStateInterface $form_state) {
    $current_step = $form_state->get('step');
    $form_state->set('step', $current_step - 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "New Search" button.
   */
  public function resetForm(array &$form, FormStateInterface $form_state) {
    $form_state->set('step', 1);
    $form_state->set('selected_content_type', NULL);
    $form_state->set('selected_fields', NULL);
    $form_state->set('processed_nodes', NULL);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "Process Content" button.
   */
  public function processFieldsAndStartBatch(array &$form, FormStateInterface $form_state) {
    // Debug: Log all form values to see what's available
    \Drupal::logger('entity_display_json')->debug('Form values: @values', [
      '@values' => print_r($form_state->getValues(), TRUE),
    ]);
    
    // Debug: Log the field_selection value specifically
    \Drupal::logger('entity_display_json')->debug('Field selection: @field_selection', [
      '@field_selection' => print_r($form_state->getValue('field_selection'), TRUE),
    ]);
    
    // Get field selection from the field_selection fieldset
    $field_selection = $form_state->getValue('field_selection');
    $selected_fields = [];
    
    if (is_array($field_selection)) {
      foreach ($field_selection as $key => $value) {
        // Skip the select_all checkbox
        if ($key === 'select_all') {
          continue;
        }
        
        // Only include fields that are checked (value == 1)
        if ($value == 1) {
          $selected_fields[] = $key;
        }
      }
    }
    
    // Debug: Log the selected fields
    \Drupal::logger('entity_display_json')->debug('Selected fields: @fields', [
      '@fields' => print_r($selected_fields, TRUE),
    ]);
    
    // If no fields were selected, try the original approach as a fallback
    if (empty($selected_fields)) {
      // Get all form values
      $values = $form_state->getValues();
      
      // Process field selection from the form values
      foreach ($values as $key => $value) {
        // Skip non-field elements and the select_all checkbox
        if (strpos($key, 'field_selection') === 0 || $key === 'select_all') {
          continue;
        }
        
        // Check if this is a field checkbox and it's selected
        if (isset($form['field_selection'][$key]) && 
            $form['field_selection'][$key]['#type'] === 'checkbox' && 
            $value == 1) {
          $selected_fields[] = $key;
        }
      }
      
      // Debug: Log the selected fields after fallback
      \Drupal::logger('entity_display_json')->debug('Selected fields after fallback: @fields', [
        '@fields' => print_r($selected_fields, TRUE),
      ]);
    }
    
    // If still no fields were selected, show a warning
    if (empty($selected_fields)) {
      \Drupal::messenger()->addWarning('No fields were selected. Please select at least one field.');
      return;
    }
    
    // Save selected fields to form state for use in batch processing
    $form_state->set('selected_fields', $selected_fields);
    
    // Get content type and batch size
    $content_type = $form_state->get('selected_content_type');
    $batch_size = $form_state->get('batch_size');
    $items_per_page = $form_state->get('items_per_page');
    $node_limit = $form_state->get('node_limit');
    $node_offset = $form_state->get('node_offset');

    // Debug the values
    \Drupal::logger('entity_display_json')->debug('Batch parameters: content_type=@content_type, batch_size=@batch_size, items_per_page=@items_per_page, node_limit=@node_limit, node_offset=@node_offset', [
      '@content_type' => $content_type,
      '@batch_size' => $batch_size,
      '@items_per_page' => $items_per_page,
      '@node_limit' => $node_limit,
      '@node_offset' => $node_offset,
    ]);
    
    // Start the batch process
    $this->batchProcessor->startBatch(
      $content_type,
      $selected_fields,
      $batch_size,
      $items_per_page,
      $this->currentUser()->id(),
      $node_limit,
      $node_offset
    );
  }

  /**
   * Submit handler for the "Export CSV" button.
   */
  public function exportCsv(array &$form, FormStateInterface $form_state) {
    $response = $this->contentExporter->exportCsv($this->currentUser()->id());
    
    if ($response) {
      $form_state->setResponse($response);
    }
  }

  /**
   * Submit handler for the "Export JSON" button.
   */
  public function exportJson(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser()->id();
    $response = $this->contentExporter->exportJson($uid);
    
    if ($response) {
        $form_state->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This method is required but we're handling submissions in specific handlers
  }

  /**
   * Gets a list of content types as options for a select list.
   *
   * @return array
   *   An array of content type labels keyed by machine name.
   */
  protected function getContentTypeOptions() {
    $options = [];
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }
    
    return $options;
  }

  /**
   * Gets the label for a content type.
   *
   * @param string $content_type
   *   The content type machine name.
   *
   * @return string
   *   The content type label.
   */
  protected function getContentTypeLabel($content_type) {
    $node_type = $this->entityTypeManager->getStorage('node_type')->load($content_type);
    return $node_type ? $node_type->label() : $content_type;
  }

  /**
   * Gets a list of fields for a content type.
   *
   * @param string $content_type
   *   The content type machine name.
   *
   * @return array
   *   An array of field labels keyed by field name.
   */
  protected function getContentTypeFields($content_type) {
    $fields = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $content_type);
    
    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip technical fields
      if (in_array($field_name, ['revision_uid', 'revision_log', 'revision_timestamp', 'revision_translation_affected', 'default_langcode', 'content_translation_source', 'content_translation_outdated'])) {
        continue;
      }
      
      $fields[$field_name] = $field_definition->getLabel();
    }
    
    return $fields;
  }

   /**
   * Gets the count of nodes for a content type.
   *
   * @param string $content_type
   *   The content type machine name.
   *
   * @return int
   *   The number of nodes.
   */
  protected function getNodeCount($content_type) {
    return $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->count()
      ->execute();
  }

  /**
   * Gets all node IDs for a content type with optional offset and limit.
   *
   * @param string $content_type
   *   The content type machine name.
   * @param int $offset
   *   Optional offset to start from.
   * @param int $limit
   *   Optional limit on number of nodes to return.
   *
   * @return array
   *   An array of node IDs.
   */
  protected function getNodeIds($content_type, $offset = 0, $limit = NULL) {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);
    
    // Apply offset if provided
    if ($offset > 0) {
      $query->range($offset, $limit);
    }
    // Apply just the limit if no offset but limit is provided
    elseif ($limit) {
      $query->range(0, $limit);
    }
    
    return $query->execute();
  }

}