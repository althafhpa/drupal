<?php

namespace Drupal\entity_display_json\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Service for batch processing content.
 */
class ContentBatchProcessor {
  use StringTranslationTrait;
  use DependencySerializationTrait;

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
   * The field formatter service.
   *
   * @var \Drupal\entity_display_json\Service\FieldFormatter
   */
  protected $fieldFormatter;

  /**
   * Constructs a new ContentBatchProcessor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\entity_display_json\Service\FieldFormatter $field_formatter
   *   The field formatter service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    FieldFormatter $field_formatter,
    TranslationInterface $string_translation
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldFormatter = $field_formatter;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Starts a batch process for content exploration.
   *
   * @param string $content_type
   *   The content type to process.
   * @param array $selected_fields
   *   The fields to include in the results.
   * @param int $batch_size
   *   The number of nodes to process in each batch.
   * @param int $items_per_page
   *   The number of items to display per page in results.
   * @param int $uid
   *   The user ID to store results for.
   * @param int|null $node_limit
   *   Optional limit on the number of nodes to process.
   * @param int $node_offset
   *   Optional offset to start processing from.
   */
  public function startBatch($content_type, array $selected_fields, $batch_size, $items_per_page, $uid, $node_limit = NULL, $node_offset = 0) {
    // Log the parameters for debugging
    \Drupal::logger('entity_display_json')->debug('startBatch parameters: content_type=@content_type, selected_fields=@selected_fields, batch_size=@batch_size, items_per_page=@items_per_page, uid=@uid, node_limit=@node_limit, node_offset=@node_offset', [
      '@content_type' => $content_type,
      '@selected_fields' => implode(', ', $selected_fields),
      '@batch_size' => $batch_size,
      '@items_per_page' => $items_per_page,
      '@uid' => $uid,
      '@node_limit' => $node_limit,
      '@node_offset' => $node_offset,
    ]);

    // Get all node IDs for this content type
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);
    
    // Clone the query for count
    $count_query = clone $query;
    $total_count = $count_query->count()->execute();
    \Drupal::logger('entity_display_json')->debug('Total nodes before offset/limit: @count', ['@count' => $total_count]);

    // Apply range to the main query
    if (!empty($node_limit) && is_numeric($node_limit) && $node_limit > 0) {
      $offset = (!empty($node_offset) && is_numeric($node_offset) && $node_offset > 0) ? $node_offset : 0;
      $query->range($offset, $node_limit);
      \Drupal::logger('entity_display_json')->debug('Applied range with offset=@offset and limit=@limit', [
        '@offset' => $offset,
        '@limit' => $node_limit,
      ]);
    }
    elseif (!empty($node_offset) && is_numeric($node_offset) && $node_offset > 0) {
      $query->range($node_offset, NULL);
      \Drupal::logger('entity_display_json')->debug('Applied range with offset=@offset and no limit', [
        '@offset' => $node_offset,
      ]);
    }

    // Execute the query and ensure we get an array
    $nids = $query->execute();
    if (!is_array($nids)) {
      \Drupal::logger('entity_display_json')->error('Query returned non-array result: @type', ['@type' => gettype($nids)]);
      $nids = [];
    }

    $actual_count = count($nids);
    \Drupal::logger('entity_display_json')->debug('Retrieved @count node IDs (from total of @total)', [
      '@count' => $actual_count,
      '@total' => $total_count,
    ]);
    
    $total_nodes = count($nids);
    
    // Set up the batch
    $batch_builder = new BatchBuilder();
    $batch_builder
      ->setTitle($this->t('Processing @type content', ['@type' => $this->getContentTypeLabel($content_type)]))
      ->setInitMessage($this->t('Starting content processing...'))
      ->setProgressMessage($this->t('Processing batch @current of @total (approximately @nodes_per_batch nodes per batch)', [
        '@nodes_per_batch' => $batch_size,
      ]))
      ->setErrorMessage($this->t('An error occurred during processing'));
    
