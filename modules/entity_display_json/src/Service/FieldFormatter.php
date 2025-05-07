<?php

namespace Drupal\entity_display_json\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Psr\Log\LoggerInterface;

/**
 * Service for formatting entity field values.
 */
class FieldFormatter {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new FieldFormatter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    RendererInterface $renderer,
    TranslationInterface $string_translation,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
    $this->stringTranslation = $string_translation;
    $this->logger = $logger;
  }

  /**
   * Formats a single field value for display in the table or export.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   * @param bool $for_display
   *   Whether the value is for display or export.
   *
   * @return string
   *   The formatted field value.
   */
  public function formatFieldValue(EntityInterface $entity, $field_name, $for_display = true) {
    if (!$entity->hasField($field_name)) {
      return $this->t('N/A');
    }
    
    $field = $entity->get($field_name);
    
    if ($field->isEmpty()) {
      return $this->t('Empty');
    }
    
    $field_type = $field->getFieldDefinition()->getType();
    
    switch ($field_type) {
      case 'entity_reference':
        $referenced_entities = $field->referencedEntities();
        $labels = [];
        
        foreach ($referenced_entities as $entity) {
          $labels[] = $entity->label();
        }
        
        return implode(', ', $labels);
        
      case 'image':
      case 'file':
        $file_urls = [];
        
        foreach ($field as $item) {
          if ($item->entity) {
            $file_urls[] = $item->entity->createFileUrl(FALSE);
          }
        }
        
        return implode(', ', $file_urls);
        
      case 'link':
        $links = [];
        
        foreach ($field as $item) {
          $url = $item->uri;
          $title = $item->title ?: $url;
          $links[] = $title . ' (' . $url . ')';
        }
        
        return implode(', ', $links);
        
      case 'text_with_summary':
      case 'text_long':
      case 'text':
        $values = [];
        
        foreach ($field as $item) {
          if (isset($item->value)) {
            // Always process through renderEntityEmbeds to handle entity embeds
            // This will preserve all HTML formatting
            $values[] = $this->renderEntityEmbeds($item->value);
          }
        }
        
        return implode("\n\n", $values);
        
      case 'datetime':
      case 'timestamp':
        $dates = [];
        
        foreach ($field as $item) {
          if ($item->value) {
            try {
              // For datetime fields, use the DateTime object directly
              if ($field_type === 'datetime' && $item->date instanceof \DateTime) {
                $dates[] = $item->date->format('Y-m-d H:i:s');
              }
              // For timestamp fields or as a fallback
              else {
                // Make sure we have a valid timestamp before formatting
                $timestamp = is_numeric($item->value) ? $item->value : strtotime($item->value);
                if ($timestamp !== FALSE) {
                  $dates[] = \Drupal::service('date.formatter')->format($timestamp);
                }
                else {
                  // If we can't convert to timestamp, just use the raw value
                  $dates[] = $item->value;
                }
              }
            }
            catch (\Exception $e) {
              // If any error occurs, just use the raw value
              $dates[] = $item->value;
            }
          }
        }
        
        return implode(', ', $dates);
        
      case 'boolean':
        return $field->value ? $this->t('Yes') : $this->t('No');
        
      default:
        $values = [];
        
        foreach ($field as $item) {
          if (isset($item->value)) {
            $values[] = $item->value;
          }
        }
        
        return implode(', ', $values);
    }
  }

  /**
   * Renders entity embed code as full HTML.
   *
   * @param string $text
   *   The text containing entity embed code.
   *
   * @return string
   *   The text with entity embed code replaced with rendered HTML.
   */
  public function renderEntityEmbeds($text) {
    // Check if text contains any kind of entity embed code
    if (strpos($text, 'data-entity-type') === FALSE && strpos($text, 'drupal-entity') === FALSE) {
      return $text;
    }
    
    // Check if the entity_embed module is available
    if (!$this->moduleHandler->moduleExists('entity_embed')) {
      return $text;
    }
    
    try {
      // Find a text format that has entity_embed filter enabled
      $format = $this->findFormatWithEntityEmbed();
      
      if (!$format) {
        $this->logger->warning('No text format with entity_embed filter found. Entity embeds will not be processed.');
        return $text;
      }
      
      // Process the text through Drupal's filter system
      // This will properly render all entity embeds
      return check_markup($text, $format->id());
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing entity embeds: @error', ['@error' => $e->getMessage()]);
      return $text; // Return original text if an error occurs
    }
  }

  /**
   * Finds a text format that has the entity_embed filter enabled.
   *
   * @return \Drupal\filter\Entity\FilterFormat|null
   *   The filter format entity, or NULL if no suitable format is found.
   */
  protected function findFormatWithEntityEmbed() {
    // Try to find any format with entity_embed filter
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    foreach ($formats as $format) {
      $filters = $format->filters();
      if ($filters->has('entity_embed') && $filters->get('entity_embed')->status) {
        return $format;
      }
    }
    
    return NULL;
  }
}
