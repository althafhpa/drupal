<?php

namespace Drupal\assets_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;

/**
 * Controller for the Assets Metadata API.
 */
class AssetsMetadataApiController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new AssetsMetadataApiController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager, 
    FileSystemInterface $file_system,
    Connection $database,
    ClientInterface $http_client
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->database = $database;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('database'),
      $container->get('http_client')
    );
  }

  /**
   * Returns assets metadata as JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getMetadata(Request $request) {
    // Get start and end dates from query parameters
    $start_date = $request->query->get('start_date', date('Y-m-d', strtotime('-7 days')));
    $end_date = $request->query->get('end_date', date('Y-m-d'));
    
    // Get delete-unused parameter
    $delete_unused = $request->query->has('delete-unused');
    
    // Get assets site URL parameter
    $assets_site_url = $request->query->get('assets-site-url', $request->getSchemeAndHttpHost());
    
    // Determine if we should preserve physical files
    $db_only = FALSE;
    if ($delete_unused && $assets_site_url !== $request->getSchemeAndHttpHost()) {
      $db_only = TRUE;
    }
    
    // Fixed batch size
    $batch_size = 100;
    
    // Convert dates to timestamps
    $start_timestamp = strtotime($start_date . ' 00:00:00');
    $end_timestamp = strtotime($end_date . ' 23:59:59');
    
    // Validate dates
    if (!$start_timestamp || !$end_timestamp) {
      return new JsonResponse([
        'error' => 'Invalid date format. Use YYYY-MM-DD.',
      ], 400);
    }
    
    if ($start_timestamp > $end_timestamp) {
      return new JsonResponse([
        'error' => 'Start date must be before end date.',
      ], 400);
    }
    
    // Calculate the difference in days
    $days_diff = floor(($end_timestamp - $start_timestamp) / (60 * 60 * 24));
    
    // Limit to 90 days
    if ($days_diff > 90) {
      return new JsonResponse([
        'error' => 'Date range cannot exceed 90 days. Current range: ' . $days_diff . ' days.',
      ], 400);
    }
    
    // Get file IDs within the date range - using changed date instead of created
    $query = $this->entityTypeManager->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('changed', [$start_timestamp, $end_timestamp], 'BETWEEN')
      ->sort('changed', 'DESC');
    
    $fids = $query->execute();
    
    if (empty($fids)) {
      return new JsonResponse([
        'metadata' => [],
        'count' => 0,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'assets_site_url' => $assets_site_url,
      ]);
    }
    
    // Delete unused files if requested
    $deletion_results = [];
    if ($delete_unused) {
      $deletion_results = $this->deleteUnusedFiles($fids, $batch_size, $db_only, $assets_site_url);
      
      // After deletion, we need to refresh the file IDs list as some may have been deleted
      $query = $this->entityTypeManager->getStorage('file')->getQuery()
        ->accessCheck(FALSE)
        ->condition('changed', [$start_timestamp, $end_timestamp], 'BETWEEN')
        ->sort('changed', 'DESC');
      
      $fids = $query->execute();
      
      if (empty($fids)) {
        // All files in the date range were deleted
        return new JsonResponse([
          'metadata' => [],
          'count' => 0,
          'start_date' => $start_date,
          'end_date' => $end_date,
          'assets_site_url' => $assets_site_url,
          'deletion_results' => $deletion_results,
          'message' => 'All files in the date range were deleted as unused.',
        ]);
      }
    }
    
    // Process files
    $metadata = [];
    $duplicate_files = [];
    $processed = 0;
    
    // Load files in chunks to avoid memory issues
    $chunks = array_chunk($fids, $batch_size);
    
    foreach ($chunks as $chunk) {
      $files = $this->entityTypeManager->getStorage('file')->loadMultiple($chunk);
      
      foreach ($files as $file) {
        $uri = $file->getFileUri();
        $real_path = $this->fileSystem->realpath($uri);
        
        // Transform URI from public://path to sites/default/files/path
        $transformed_uri = str_replace('public://', 'sites/default/files/', $uri);
        
        // Extract filename from path (last part only)
        $parts = explode('/', $transformed_uri);
        $extracted_filename = end($parts);
        
        // Get file metadata
        $file_metadata = [
          'fid' => $file->id(),
          'filename' => $extracted_filename,
          'uri' => $transformed_uri,
          'path' => $transformed_uri,
          'real_path' => $real_path,
          'drupal_url' => $request->getSchemeAndHttpHost() . '/' . $transformed_uri,
          'assets_url' => rtrim($assets_site_url, '/') . '/' . $transformed_uri,
          'metadata' => [
            'filesize' => $file->getSize(),
            'changed' => $file->getChangedTime(),
            'created' => $file->getCreatedTime(),
            'mime' => $file->getMimeType(),
            'md5' => file_exists($real_path) ? md5_file($real_path) : '',
          ],
        ];
        
        // Check for duplicates based on full path
        $duplicate = FALSE;
        foreach ($metadata as $existing) {
          if ($existing['path'] === $transformed_uri) {
            $duplicate = TRUE;
            $duplicate_files[] = $file_metadata;
            break;
          }
        }
        
        if (!$duplicate) {
          $metadata[] = $file_metadata;
        }
        
        $processed++;
      }
    }
    
    // Prepare response
    $response = [
      'metadata' => $metadata,
      'count' => count($metadata),
      'duplicate_count' => count($duplicate_files),
      'total_processed' => $processed,
      'start_date' => $start_date,
      'end_date' => $end_date,
      'assets_site_url' => $assets_site_url,
    ];
    
    // Add deletion results if applicable
    if ($delete_unused) {
      $response['deletion_results'] = $deletion_results;
    }
    
    // Return the JSON response
    return new JsonResponse($response);
  }

  /**
   * Deletes unused files within a set of file IDs.
   *
   * @param array $fids
   *   Array of file IDs to check.
   * @param int $batch_size
   *   Batch size for processing.
   * @param bool $db_only
   *   Whether to delete only from database and preserve physical files.
   * @param string|null $assets_site_url
   *   Optional assets site URL for checking file existence. Defaults to current site.
   *
   * @return array
   *   Results of the deletion operation.
   */
  protected function deleteUnusedFiles(array $fids, $batch_size = 100, $db_only = false, $assets_site_url = null) {
    $file_storage = $this->entityTypeManager->getStorage('file');
    $unused_files = [];
    $deleted_count = 0;
    $error_count = 0;
    $file_deleted_count = 0;
    
    // If no assets site URL provided, use current site URL
    if ($assets_site_url === null) {
      $assets_site_url = $this->getRequest()->getSchemeAndHttpHost();
    }
    
    // Process files in batches
    $batches = array_chunk($fids, $batch_size);
    
    foreach ($batches as $batch_fids) {
      $files = $file_storage->loadMultiple($batch_fids);
      
      foreach ($files as $file) {
        // Check if file is used in any entity
        $usage = $this->database->select('file_usage', 'fu')
          ->fields('fu')
          ->condition('fid', $file->id())
          ->execute()
          ->fetchAll();
        
        if (empty($usage)) {
          // Transform URI from public://path to sites/default/files/path
          $uri = $file->getFileUri();
          $transformed_uri = str_replace('public://', 'sites/default/files/', $uri);
          $real_path = $this->fileSystem->realpath($uri);
          
          // Check if file exists on remote server
          $remote_url = rtrim($assets_site_url, '/') . '/' . $transformed_uri;
          $file_exists_on_remote = FALSE;
          
          try {
            $response = $this->httpClient->request('HEAD', $remote_url, [
              'timeout' => 10,
              'connect_timeout' => 10,
              'http_errors' => false,
            ]);
            $file_exists_on_remote = ($response->getStatusCode() == 200);
          }
          catch (\Exception $e) {
            $file_exists_on_remote = FALSE;
          }
          
          // Add file info to unused files list
          $unused_files[] = [
            'fid' => $file->id(),
            'filename' => $file->getFilename(),
            'uri' => $file->getFileUri(),
            'real_path' => $real_path,
            'size' => $file->getSize(),
            'changed' => $file->getChangedTime(),
            'exists_on_remote' => $file_exists_on_remote,
            'remote_url' => $remote_url,
          ];
          
          try {
            // Check if physical file exists before attempting to delete
            $file_exists = file_exists($real_path);
            
            // Delete physical file if it exists and we're not in db-only mode
            if ($file_exists && !$db_only) {
              if ($this->fileSystem->delete($uri)) {
                $file_deleted_count++;
              }
            }
            
            // Delete the file entity from database
            $file->delete();
            $deleted_count++;
          }
          catch (\Exception $e) {
            $error_count++;
          }
        }
      }
    }
    
    return [
      'unused_files_count' => count($unused_files),
      'entities_deleted' => $deleted_count,
      'physical_files_deleted' => $file_deleted_count,
      'errors' => $error_count,
      'db_only_mode' => $db_only,
      'assets_site_url' => $assets_site_url,
      'unused_files' => $unused_files,
    ];
  }
}
