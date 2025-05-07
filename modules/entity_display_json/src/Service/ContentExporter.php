<?php

namespace Drupal\entity_display_json\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for exporting content data.
 */
class ContentExporter {
  use StringTranslationTrait;

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
   * Constructs a new ContentExporter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    TranslationInterface $string_translation
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Exports content data as CSV.
   *
   * @param int $uid
   *   The user ID to retrieve results for.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The CSV response or NULL if no data is available.
   */
  public function exportCsv($uid) {
    // Retrieve results from tempstore
    $tempstore = \Drupal::service('tempstore.private')->get('entity_display_json');
    $results = $tempstore->get('explorer_results_' . $uid);
    
    if (empty($results) || empty($results['processed_nodes'])) {
      \Drupal::messenger()->addError($this->t('No data available for export.'));
      return NULL;
    }
    
    $content_type = $results['content_type'];
    $selected_fields = $results['selected_fields'];
    $processed_nodes = $results['processed_nodes'];
    
    // Create CSV headers
    $headers = ['Title', 'UUID', 'Path', 'Metatags'];
    
    // Get field labels for headers
    foreach ($selected_fields as $field_name) {

      // Skip all metatag-related fields
      if (in_array($field_name, ['field_metatag', 'metatags', 'field_meta_tags']) || 
        strpos($field_name, 'metatag') !== false) {
        continue;
      }

      $field_definition = $this->entityFieldManager->getFieldDefinitions('node', $content_type)[$field_name] ?? NULL;
      if ($field_definition) {
        $headers[] = $field_definition->getLabel();
      }
    }
    
    // Create CSV content
    $csv_content = implode(',', $headers) . "\n";
    
    foreach ($processed_nodes as $node_data) {
      $row = [];
      
      // Only take the first 4 columns (Title, UUID, Path, Metatags)
      $core_fields = array_slice($node_data['row'], 0, 4);
      
      // Get remaining fields excluding metatags
      $other_fields = array_slice($node_data['row'], 4);
      
      // Combine and format the row data
      foreach (array_merge($core_fields, $other_fields) as $cell) {
          $row[] = '"' . str_replace('"', '""', $cell) . '"';
      }
      
      $csv_content .= implode(',', $row) . "\n";
      
    }
    
    // Set headers for file download
    $filename = $content_type . '_export_' . date('Y-m-d_H-i-s') . '.csv';
    
    $response = new Response($csv_content);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    
    return $response;
  }
 
  /**
   * Exports content data as JSON.
   *
   * @param int $uid
   *   The user ID to retrieve results for.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The JSON response or NULL if no data is available.
   */
  public function exportJson($uid) {
    try {
        // Retrieve results from tempstore
        $tempstore = \Drupal::service('tempstore.private')->get('entity_display_json');
        $results = $tempstore->get('explorer_results_' . $uid);
        
        if (empty($results) || empty($results['processed_nodes'])) {
            \Drupal::messenger()->addError($this->t('No data available for export.'));
            return NULL;
        }
        
        $content_type = $results['content_type'];
        $processed_nodes = $results['processed_nodes'];
        
        // Create structured data for JSON
        $json_data = [
            'metadata' => [
                'contentType' => $content_type,
                'contentTypeLabel' => $this->getContentTypeLabel($content_type),
                'exportDate' => date('c'),
                'totalNodes' => count($processed_nodes),
            ],
            'nodes' => [],
        ];
        
        // Add each node's data
        foreach ($processed_nodes as $node_data) {
            if (!isset($node_data['data'])) {
                continue;
            }
            
            // Create a clean node data structure
            $clean_node_data = [
                'nid' => $node_data['data']['nid'],
                'uuid' => $node_data['data']['uuid'],
                'title' => $node_data['data']['title'],
                'path' => $node_data['data']['path'],
                'metatags' => $node_data['data']['metatags'] ?? [],
                'fields' => [],
            ];
            
            // Process fields, excluding metatag fields
            if (isset($node_data['data']['fields'])) {
                foreach ($node_data['data']['fields'] as $field_name => $field_value) {
                    // Skip all metatag-related fields
                    if (in_array($field_name, ['field_metatag', 'metatags', 'field_meta_tags']) || 
                        strpos($field_name, 'metatag') !== false) {
                        continue;
                    }
                    $clean_node_data['fields'][$field_name] = $field_value;
                }
            }
            
            $json_data['nodes'][] = $clean_node_data;
        }
        
        // Set headers for file download
        $filename = $content_type . '_export_' . date('Y-m-d_H-i-s') . '.json';
        
        $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json_content = json_encode($json_data, $json_options);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        
        $response = new Response($json_content);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        
        return $response;

    } catch (\Exception $e) {
        \Drupal::logger('entity_display_json')->error('JSON export failed: @error', [
            '@error' => $e->getMessage(),
        ]);
        \Drupal::messenger()->addError($this->t('Failed to generate JSON export: @error', [
            '@error' => $e->getMessage(),
        ]));
        return NULL;
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
}
