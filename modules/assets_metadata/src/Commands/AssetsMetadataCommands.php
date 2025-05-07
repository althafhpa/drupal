<?php

namespace Drupal\assets_metadata\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\assets_metadata\FileMetadataManager;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Console\Helper\Table;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


/**
 * Drush commands for assets metadata.
 *
 * @package Drupal\assets_metadata\Commands
 */
class AssetsMetadataCommands extends DrushCommands {

   /**
   * Remote assets base URL.
   */
  const REMOTE_ASSETS_URL = 'https://dp.uat.uts.edu.au/globalassets';

  /**
   * Data directory for storing metadata files.
   */
  const DATA_DIRECTORY = 'docroot/modules/custom/assets_metadata/data';

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructor.
   */
  public function __construct() {
    parent::__construct();
    $this->fileSystem = \Drupal::service('file_system');
  }

  /**
   * Refresh the assets metadata.
   *
   * @command assets-meta:refresh
   * @aliases amr
   * @option force Force a fresh start, ignoring any existing progress
   * @option resume Resume from the last processed batch
   * @option batch-size Number of files to process in each batch
   * @option start-date Start date in YYYY-MM-DD format
   * @option end-date End date in YYYY-MM-DD format
   * @option skip-file-check Skip checking if files exist on disk
   * @usage drush assets-meta:refresh
   *   Refresh assets metadata, starting from the beginning.
   * @usage drush assets-meta:refresh --resume
   *   Resume refreshing metadata from where it left off.
   * @usage drush assets-meta:refresh --force
   *   Force a fresh start, ignoring any existing progress.
   * @usage drush assets-meta:refresh --batch-size=100
   *   Process files in batches of 100.
   * @usage drush assets-meta:refresh --start-date=2023-01-01 --end-date=2023-03-31
   *   Process files changed between Jan 1, 2023 and Mar 31, 2023.
   * @usage drush assets-meta:refresh --skip-file-check
   *   Process files without checking if they exist on disk.
   */
  public function refresh($options = [
    'force' => FALSE,
    'resume' => FALSE,
    'batch-size' => 50,
    'start-date' => NULL,
    'end-date' => NULL,
    'skip-file-check' => FALSE,
  ]) {
    try {
      $state = \Drupal::state();
      
      // Ensure data directory exists
      $data_dir = 'public://assets_metadata';
      $this->fileSystem->prepareDirectory($data_dir, FileSystemInterface::CREATE_DIRECTORY);
      
      // Define output file paths
      $metadata_file = $data_dir . '/metadata.json';
      $abandoned_file = $data_dir . '/drupal-abandoned-files.json';
      $duplicate_file = $data_dir . '/duplicate-asset-files.json';
      
      // Determine starting batch
      $start_batch = 0;
      
      if ($options['force']) {
        // Force starting from the beginning
        $state->delete('assets_metadata.refresh.last_batch');
        $state->delete('assets_metadata.refresh.processed_fids');
        $this->logger()->notice('Forcing fresh start, cleared existing progress.');
      }
      else if ($options['resume']) {
        // Resume from last saved position
        $start_batch = $state->get('assets_metadata.refresh.last_batch', 0);
        if ($start_batch > 0) {
          $this->logger()->notice(dt('Resuming from batch @batch.', [
            '@batch' => $start_batch + 1,
          ]));
        }
      }

      if ($options['skip-file-check']) {
        $this->logger()->notice('File existence checking is disabled.');
      }

      $entity_type_manager = \Drupal::entityTypeManager();

      // Get all file IDs
      $query = $entity_type_manager->getStorage('file')->getQuery();
      $query->accessCheck(FALSE);
      
      // Add date range conditions if provided
      if ($options['start-date'] && $options['end-date']) {
        $start_timestamp = strtotime($options['start-date'] . ' 00:00:00');
        $end_timestamp = strtotime($options['end-date'] . ' 23:59:59');
        
        if (!$start_timestamp || !$end_timestamp) {
          throw new \Exception('Invalid date format. Use YYYY-MM-DD.');
        }
        
        if ($start_timestamp > $end_timestamp) {
          throw new \Exception('Start date must be before end date.');
        }
        
        // Calculate the difference in days for informational purposes
        $days_diff = floor(($end_timestamp - $start_timestamp) / (60 * 60 * 24));
        
        // Use changed date for filtering
        $query->condition('changed', [$start_timestamp, $end_timestamp], 'BETWEEN');
        $this->logger()->notice(dt('Processing files changed between @start and @end (@days days)', [
          '@start' => $options['start-date'],
          '@end' => $options['end-date'],
          '@days' => $days_diff,
        ]));
      }
      
      $fids = $query->execute();

      if (empty($fids)) {
        $this->logger()->warning('No files found to process.');
        return;
      }

      $this->logger()->notice(dt('Found @count files to process.', [
        '@count' => count($fids),
      ]));

      // Get previously processed FIDs
      $processed_fids = $state->get('assets_metadata.refresh.processed_fids', []);

      // Load existing metadata if resuming
      $metadata = [];
      $abandoned_files = [];
      $duplicate_files = [];

      if (file_exists($metadata_file) && $options['resume']) {
        $metadata = json_decode(file_get_contents($metadata_file), TRUE) ?: [];
        $this->logger()->notice(dt('Loaded @count existing metadata entries.', [
          '@count' => count($metadata),
        ]));
      }

      if (file_exists($abandoned_file) && $options['resume']) {
        $abandoned_files = json_decode(file_get_contents($abandoned_file), TRUE) ?: [];
        $this->logger()->notice(dt('Loaded @count existing abandoned files.', [
          '@count' => count($abandoned_files),
        ]));
      }

      if (file_exists($duplicate_file) && $options['resume']) {
        $duplicate_files = json_decode(file_get_contents($duplicate_file), TRUE) ?: [];
        $this->logger()->notice(dt('Loaded @count existing duplicate files.', [
          '@count' => count($duplicate_files),
        ]));
      }

      // Process files in batches
      $batch_size = (int) $options['batch-size'];
      $batches = array_chunk($fids, $batch_size);
      $total_batches = count($batches);

      $this->logger()->notice(dt('Processing @count files in @batches batches of @size.', [
        '@count' => count($fids),
        '@batches' => $total_batches,
        '@size' => $batch_size,
      ]));

      $batch_count = 0;
      $processed_count = 0;
      $abandoned_count = 0;
      $duplicate_count = 0;

      foreach ($batches as $batch_index => $batch_fids) {
        // Skip batches we've already processed
        if ($batch_index < $start_batch) {
          continue;
        }

        $this->logger()->notice(dt('Processing batch @current of @total...', [
          '@current' => $batch_index + 1,
          '@total' => $total_batches,
        ]));

        // Load files for this batch
        $files = $entity_type_manager->getStorage('file')->loadMultiple($batch_fids);

        foreach ($files as $file) {
          // Skip if already processed
          if (in_array($file->id(), $processed_fids)) {
            continue;
          }

          $uri = $file->getFileUri();
          $real_path = \Drupal::service('file_system')->realpath($uri);
          
          // Transform URI from public://path to sites/default/files/path
          $transformed_uri = str_replace('public://', 'sites/default/files/', $uri);
          
          // Extract filename from path (last part only)
          $parts = explode('/', $transformed_uri);
          $extracted_filename = end($parts);
          
          // Check if file exists on disk (unless skipped)
          $file_exists = $options['skip-file-check'] ? TRUE : file_exists($real_path);
          
          if (!$file_exists) {
            $abandoned_files[] = [
              'fid' => $file->id(),
              'filename' => $extracted_filename,
              'uri' => $transformed_uri,
              'real_path' => $real_path,
            ];
            $abandoned_count++;
            $this->logger()->warning(dt('File not found on disk: @uri', [
              '@uri' => $uri,
            ]));
          }
          else {
            // Check for duplicates based on filename
            $duplicate = FALSE;
            foreach ($metadata as $existing) {
              if ($existing['filename'] === $extracted_filename) {
                $duplicate_files[] = [
                  'fid' => $file->id(),
                  'filename' => $extracted_filename,
                  'uri' => $transformed_uri,
                  'real_path' => $real_path,
                  'duplicate_of' => $existing['fid'],
                ];
                $duplicate = TRUE;
                $duplicate_count++;
                break;
              }
            }
            
            if (!$duplicate) {
              // Add to metadata
              $metadata_entry = [
                'fid' => $file->id(),
                'filename' => $extracted_filename,
                'uri' => $transformed_uri,
                'path' => $transformed_uri,
                'real_path' => $real_path,
                'drupal_url' => \Drupal::request()->getSchemeAndHttpHost() . '/' . $transformed_uri,
                'metadata' => [
                  'filesize' => $file->getSize(),
                  'changed' => $file->getChangedTime(),
                  'created' => $file->getCreatedTime(),
                  'mime' => $file->getMimeType(),
                ],
              ];
              
              // Only calculate MD5 if file exists and we're not skipping file checks
              if (!$options['skip-file-check'] && file_exists($real_path)) {
                $metadata_entry['metadata']['md5'] = md5_file($real_path);
              }
              
              $metadata[] = $metadata_entry;
            }
          }
          
          // Mark as processed
          $processed_fids[] = $file->id();
          $processed_count++;
          
          // Save progress every 100 files
          if ($processed_count % 100 === 0) {
            $this->logger()->notice(dt('Processed @count files...', [
              '@count' => $processed_count,
            ]));
            
            // Save current state
            $state->set('assets_metadata.refresh.processed_fids', $processed_fids);
            $state->set('assets_metadata.refresh.last_batch', $batch_index);
            
            // Save files
            file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT));
            file_put_contents($abandoned_file, json_encode($abandoned_files, JSON_PRETTY_PRINT));
            file_put_contents($duplicate_file, json_encode($duplicate_files, JSON_PRETTY_PRINT));
          }
        }
        
        $batch_count++;
        
        // Save current state after each batch
        $state->set('assets_metadata.refresh.processed_fids', $processed_fids);
        $state->set('assets_metadata.refresh.last_batch', $batch_index);
        
        // Save files after each batch
        file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT));
        file_put_contents($abandoned_file, json_encode($abandoned_files, JSON_PRETTY_PRINT));
        file_put_contents($duplicate_file, json_encode($duplicate_files, JSON_PRETTY_PRINT));
        
        $this->logger()->notice(dt('Completed batch @current of @total.', [
          '@current' => $batch_index + 1,
          '@total' => $total_batches,
        ]));
      }
      
      // Final save
      file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT));
      file_put_contents($abandoned_file, json_encode($abandoned_files, JSON_PRETTY_PRINT));
      file_put_contents($duplicate_file, json_encode($duplicate_files, JSON_PRETTY_PRINT));
      
      // Clear state if all done
      if ($batch_count === $total_batches) {
        $state->delete('assets_metadata.refresh.last_batch');
        $state->delete('assets_metadata.refresh.processed_fids');
      }
      
      $this->logger()->success(dt('Metadata refresh complete. Processed @count files, found @abandoned abandoned files and @duplicates duplicates.', [
        '@count' => $processed_count,
        '@abandoned' => $abandoned_count,
        '@duplicates' => $duplicate_count,
      ]));
      
      $this->logger()->notice(dt('Metadata saved to:'));
      $this->logger()->notice(dt('- @file', ['@file' => $metadata_file]));
      $this->logger()->notice(dt('- @file', ['@file' => $abandoned_file]));
      $this->logger()->notice(dt('- @file', ['@file' => $duplicate_file]));
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
      return;
    }
  }

  /**
   * Check if files exist on remote server and generate metadata with transformed URIs.
   *
   * @command assets-meta:check-remote
   * @aliases amcr
   * @option base-url Base URL for remote assets (defaults to current site URL)
   * @option output Output file path for the metadata JSON
   * @option timeout Connection timeout in seconds
   * @option batch-size Number of files to process in each batch
   * @option missing-only Save only missing files to the metadata file
   * @option start-batch Batch number to start from (for resuming)
   * @option force Force starting from the beginning, ignoring saved progress
   * @option start-date Start date in YYYY-MM-DD format (filter by changed date)
   * @option end-date End date in YYYY-MM-DD format (filter by changed date)
   * @usage drush assets-meta:check-remote
   *   Check remote files with default settings.
   * @usage drush assets-meta:check-remote --missing-only
   *   Check remote files and only save missing ones.
   * @usage drush assets-meta:check-remote --start-date=2023-01-01 --end-date=2023-12-31
   *   Check files changed between Jan 1, 2023 and Dec 31, 2023.
   * @usage drush assets-meta:check-remote --base-url=https://example.com/assets
   *   Check files against a custom remote URL.
   */
  public function checkRemote($options = [
    'base-url' => NULL,
    'output' => 'assets-remote-missing.json',
    'timeout' => 10,
    'batch-size' => 50,
    'missing-only' => FALSE,
    'start-batch' => 0,
    'force' => FALSE,
    'start-date' => NULL,
    'end-date' => NULL,
  ]) {
    try {
      $state = \Drupal::state();
      
      // Define the data directory path
      $data_dir = 'public://assets_metadata';
      
      // Create the directory if it doesn't exist
      $this->fileSystem->prepareDirectory($data_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
      
      // Prepend the data directory to output filenames
      $output_file = $data_dir . '/' . $options['output'];
      
      // Use current site URL if base-url not specified
      if (empty($options['base-url'])) {
        $options['base-url'] = \Drupal::request()->getSchemeAndHttpHost();
        $this->logger()->notice(dt('Using current site URL as base: @url', [
          '@url' => $options['base-url'],
        ]));
      } else {
        $this->logger()->notice(dt('Using specified base URL: @url', [
          '@url' => $options['base-url'],
        ]));
      }
      
      // Determine starting batch
      $start_batch = (int) $options['start-batch'];
      
      if ($options['force']) {
        // Force starting from the beginning
        $state->delete('assets_metadata.check_remote.last_batch');
        $state->delete('assets_metadata.check_remote.processed_fids');
        $this->logger()->notice('Forcing fresh start, cleared existing progress.');
        $start_batch = 0;
      }
      
      $entity_type_manager = \Drupal::entityTypeManager();
      
      // Get all file IDs
      $query = $entity_type_manager->getStorage('file')->getQuery();
      $query->accessCheck(FALSE);
      
      // Add date range conditions if provided
      if ($options['start-date'] && $options['end-date']) {
        $start_timestamp = strtotime($options['start-date'] . ' 00:00:00');
        $end_timestamp = strtotime($options['end-date'] . ' 23:59:59');
        
        if (!$start_timestamp || !$end_timestamp) {
          throw new \Exception('Invalid date format. Use YYYY-MM-DD.');
        }
        
        if ($start_timestamp > $end_timestamp) {
          throw new \Exception('Start date must be before end date.');
        }
        
        // Calculate the difference in days for informational purposes
        $days_diff = floor(($end_timestamp - $start_timestamp) / (60 * 60 * 24));
        
        // Use changed date for filtering
        $query->condition('changed', [$start_timestamp, $end_timestamp], 'BETWEEN');
        $this->logger()->notice(dt('Processing files changed between @start and @end (@days days)', [
          '@start' => $options['start-date'],
          '@end' => $options['end-date'],
          '@days' => $days_diff,
        ]));
      }
      
      $fids = $query->execute();
      
      if (empty($fids)) {
        $this->logger()->warning('No files found to process.');
        return;
      }
      
      $this->logger()->notice(dt('Found @count files to process.', [
        '@count' => count($fids),
      ]));
      
      // Get previously processed FIDs
      $processed_fids = $state->get('assets_metadata.check_remote.processed_fids', []);
      
      // Load existing metadata if resuming
      $metadata = [];
      
      if (file_exists($output_file) && $start_batch > 0) {
        $metadata = json_decode(file_get_contents($output_file), TRUE) ?: [];
        $this->logger()->notice(dt('Loaded @count existing metadata entries.', [
          '@count' => count($metadata),
        ]));
      }
      
      // Process files in batches
      $batch_size = (int) $options['batch-size'];
      $batches = array_chunk($fids, $batch_size);
      $total_batches = count($batches);
      
      $this->logger()->notice(dt('Processing @count files in @batches batches of @size.', [
        '@count' => count($fids),
        '@batches' => $total_batches,
        '@size' => $batch_size,
      ]));
      
      $batch_count = 0;
      $processed_count = 0;
      $missing_count = 0;
      $existing_count = 0;
      
      // Create HTTP client
      $client = new Client([
        'timeout' => (int) $options['timeout'],
        'verify' => FALSE,
      ]);
      
      foreach ($batches as $batch_index => $batch_fids) {
        // Skip batches we've already processed
        if ($batch_index < $start_batch) {
          continue;
        }
        
        $this->logger()->notice(dt('Processing batch @current of @total...', [
          '@current' => $batch_index + 1,
          '@total' => $total_batches,
        ]));
        
        // Load files for this batch
        $files = $entity_type_manager->getStorage('file')->loadMultiple($batch_fids);
        
        foreach ($files as $file) {
          // Skip if already processed
          if (in_array($file->id(), $processed_fids)) {
            continue;
          }
          
          $uri = $file->getFileUri();
          $real_path = \Drupal::service('file_system')->realpath($uri);
          
          // Transform URI from public://path to sites/default/files/path
          $transformed_uri = str_replace('public://', 'sites/default/files/', $uri);
          
          // Create remote URL
          $remote_url = rtrim($options['base-url'], '/') . '/' . $transformed_uri;
          
          // Check if file exists on remote server
          $exists = FALSE;
          $status_code = 0;
          
          try {
            $response = $client->head($remote_url);
            $status_code = $response->getStatusCode();
            $exists = ($status_code >= 200 && $status_code < 300);
          }
          catch (RequestException $e) {
            $status_code = $e->getCode();
            $exists = FALSE;
          }
          
          // Add to metadata
          $file_data = [
            'fid' => $file->id(),
            'filename' => $file->getFilename(),
            'uri' => $uri,
            'transformed_uri' => $transformed_uri,
            'remote_url' => $remote_url,
            'exists' => $exists,
            'status_code' => $status_code,
            'metadata' => [
              'filesize' => $file->getSize(),
              'changed' => $file->getChangedTime(),
              'created' => $file->getCreatedTime(),
              'mime' => $file->getMimeType(),
            ],
          ];
          
          // Only add to metadata if not missing-only or if file is missing
          if (!$options['missing-only'] || !$exists) {
            $metadata[] = $file_data;
            
            if (!$exists) {
              $missing_count++;
            }
            else {
              $existing_count++;
            }
          }
          else if ($exists) {
            $existing_count++;
          }
          
          // Mark as processed
          $processed_fids[] = $file->id();
          $processed_count++;
          
          // Save progress every 100 files
          if ($processed_count % 100 === 0) {
            $this->logger()->notice(dt('Processed @count files...', [
              '@count' => $processed_count,
            ]));
            
            // Save current state
            $state->set('assets_metadata.check_remote.processed_fids', $processed_fids);
            $state->set('assets_metadata.check_remote.last_batch', $batch_index);
            
            // Save files
            file_put_contents($output_file, json_encode($metadata, JSON_PRETTY_PRINT));
          }
        }
        
        $batch_count++;
        
        // Save current state after each batch
        $state->set('assets_metadata.check_remote.processed_fids', $processed_fids);
        $state->set('assets_metadata.check_remote.last_batch', $batch_index);
        
        // Save files after each batch
        file_put_contents($output_file, json_encode($metadata, JSON_PRETTY_PRINT));
        
        $this->logger()->notice(dt('Completed batch @current of @total.', [
          '@current' => $batch_index + 1,
          '@total' => $total_batches,
        ]));
      }
      
      // Final save
      file_put_contents($output_file, json_encode($metadata, JSON_PRETTY_PRINT));
      
      // Clear state if all done
      if ($batch_count === $total_batches) {
        $state->delete('assets_metadata.check_remote.last_batch');
        $state->delete('assets_metadata.check_remote.processed_fids');
      }
      
      $this->logger()->success(dt('Remote check complete. Processed @count files, found @missing missing and @existing existing.', [
        '@count' => $processed_count,
        '@missing' => $missing_count,
        '@existing' => $existing_count,
      ]));
      
      $this->logger()->notice(dt('Metadata saved to: @file', [
        '@file' => $output_file,
      ]));
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
      return;
    }
  }

  /**
   * Finds and optionally deletes unused file entities.
   *
   * @command assets-meta:delete-unused
   * @aliases amdu
   * @option dry-run Only report unused files without deleting them
   * @option batch-size Number of files to process in each batch
   * @option age-days Only consider files older than this many days
   * @option resume Resume from the last processed batch
   * @option force Force starting from the beginning, ignoring saved progress
   * @option db-only Delete only from database, preserve physical files
   * @option assets-site-url Base URL for assets site (defaults to current site URL)
   * @usage drush assets-meta:delete-unused
   *   Find and delete unused file entities and physical files.
   * @usage drush assets-meta:delete-unused --dry-run
   *   Only report unused files without deleting them.
   * @usage drush assets-meta:delete-unused --age-days=30
   *   Only consider files older than 30 days.
   * @usage drush assets-meta:delete-unused --batch-size=500
   *   Process files in batches of 500.
   * @usage drush assets-meta:delete-unused --resume
   *   Resume processing from where it stopped previously.
   * @usage drush assets-meta:delete-unused --db-only
   *   Delete only from database, preserve physical files.
   * @usage drush assets-meta:delete-unused --assets-site-url=https://example.com
   *   Check file existence against a specific assets site URL.
   */
  public function deleteUnused($options = [
    'dry-run' => FALSE,
    'batch-size' => 50,
    'age-days' => 0,
    'resume' => FALSE,
    'force' => FALSE,
    'db-only' => FALSE,
    'assets-site-url' => NULL,
  ]) {
    try {
      $entity_type_manager = \Drupal::entityTypeManager();
      $database = \Drupal::database();
      $file_storage = $entity_type_manager->getStorage('file');
      $client = \Drupal::httpClient();
      $state = \Drupal::state();
      $file_system = \Drupal::service('file_system');
      
      // Get the assets site URL - use provided option, or current site URL as default
      $assets_site_url = $options['assets-site-url'] ?: \Drupal::request()->getSchemeAndHttpHost();
      $this->logger()->notice(dt('Using assets site URL: @url', [
        '@url' => $assets_site_url,
      ]));
      
      // Determine starting batch
      $start_batch = 0;
      if ($options['force']) {
        // Force starting from the beginning
        $state->delete('assets_metadata.delete_unused.last_batch');
        $state->delete('assets_metadata.delete_unused.processed_fids');
        $state->delete('assets_metadata.delete_unused.unused_files');
        $state->delete('assets_metadata.delete_unused.deleted_count');
        $state->delete('assets_metadata.delete_unused.error_count');
        $state->delete('assets_metadata.delete_unused.file_deleted_count');
        $this->logger()->notice('Forcing fresh start, cleared existing progress.');
      }
      else if ($options['resume']) {
        // Resume from last saved position
        $start_batch = $state->get('assets_metadata.delete_unused.last_batch', 0);
        if ($start_batch > 0) {
          $this->logger()->notice(dt('Resuming from batch @batch.', [
            '@batch' => $start_batch + 1,
          ]));
        }
      }
      
      // Get all file IDs
      $query = $file_storage->getQuery()->accessCheck(FALSE);
      
      // Apply age filter if specified
      if ($options['age-days'] > 0) {
        $timestamp = strtotime('-' . $options['age-days'] . ' days');
        $query->condition('changed', $timestamp, '<');
      }
      
      $fids = $query->execute();
      
      if (empty($fids)) {
        $this->logger()->warning('No files found to process.');
        return;
      }
      
      $this->logger()->notice(dt('Checking @count files for usage.', [
        '@count' => count($fids),
      ]));
      
      // Process files in batches
      $batch_size = $options['batch-size'];
      $total_batches = ceil(count($fids) / $batch_size);
      
      // Load existing data if resuming
      $unused_files = [];
      $deleted_count = 0;
      $error_count = 0;
      $file_deleted_count = 0;
      $processed_fids = [];
      
      if ($start_batch > 0 && $options['resume']) {
        $unused_files = $state->get('assets_metadata.delete_unused.unused_files', []);
        $deleted_count = $state->get('assets_metadata.delete_unused.deleted_count', 0);
        $error_count = $state->get('assets_metadata.delete_unused.error_count', 0);
        $file_deleted_count = $state->get('assets_metadata.delete_unused.file_deleted_count', 0);
        $processed_fids = $state->get('assets_metadata.delete_unused.processed_fids', []);
        
        $this->logger()->notice(dt('Resuming with @unused unused files, @deleted deleted entities, @files_deleted deleted files, @errors errors.', [
          '@unused' => count($unused_files),
          '@deleted' => $deleted_count,
          '@files_deleted' => $file_deleted_count,
          '@errors' => $error_count,
        ]));
      }
      
      // Process batches
      for ($i = $start_batch; $i < $total_batches; $i++) {
        $batch_fids = array_slice($fids, $i * $batch_size, $batch_size);
        
        // Skip already processed FIDs if resuming
        if (!empty($processed_fids)) {
          $batch_fids = array_diff($batch_fids, $processed_fids);
        }
        
        if (empty($batch_fids)) {
          $this->logger()->notice(dt('Batch @current has no new files to process, skipping.', [
            '@current' => $i + 1,
          ]));
          continue;
        }
        
        $this->logger()->notice(dt('Processing batch @current of @total', [
          '@current' => $i + 1,
          '@total' => $total_batches,
        ]));
        
        $files = $file_storage->loadMultiple($batch_fids);
        
        foreach ($files as $file) {
          // Add to processed FIDs
          $processed_fids[] = $file->id();
          
          // Check if file is used in any entity
          $usage = $database->select('file_usage', 'fu')
            ->fields('fu')
            ->condition('fid', $file->id())
            ->execute()
            ->fetchAll();
          
          if (empty($usage)) {
            // Transform URI from public://path to sites/default/files/path
            $uri = $file->getFileUri();
            $transformed_uri = str_replace('public://', 'sites/default/files/', $uri);
            $real_path = $file_system->realpath($uri);
            
            // Check if file exists on remote server
            $remote_url = rtrim($assets_site_url, '/') . '/' . $transformed_uri;
            $file_exists_on_remote = FALSE;
            
            try {
              $response = $client->head($remote_url, [
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
            
            // If not dry run, delete the file entity and optionally the physical file
            if (!$options['dry-run']) {
              try {
                // Check if physical file exists before attempting to delete
                $file_exists = file_exists($real_path);
                
                // Delete physical file if it exists and we're not in db-only mode
                if ($file_exists && !$options['db-only']) {
                  if ($file_system->delete($uri)) {
                    $file_deleted_count++;
                    $this->logger()->notice(dt('Deleted physical file: @path', [
                      '@path' => $real_path,
                    ]));
                  } else {
                    $this->logger()->warning(dt('Failed to delete physical file: @path', [
                      '@path' => $real_path,
                    ]));
                  }
                }
                
                // Delete the file entity from database
                $file->delete();
                $deleted_count++;
                
                $this->logger()->notice(dt('Deleted unused file entity: @uri', [
                  '@uri' => $uri,
                ]));
              }
              catch (\Exception $e) {
                $error_count++;
                $this->logger()->error(dt('Error deleting file @fid: @error', [
                  '@fid' => $file->id(),
                  '@error' => $e->getMessage(),
                ]));
              }
            }
          }
        }
        
        // Save progress after each batch
        $state->set('assets_metadata.delete_unused.last_batch', $i + 1);
        $state->set('assets_metadata.delete_unused.unused_files', $unused_files);
        $state->set('assets_metadata.delete_unused.deleted_count', $deleted_count);
        $state->set('assets_metadata.delete_unused.error_count', $error_count);
        $state->set('assets_metadata.delete_unused.file_deleted_count', $file_deleted_count);
        $state->set('assets_metadata.delete_unused.processed_fids', $processed_fids);
        
        // Free up memory
        $files = null;
        gc_collect_cycles();
      }
      
      // Generate summary
      $this->logger()->success(dt('Completed checking files.', []));
      
      $this->output()->writeln(sprintf("\nSummary:"));
      $this->output()->writeln(sprintf("- Total files checked: %d", count($processed_fids)));
      $this->output()->writeln(sprintf("- Unused files found: %d", count($unused_files)));
      $this->output()->writeln(sprintf("- Assets site URL used: %s", $assets_site_url));
      
      if (!$options['dry-run']) {
        $this->output()->writeln(sprintf("- File entities successfully deleted: %d", $deleted_count));
        if ($options['db-only']) {
          $this->output()->writeln("- Physical files were preserved (--db-only option)");
        } else {
          $this->output()->writeln(sprintf("- Physical files successfully deleted: %d", $file_deleted_count));
        }
        $this->output()->writeln(sprintf("- Errors during deletion: %d", $error_count));
      } else {
        $this->output()->writeln("- Dry run mode: No files were deleted");
      }
      
      // If in dry-run mode, display the list of unused files
      if ($options['dry-run'] && !empty($unused_files)) {
        $this->output()->writeln("\nUnused files:");
        $table = new Table($this->output());
        $table->setHeaders(['FID', 'Filename', 'URI', 'Size', 'Changed', 'Exists on Remote']);
        
        $rows = [];
        foreach ($unused_files as $item) {
          $rows[] = [
            $item['fid'],
            $item['filename'],
            $item['uri'],
            format_size($item['size']),
            date('Y-m-d H:i:s', $item['changed']),
            $item['exists_on_remote'] ? 'Yes' : 'No',
          ];
        }
        
        $table->setRows($rows);
        $table->render();
      }
      
      // Clear progress data if completed successfully
      if ($i >= $total_batches) {
        $state->delete('assets_metadata.delete_unused.last_batch');
        $state->delete('assets_metadata.delete_unused.processed_fids');
        // Keep the unused_files, deleted_count, and error_count for reference
      }
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

  }

}