    // Split node IDs into chunks for batch processing
    $chunks = array_chunk($nids, $batch_size);
    $total_batches = count($chunks);
    
    // Store total nodes and batches in batch context
    $batch_builder->setProgressive(TRUE);
    
    foreach ($chunks as $index => $chunk) {
      $batch_builder->addOperation(
        [$this, 'processBatch'],
        [
          $chunk, 
          $content_type, 
          $selected_fields, 
          $index + 1, 
          $total_batches,
          $total_nodes,
          $uid,
          $items_per_page
        ]
      );
    }
    
    $batch_builder->setFinishCallback([$this, 'finishBatch']);
    
    // Set the batch
    batch_set($batch_builder->toArray());
  }
  
  /**
   * Batch operation callback.
   */
  public function processBatch($nids, $content_type, $selected_fields, $current_batch, $total_batches, $batch_size, $uid, $items_per_page, &$context) {
     // Add null check for $nids
    if (!is_array($nids)) {
      $nids = [];
    }

    if (!isset($context['results']['processed_nodes'])) {
      $context['results']['processed_nodes'] = [];
      $context['results']['content_type'] = $content_type;
      $context['results']['selected_fields'] = $selected_fields;
      $context['results']['batch_size'] = $batch_size;
      $context['results']['total_batches'] = $total_batches;
      $context['results']['nodes_processed'] = 0;
      $context['results']['uid'] = $uid;
      $context['results']['items_per_page'] = $items_per_page;
    }
    
    // Load nodes for this batch
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $batch_node_count = count($nodes);
    
    // Update the batch message to show more detailed progress
    $context['message'] = $this->t('Processing batch @current of @total batches (@count nodes in this batch, @processed of @total_nodes total nodes processed)', [
      '@current' => $current_batch,
      '@total' => $total_batches,
      '@count' => $batch_node_count,
      '@processed' => $context['results']['nodes_processed'],
      '@total_nodes' => count($nids),
    ]);
    
    foreach ($nodes as $node) {
      // Create a row for the table
      $row = [];
      
      // Add title, UUID and path
      $row[] = $node->label();
      $row[] = $node->uuid();
      $row[] = $node->toUrl()->toString();
      
      // Extract metatags and add them to the row
      $metatags = $this->extractMetatags($node);
      if (!empty($metatags)) {
          $organized_metatags = [
              'basic' => [],
              'open_graph' => [],
              'twitter' => []
          ];
          
          // Organize metatags by type
          foreach ($metatags as $key => $value) {
              // Skip debug information and empty values
              if ($key === '_debug' || empty($value)) {
                  continue;
              }
              
              if (strpos($key, 'og:') === 0) {
                  $organized_metatags['open_graph'][$key] = $value;
              }
              elseif (strpos($key, 'twitter:') === 0) {
                  $organized_metatags['twitter'][$key] = $value;
              }
              else {
                  $organized_metatags['basic'][$key] = $value;
              }
          }
          
          $metatag_summary = [];
          
          // Process each category
          foreach ($organized_metatags as $category => $tags) {
            foreach ($tags as $key => $value) {
                // Skip empty values
                if (empty($value)) {
                    continue;
                }

                $display_key = match($category) {
                    'open_graph' => 'OG ' . substr($key, 3), // Remove 'og:' prefix
                    'twitter' => 'Twitter ' . substr($key, 8), // Remove 'twitter:' prefix
                    default => ucfirst(str_replace('_', ' ', $key))
                };

                // Handle array values (like og:image)
                if (is_array($value)) {
                    if (isset($value['url'])) {
                        $value = $value['url'];
                    } else {
                        $value = implode(', ', array_filter($value));
                    }
                }

                // Format dates
                if (strpos($key, 'modified_time') !== false || 
                    strpos($key, 'published_time') !== false) {
                    // Convert timestamp to formatted date
                    $date = new \DateTime($value);
                    $value = $date->format('Y-m-d H:i:s O');
                }

                // Add formatted metatag to summary
                $metatag_summary[] = $display_key . ': ' . $value;
            }
          }
          
          $row[] = implode('; ', array_filter($metatag_summary));
      } else {
          $row[] = $this->t('No metatags');
      }

      // Add node ID  and field values
      $node_data = [
        'nid' => $node->id(),
        'uuid' => $node->uuid(),
        'title' => $node->label(),
        'path' => $node->toUrl()->toString(),
        'fields' => [],
      ];
      
      // Add metatags to node data for export - include all metatags without truncation
      if (!empty($metatags)) {
        $node_data['metatags'] = $metatags;
      }
      
      foreach ($selected_fields as $field_name) {
        // Skip metatag-related fields to avoid duplication
        // We use another approach to handle metatags
        if ($field_name === 'field_metatag' || $field_name === 'metatags') {
          continue;
        }

        // For table display, we need a simplified version
        $display_value = $this->fieldFormatter->formatFieldValue($node, $field_name, true);
        $row[] = $display_value;
        
        // For export data, we need the full value
        $export_value = $this->fieldFormatter->formatFieldValue($node, $field_name, false);
        $node_data['fields'][$field_name] = $export_value;
      }
      
      // Store both the row for the table and the structured data for export
      $context['results']['processed_nodes'][$node->id()] = [
        'row' => $row,
        'data' => $node_data,
      ];
      
      // Increment the processed node count
      $context['results']['nodes_processed']++;
    }
  }

  /**
   * Batch finish callback.
   */
  public function finishBatch($success, $results, $operations) {
    if ($success) {
      $count = count($results['processed_nodes']);
      $total_batches = $results['total_batches'];
      $uid = $results['uid'];
      
      \Drupal::messenger()->addStatus($this->t('Successfully processed @count nodes in @batches batches.', [
        '@count' => $count,
        '@batches' => $total_batches,
      ]));
      
      // Store results in tempstore for use in export handlers and form display
      $tempstore = \Drupal::service('tempstore.private')->get('entity_display_json');
      $tempstore->set('explorer_results_' . $uid, [
        'content_type' => $results['content_type'],
        'selected_fields' => $results['selected_fields'],
        'processed_nodes' => $results['processed_nodes'],
        'items_per_page' => $results['items_per_page'],
      ]);
      
      // Redirect to the form with a special parameter to trigger step 3
      $url = Url::fromRoute('entity_display_json.content_explorer', [
        'show_results' => 1,
      ]);
      
      $response = new RedirectResponse($url->toString());
      $response->send();
      exit;
    }
    else {
      \Drupal::messenger()->addError($this->t('An error occurred during processing.'));
    }
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
   * Gets all node IDs for a content type.
   *
   * @param string $content_type
   *   The content type machine name.
   *
   * @return array
   *   An array of node IDs.
   */
  protected function getNodeIds($content_type) {
    return $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE)
      ->execute();
  }

   /**
   * Extracts metatag information from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract metatags from.
   *
   * @return array
   *   An array of metatag data, or empty array if no metatags found.
   */
  protected function extractMetatags(EntityInterface $entity) {
    $metatags = [];
    $debug_info = [];
    
    // Check if Metatag module is enabled
    if (!\Drupal::moduleHandler()->moduleExists('metatag')) {
      return [];
    }
    
    // Use the Metatag Manager service to get and process metatags
    if (\Drupal::hasService('metatag.manager')) {
      $metatag_manager = \Drupal::service('metatag.manager');
      
      // Get metatags with tokens from the entity
      $metatags_with_tokens = $metatag_manager->tagsFromEntityWithDefaults($entity);
      
      // Generate actual values from tokens
      $metatags = $metatag_manager->generateTokenValues($metatags_with_tokens, $entity);
      
    }
    
    return $metatags;
  }

}
