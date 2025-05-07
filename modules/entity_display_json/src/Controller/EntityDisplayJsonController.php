<?php

namespace Drupal\entity_display_json\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


/**
 * Returns responses for Entity Display JSON routes.
 */
class EntityDisplayJsonController extends ControllerBase {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The alias manager.
   *
   * @var Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  // Update the constructor to accept the date formatter service
  public function __construct(
    EntityFieldManagerInterface $entity_field_manager, 
    EntityTypeManagerInterface $entity_type_manager, 
    EntityDisplayRepositoryInterface $entity_display_repository, 
    AliasManagerInterface $aliasManager, 
    Request $request,
    \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
  ) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->aliasManager = $aliasManager;
    $this->request = $request;
    $this->dateFormatter = $date_formatter;
  }

  // Update the create method to get the date formatter service from the container
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('path_alias.manager'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('date.formatter')
    );
  }

  /**
   * Get an array with all available translations of an entity and its path.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   An array with all available translations of the entity and its path.
   */
  public function getAvailableTranslations(EntityInterface $entity) {
    $availableTranslations = [];
    $languages = $entity->getTranslationLanguages();
    foreach ($languages as $id => $language) {
      $translation = $entity->getTranslation($id);
      $url = $translation->toUrl()->toString();
      $availableTranslations[$id] = $url;
    }
    return $availableTranslations;
  }

  /**
   * Processes an entity and prepares its data for JSON representation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object to be processed.
   * @param string $langcode
   *   The language code.
   * @param string $display_id
   *   (optional) The display ID. Defaults to 'default'.
   *
   * @return array
   *   An associative array representing the processed entity data.
   */
  public function processEntity(EntityInterface $entity, $langcode, $display_id = 'default') {
    $basePath = $this->request->getSchemeAndHttpHost();
    $entity_type = $entity->getEntityTypeId();
    if ($entity_type != 'view' && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    $access = $entity->access('view', NULL, TRUE);
    if (!$access->isAllowed() && $entity_type !== 'view') {
      return [];
    }
    $bundle = $entity->bundle();
    $data = [
      'entityType' => $entity_type,
      'bundle' => $bundle,
      'uuid' => $entity->uuid(),
      'id' => $entity->id(),
      'title' => $entity->label(),
    ];

    if ($entity_type == 'file') {
      $data['file_url'] = $entity->createFileUrl(FALSE);
      $data['file_name'] = $entity->getFilename();
      $data['file_size'] = $entity->getSize();
      $data['file_mime'] = $entity->getMimeType();
    }

    if ($entity->hasLinkTemplate('canonical')) {
      $path = $entity->toUrl()->getInternalPath();
      $data['path_alias'] = $basePath . $this->aliasManager->getAliasByPath('/' . $path);
    }
    // Views support.
    if ($entity_type == 'view') {
      $data['display_id'] = $display_id;
      $view = Views::getView($data['id']);
      if (is_object($view)) {
        $page = $this->request->query->get('page');
        $page ? $view->setCurrentPage($page) : NULL;
        $view->setDisplay($display_id);
        $display_handler = $view->getDisplay();
        $row_plugin = $display_handler->getPlugin('row');
        $results = $view->executeDisplay($display_id);
        $results_array = $results['#view']->result;
        if ($row_plugin->getPluginId() === 'entity:node') {
          $view_mode = $row_plugin->options['view_mode'];
          foreach ($results_array as $key => $result) {
            $results_array[$key] = self::processEntity($result->_entity, $langcode, $view_mode === 'full' ? 'default' : $view_mode);
          }
        }
        $data['results'] = $results_array;
        $filters = $view->display_handler->getOption('filters');
        $exposed_filters = array_filter($filters, function ($filter) {
          return !empty($filter['exposed']);
        });
        if (!empty($exposed_filters)) {
          $filtered_data = [];
          foreach ($exposed_filters as $key => $filter) {
            if (isset($filter['expose']['label'])) {
              $filtered_data[$key] = $filter['expose']['label'];
            }
          }
          $data['exposed_filters'] = $filtered_data;
        }
        $pager = $view->getPager();
        $data['pager'] = [
          'type' => $pager->getPluginId(),
          'items_per_page' => $pager->getItemsPerPage(),
        ];
        $data['total_rows'] = $results['#view']->total_rows;
      }
      return $data;
    }
    $display = $this->entityDisplayRepository->getViewDisplay($entity_type, $bundle, $display_id);
    $fields = $display->getComponents();

    foreach ($fields as $field_name => $field_value) {
      if (!isset($field_value['label'])) {
        continue;
      }

      // Entity reference fields.
      if ($entity->$field_name->target_id) {
        $ref_entities = $entity->$field_name->referencedEntities();
        $display_id = $field_value["settings"]["view_mode"] ?? NULL;
        foreach ($ref_entities as $key => $ref_entity) {
          if ($field_value["type"] == 'media_thumbnail') {
            $image_style = $field_value["settings"]["image_style"];
            $medias = $ref_entity->referencedEntities();
            foreach ($medias as $media) {
              if ($media->getEntityTypeId() == "file") {
                $imageUri = $media->getFileUri();
                $imgStyle = $this->entityTypeManager->getStorage('image_style')->load($image_style);
                $data[$field_name]['image_url'] = $imgStyle->buildUrl($imageUri);

              }
            }
            continue;
          }
          $data[$field_name][$key] = self::processEntity($ref_entity, $langcode, $display_id ?? $entity->$field_name->display_id);
          if (isset($ref_entity) && ($ref_entity->getEntityTypeId() == 'file') && ($ref_entity == $ref_entities[$key])) {
            $data[$field_name][$key]['uri'] = $ref_entity->getFileUri();
          }
        }
      }

      // Normal value fields.
      if ($value = $entity->$field_name->value) {
        // Summary and or trimmed.
        $summary = $entity->$field_name->summary;
        if (isset($field_value['settings']['trim_length'])) {
          $length = $field_value['settings']['trim_length'];
          $format = $entity->$field_name->format;
          $value = strip_tags($value);
          $trimmed = text_summary($value, $format, $length);
        }
        if ($field_value['type'] === 'text_summary_or_trimmed') {
          $value = $summary ?? $trimmed ?? $value;
        }
        if ($field_value['type'] === 'text_trimmed') {
          $value = $trimmed ?? $value;
        }
        $data[$field_name] = $value;
      }

      // Special fields.
      $field_type = $entity->get($field_name)->getFieldDefinition()->getType();

      // Links.
      if ($field_type === 'link_separate' || $field_type === 'link') {
        if (!$entity->$field_name->isEmpty()) {
          $values = $entity->$field_name->getValue();
          $links = [];
          foreach ($values as $value) {
            $url = $value['uri'];
            if (strpos($url, 'internal:') === 0) {
              $url = $basePath . '/' . str_replace('internal:', '', $url);
            }
            if (strpos($url, 'entity:') === 0) {
              $url = $basePath . '/' . str_replace('entity:', '', $url);
            }
            $publicUrl = $url;
            $link = ['url' => $publicUrl];
            // Since paragraphs don't have titles, let's skip them.
            if (!empty($value['title']) && $entity_type !== 'paragraph') {
              $link['title'] = $value['title'];
            }
            $links[] = $link;
          }
          $data[$field_name] = $links;
        }
      }

      // Add labels object except for entity references.
      if ($field_value['label'] == 'above' || $field_value['label'] == 'inline') {
        $data['labels'][$field_name]['display'] = $field_value['label'];
        $data['labels'][$field_name]['text'] = $entity->get($field_name)->getFieldDefinition()->getLabel();
      }

    }
    // Field groups support.
    $display_config = $display->toArray();
    if (isset($display_config['third_party_settings']['field_group'])) {
      $field_groups = $display_config['third_party_settings']['field_group'];
      foreach ($field_groups as $group_name => $group_config) {
        if (isset($group_config['children'])) {
          $specs = [
            'label' => $group_config['label'],
            'weight' => $group_config['weight'],
            'format_type' => $group_config['format_type'],
          ];
          empty($group_config["parent_name"]) ? $data[$group_name]['specs'] = $specs : $data[$group_config["parent_name"]][$group_name]['specs'] = $specs;
          foreach ($group_config['children'] as $field_name) {
            if ($data[$field_name]) {
              empty($group_config["parent_name"]) ? $data[$group_name][$field_name] ?? $data[$group_name][$field_name] = [] : $data[$group_config["parent_name"]][$group_name][$field_name] = $data[$field_name];
              unset($data[$field_name]);
            }
          }
        }
      }
      // Mode labels to the end.
      if (isset($data['labels'])) {
        $labels = $data['labels'];
        unset($data['labels']);
        $data['labels'] = $labels;
      }
    }

    return $data;
  }

  /**
   * Generates the JSON response based on entity display settings.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $uuid
   *   The UUID of the entity.
   * @param string $langcode
   *   The language code.
   * @param string $display_id
   *   (optional) The display ID. If null, will be determined programmatically.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the entity or display cannot be found.
   */
  public function build($entity_type, $uuid, $langcode = 'default', $display_id = null) {
    $entities = $this->entityTypeManager->getStorage($entity_type)->loadByProperties(['uuid' => $uuid]);
    $entity = reset($entities);
    
    if (!$entity) {
      throw new NotFoundHttpException(sprintf('Entity with UUID %s not found.', $uuid));
    }
    
    // Determine display ID programmatically if not provided
    if ($display_id === null) {
      $display_id = $this->determineDisplayId($entity);
    }
    
    $data = $this->processEntity($entity, $langcode, $display_id);
    
    // Extract metatags and add them to the response
    $metatags = $this->extractMetatags($entity);
    if (!empty($metatags)) {
      $data['metatags'] = $metatags;
    }
    
    // Prepend apiVersion $data.
    $data = [
      'apiVersion' => '1.0',
      'langcode' => $entity->language()->getId(),
      'translations' => $entity_type != 'view' ? $this->getAvailableTranslations($entity) : [],
    ] + $data;
    
    // Return the JSON response.
    return new JsonResponse($data);
  }

  /**
   * Determines the appropriate display ID for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The display ID to use.
   */
  protected function determineDisplayId(EntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    
    // Check if there's a specific display mode for API/JSON
    $available_view_modes = $this->entityDisplayRepository->getViewModes($entity_type);
    if (isset($available_view_modes['json']) && 
        $this->entityDisplayRepository->getViewDisplay($entity_type, $bundle, 'json')) {
      return 'json';
    }
    
    // Check if there's a teaser display mode
    if (isset($available_view_modes['teaser']) && 
        $this->entityDisplayRepository->getViewDisplay($entity_type, $bundle, 'teaser')) {
      return 'teaser';
    }
    
    // Check if there's a custom display mode for this entity type
    $custom_display_id = $entity_type . '_json';
    if (isset($available_view_modes[$custom_display_id]) && 
        $this->entityDisplayRepository->getViewDisplay($entity_type, $bundle, $custom_display_id)) {
      return $custom_display_id;
    }
    
    // Default to 'default' display mode
    return 'default';
  }

  /**
   * Lists nodes with their UUIDs and paths as JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function listNodes(Request $request) {
    // Get query parameters
    $limit = $request->query->get('limit', 100);
    $bundle = $request->query->get('bundle', NULL);
    $path = $request->query->get('path', NULL);
    $page = $request->query->get('page', 0);
    
    // If path is provided, try to find the node by path
    if ($path) {
      // Remove leading slash if present
      $path = ltrim($path, '/');
      
      // Get the internal path from the alias
      $internal_path = $this->aliasManager->getPathByAlias('/' . $path);
      
      // If this is a node path, extract the node ID
      if (preg_match('/^\/node\/(\d+)/', $internal_path, $matches)) {
        $nid = $matches[1];
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple([$nid]);
        $total = count($nodes);
      } else {
        // Path doesn't match a node
        $nodes = [];
        $total = 0;
      }
    } else {
      // No path filter, use regular query
      // Build the query
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range($page * $limit, $limit);
      
      // Add bundle filter if specified
      if ($bundle) {
        $query->condition('type', $bundle);
      }
      
      $nids = $query->execute();
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      
      // Count total nodes for pagination info
      $count_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE);
      
      if ($bundle) {
        $count_query->condition('type', $bundle);
      }
      
      $total = $count_query->count()->execute();
    }
    
    $basePath = $request->getSchemeAndHttpHost();
    $result = [];
    
    foreach ($nodes as $node) {
      $internal_path = '/node/' . $node->id();
      $alias = $this->aliasManager->getAliasByPath($internal_path);
      
      $result[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'uuid' => $node->uuid(),
        'entity_type' => $node->getEntityTypeId(), // Add entity type
        'bundle' => $node->bundle(),
        'type' => $node->bundle(), // Keep 'type' for backward compatibility
        'created' => $node->getCreatedTime(),
        'changed' => $node->getChangedTime(),
        'internal_path' => $internal_path,
        'path_alias' => $alias,
        'absolute_url' => $basePath . $alias,
        'ejson_url' => $basePath . '/ejson/' . $node->getEntityTypeId() . '/' . $node->uuid(),
      ];      
    }
    
    $response = [
      'apiVersion' => '1.0',
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => $total > 0 ? ceil($total / $limit) : 0,
      'nodes' => $result,
    ];
    
    return new JsonResponse($response);
  }


  /**
   * Generates the JSON response based on entity display settings using path alias.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $display_id
   *   (optional) The display ID. Defaults to 'default'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the entity or display cannot be found.
   */
  public function buildFromPathAlias(Request $request, $display_id = 'default') {
    // Get the path from the query parameter
    $path = $request->query->get('path');

    $langcode = $request->query->get('lang', 'default');
    
    if (empty($path)) {
      throw new NotFoundHttpException('No path parameter provided.');
    }
    
    // Create a new request with the path parameter for listNodes
    $nodeRequest = clone $request;
    $nodeRequest->query->set('limit', 1);
    // path is already set in the query

    // Get the node information using listNodes
    $jsonResponse = $this->listNodes($nodeRequest);
    $data = json_decode($jsonResponse->getContent(), TRUE);

    // Check if we found a node
    if (empty($data['nodes'])) {
      throw new NotFoundHttpException(sprintf('Entity at path %s not found.', $path));
    }

    // Get the first (and only) node from the result
    $entityInfo = reset($data['nodes']);

    // Use the existing build method with the entity type and UUID
    return $this->build(
      $entityInfo['entity_type'],
      $entityInfo['uuid'],
      $langcode,
      $display_id
    );
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
      
      // Add debugging information in development environments
      if (\Drupal::state()->get('system.maintenance_mode') || 
          \Drupal::request()->query->get('debug') === 'tokens') {
        $debug_info = [
          'entity_type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
          'metatags_with_tokens' => $metatags_with_tokens,
          'metatags_with_values' => $metatags,
        ];
        
        // Find metatag fields on this entity for debugging
        foreach ($entity->getFields() as $field_name => $field) {
          if ($field->getFieldDefinition()->getType() === 'metatag') {
            $debug_info['metatag_field_found'] = TRUE;
            $debug_info['metatag_field_name'] = $field_name;
            
            if (!$entity->get($field_name)->isEmpty()) {
              $debug_info['metatag_field_value'] = $entity->get($field_name)->value;
              $unserialized = unserialize($entity->get($field_name)->value);
              if ($unserialized) {
                $debug_info['metatag_field_unserialized'] = $unserialized;
              }
            }
            
            break;
          }
        }
        
        $metatags['_debug'] = $debug_info;
      }
    }
    
    return $metatags;
  }

    /**
   * Lists all available content types.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing all content types.
   */
  public function listContentTypes() {
    // Get all node bundle definitions
    $contentTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    
    $result = [];
    foreach ($contentTypes as $type) {
      $result[] = [
        'id' => $type->id(),
        'name' => $type->label(),
        'description' => $type->getDescription(),
        'status' => $type->status(),
      ];
    }
    
    // Sort by name
    usort($result, function ($a, $b) {
      return strcmp($a['name'], $b['name']);
    });
    
    return new JsonResponse([
      'contentTypes' => $result,
      'count' => count($result),
      'metadata' => [
        'generated' => $this->dateFormatter->format(time(), 'custom', 'Y-m-d\TH:i:s\Z'),
        'source' => [
          'type' => 'drupal',
          'version' => \Drupal::VERSION,
        ],
      ],
    ]);
  }

    /**
   * Lists all fields for a specific content type.
   *
   * @param string $content_type
   *   The machine name of the content type.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing all fields for the specified content type.
   */
  public function listContentTypeFields($content_type) {
    // Verify the content type exists
    if (!$this->entityTypeManager->getStorage('node_type')->load($content_type)) {
      return new JsonResponse([
        'error' => 'Content type not found',
        'content_type' => $content_type,
      ], 404);
    }
    
    // Get all field definitions for this content type
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $content_type);
    
    $result = [];
    foreach ($fields as $field_name => $field_definition) {
      $result[$field_name] = [
        'label' => $field_definition->getLabel(),
        'type' => $field_definition->getType(),
        'required' => $field_definition->isRequired(),
        'multiple' => $field_definition->getFieldStorageDefinition()->isMultiple(),
        'description' => $field_definition->getDescription(),
        'settings' => $field_definition->getSettings(),
      ];
      
      // Add field-type specific information
      switch ($field_definition->getType()) {
        case 'entity_reference':
          $result[$field_name]['target_type'] = $field_definition->getSetting('target_type');
          $result[$field_name]['target_bundles'] = $field_definition->getSetting('handler_settings')['target_bundles'] ?? [];
          break;
          
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $result[$field_name]['max_length'] = $field_definition->getSetting('max_length');
          break;
          
        case 'image':
          $result[$field_name]['file_directory'] = $field_definition->getSetting('file_directory');
          $result[$field_name]['uri_scheme'] = $field_definition->getSetting('uri_scheme');
          break;
      }
      
      // Add display information
      $view_modes = $this->entityDisplayRepository->getViewModes('node');
      $result[$field_name]['display'] = [];
      
      foreach (array_keys($view_modes) as $view_mode) {
        $view_display = $this->entityDisplayRepository->getViewDisplay('node', $content_type, $view_mode);
        $component = $view_display->getComponent($field_name);
        
        if ($component) {
          $result[$field_name]['display'][$view_mode] = [
            'type' => $component['type'] ?? 'hidden',
            'weight' => $component['weight'] ?? 0,
            'settings' => $component['settings'] ?? [],
          ];
        } else {
          $result[$field_name]['display'][$view_mode] = ['type' => 'hidden'];
        }
      }
    }
    
    return new JsonResponse([
      'content_type' => $content_type,
      'content_type_label' => $this->entityTypeManager->getStorage('node_type')->load($content_type)->label(),
      'fields' => $result,
      'count' => count($result),
      'metadata' => [
        'generated' => $this->dateFormatter->format(time(), 'custom', 'Y-m-d\TH:i:s\Z'),
        'source' => [
          'type' => 'drupal',
          'version' => \Drupal::VERSION,
        ],
      ],
    ]);
  }

  /**
   * Not working yet.
   * Lists all entities. 
   * 
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $entity_type_id
   *   The entity type ID (e.g., 'node', 'media', 'taxonomy_term').
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function listEntities(Request $request, $entity_type_id = 'node') {
    try {
      // Validate entity type exists
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        throw new NotFoundHttpException("Entity type '$entity_type_id' not found");
      }

      // Get query parameters with defaults
      $limit = min((int) $request->query->get('limit', 50), 100); // Max 100 items
      $bundle = $request->query->get('bundle');
      $page = max((int) $request->query->get('page', 0), 0); // Ensure non-negative
      $sort_by = $request->query->get('sort', 'created');
      $sort_direction = strtoupper($request->query->get('direction', 'DESC'));

      // Get the entity type definition
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $bundle_key = $entity_type->getKey('bundle');
      $id_key = $entity_type->getKey('id');

      // Build the base query
      $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(TRUE)
        ->range($page * $limit, $limit);

      // Add bundle filter if specified and entity type has bundles
      if ($bundle && $bundle_key) {
        $query->condition($bundle_key, $bundle);
      }

      // Add sorting
      if ($entity_type->hasKey($sort_by)) {
        $query->sort($sort_by, $sort_direction);
      } elseif ($sort_by === 'created' && $entity_type->hasKey('created')) {
        $query->sort('created', $sort_direction);
      }

      // Execute query
      $ids = $query->execute();
      $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids);

      // Get total count for pagination
      $count_query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(TRUE);

      if ($bundle && $bundle_key) {
        $count_query->condition($bundle_key, $bundle);
      }

      $total = $count_query->count()->execute();

      // Build response data
      $basePath = $request->getSchemeAndHttpHost();
      $result = [];

      foreach ($entities as $entity) {
        $data = [
          'id' => $entity->id(),
          'uuid' => $entity->uuid(),
          'title' => $entity->label(),
          'entity_type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
        ];

        // Add timestamps if available
        if (method_exists($entity, 'getCreatedTime')) {
          $data['created'] = [
            'timestamp' => $entity->getCreatedTime(),
            'formatted' => $this->dateFormatter->format($entity->getCreatedTime(), 'custom', 'Y-m-d\TH:i:s\Z'),
          ];
        }

        if (method_exists($entity, 'getChangedTime')) {
          $data['changed'] = [
            'timestamp' => $entity->getChangedTime(),
            'formatted' => $this->dateFormatter->format($entity->getChangedTime(), 'custom', 'Y-m-d\TH:i:s\Z'),
          ];
        }

        // Add URLs if entity is canonical
        if ($entity->hasLinkTemplate('canonical')) {
          $url = $entity->toUrl()->toString();
          $data['urls'] = [
            'canonical' => $basePath . $url,
            'api' => $basePath . '/ejson/' . $entity->getEntityTypeId() . '/' . $entity->uuid(),
          ];

          // Add path alias if available
          if ($entity->hasLinkTemplate('canonical') && $this->aliasManager) {
            $internal_path = '/'. $entity->toUrl()->getInternalPath();
            $alias = $this->aliasManager->getAliasByPath($internal_path);
            if ($alias !== $internal_path) {
              $data['urls']['alias'] = $basePath . $alias;
            }
          }
        }

        // Add language information if entity is translatable
        if ($entity->isTranslatable()) {
          $data['langcode'] = $entity->language()->getId();
          $data['translations'] = array_keys($entity->getTranslationLanguages());
        }

        $result[] = $data;
      }

      // Build pagination data
      $total_pages = $total > 0 ? ceil($total / $limit) : 0;
      $next_page = $page + 1 < $total_pages ? $page + 1 : null;
      $prev_page = $page > 0 ? $page - 1 : null;

      // Construct response
      $response = [
        'apiVersion' => '1.0',
        'entityType' => $entity_type_id,
        'bundle' => $bundle,
        'metadata' => [
          'total' => $total,
          'page' => $page,
          'limit' => $limit,
          'pages' => $total_pages,
          'generated' => $this->dateFormatter->format(time(), 'custom', 'Y-m-d\TH:i:s\Z'),
        ],
        'pagination' => [
          'current' => $page,
          'next' => $next_page,
          'previous' => $prev_page,
        ],
        'results' => $result,
      ];

      return new JsonResponse($response);

    } catch (NotFoundHttpException $e) {
      return new JsonResponse([
        'error' => 'Not Found',
        'message' => $e->getMessage(),
      ], 404);
    } catch (\Exception $e) {
      \Drupal::logger('entity_display_json')->error('Error in listEntities: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'An error occurred while processing the request.',
      ], 500);
    }
  }
  
}