<?php

namespace Drupal\entity_display_json\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Entity Display JSON module.
 */
class EntityDisplayJsonCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a new EntityDisplayJsonCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The alias manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AliasManagerInterface $alias_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasManager = $alias_manager;
  }

  /**
   * Lists nodes with their UUIDs and paths.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option limit
   *   Number of nodes to display. Default is 100.
   * @option bundle
   *   Filter by node bundle (content type).
   *
   * @command entity-display-json:list-nodes
   * @aliases edj-list-nodes
   */
  public function listNodes(array $options = ['limit' => 100, 'bundle' => NULL]) {
    $limit = $options['limit'];
    $bundle = $options['bundle'];
    
    // Build the query
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, $limit);
    
    // Add bundle filter if specified
    if ($bundle) {
      $query->condition('type', $bundle);
    }
    
    $nids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    
    // Prepare table headers
    $rows = [];
    $rows[] = ['ID', 'Title', 'UUID', 'Internal Path', 'Alias'];
    
    foreach ($nodes as $node) {
      $internal_path = '/node/' . $node->id();
      $alias = $this->aliasManager->getAliasByPath($internal_path);
      
      $rows[] = [
        $node->id(),
        $node->label(),
        $node->uuid(),
        $internal_path,
        $alias,
      ];
    }
    
    // Display as a table
    $this->io()->table($rows);
    $this->io()->success(sprintf('Displayed %d nodes.', count($nodes)));
  }
}
