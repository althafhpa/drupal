<?php

namespace Drupal\assets_metadata\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;

/**
 * Form for downloading assets metadata within a date range.
 */
class AssetsMetadataDownloadForm extends FormBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The extension path resolver service.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->fileSystem = $container->get('file_system');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->extensionPathResolver = $container->get('extension.path.resolver');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->database = $container->get('database');
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'assets_metadata_download_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Use this form to download assets metadata for a specific date range. The date range cannot exceed 90 days.') . '</p>',
    ];

    $form['date_range'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Date Range'),
    ];

    $form['date_range']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#required' => TRUE,
      '#default_value' => date('Y-m-d', strtotime('-7 days')),
    ];

    $form['date_range']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#required' => TRUE,
      '#default_value' => date('Y-m-d'),
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#description' => $this->t('Number of files to process in each batch.'),
      '#default_value' => 50,
      '#min' => 10,
      '#max' => 200,
      '#required' => TRUE,
    ];

    // Add assets site URL field
    $form['assets_site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Assets Site URL'),
      '#description' => $this->t('Optional. The base URL for assets. Leave empty to use the current site URL.'),
      '#default_value' => '',
      '#placeholder' => $this->getRequest()->getSchemeAndHttpHost(),
    ];

    // Add option for deleting unused files
    $form['delete_options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Delete Unused Files'),
    ];

    $form['delete_options']['delete_unused'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete unused files'),
      '#description' => $this->t('Delete unused file entities and physical files before generating metadata. Note: If assets site URL is different from current site, only file entities will be deleted and physical files will be preserved.'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download Metadata'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $start_date = new DrupalDateTime($form_state->getValue('start_date'));
    $end_date = new DrupalDateTime($form_state->getValue('end_date'));

    // Ensure start date is before end date
    if ($start_date > $end_date) {
      $form_state->setErrorByName('start_date', $this->t('Start date must be before end date.'));
    }

    // Calculate the difference in days
    $interval = $start_date->diff($end_date);
    $days = $interval->days;

    // Ensure the date range doesn't exceed 90 days (changed from 30 to match API)
    if ($days > 90) {
      $form_state->setErrorByName('end_date', $this->t('Date range cannot exceed 90 days. Current range: @days days.', ['@days' => $days]));
    }

    // Validate assets site URL if provided
    $assets_site_url = $form_state->getValue('assets_site_url');
    if (!empty($assets_site_url) && !filter_var($assets_site_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('assets_site_url', $this->t('Please enter a valid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    $batch_size = $form_state->getValue('batch_size');
    $delete_unused = $form_state->getValue('delete_unused');
    $assets_site_url = $form_state->getValue('assets_site_url');

    // If assets site URL is empty, use current site URL
    $current_site_url = $this->getRequest()->getSchemeAndHttpHost();
    if (empty($assets_site_url)) {
      $assets_site_url = $current_site_url;
    }

    // Determine if we should preserve physical files
    $db_only = FALSE;
    if ($delete_unused && $assets_site_url !== $current_site_url) {
      $db_only = TRUE;
      $this->messenger()->addWarning($this->t('Assets site URL differs from current site. Only file entities will be deleted, physical files will be preserved.'));
    }

    // Prepare the batch
    $this->prepareBatch($start_date, $end_date, $batch_size, $delete_unused, $db_only, $assets_site_url);
  }

  /**
   * Prepares the batch process for metadata collection.
   *
   * @param string $start_date
   *   The start date in Y-m-d format.
   * @param string $end_date
   *   The end date in Y-m-d format.
   * @param int $batch_size
   *   The batch size.
   * @param bool $delete_unused
   *   Whether to delete unused files.
   * @param bool $db_only
   *   Whether to delete only from database and preserve physical files.
   * @param string $assets_site_url
   *   The base URL for assets.
   */
  protected function prepareBatch($start_date, $end_date, $batch_size, $delete_unused = FALSE, $db_only = FALSE, $assets_site_url = NULL) {
    // Convert dates to timestamps
    $start_timestamp = strtotime($start_date . ' 00:00:00');
    $end_timestamp = strtotime($end_date . ' 23:59:59');

    // Get file IDs within the date range - using changed date instead of created
    $query = $this->entityTypeManager->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('changed', [$start_timestamp, $end_timestamp], 'BETWEEN')
      ->sort('changed', 'DESC');
    
    $fids = $query->execute();

    if (empty($fids)) {
      $this->messenger()->addWarning($this->t('No files found in the selected date range.'));
      return;
    }

    // Create a unique filename for this export
    $timestamp = date('Ymd_His');
    $filename = "assets_metadata_{$start_date}_to_{$end_date}_{$timestamp}.json";
    $directory = 'public://assets_metadata';
    
    // Ensure the directory exists
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    
    $file_path = $directory . '/' . $filename;

    // Initialize metadata array
    $metadata = [];

    // Set up the batch
    $operations = [];

    // Add delete unused files operation if requested
    if ($delete_unused) {
      $operations[] = [
        [$this, 'deleteUnusedFilesBatch'],
        [$fids, $batch_size, $db_only],
      ];
    }

    // Add file processing operations
    $chunks = array_chunk($fids, $batch_size);
    
    foreach ($chunks as $chunk) {
      $operations[] = [
        [$this, 'processFilesBatch'],
        [$chunk, $file_path, $assets_site_url],
      ];
    }

    $batch = [
      'title' => $this->t('Processing Assets Metadata'),
      'operations' => $operations,
      'finished' => [$this, 'batchFinished'],
      'init_message' => $this->t('Starting metadata collection...'),
      'progress_message' => $this->t('Processed @current out of @total batches.'),
      'error_message' => $this->t('An error occurred during processing.'),
      'file' => $this->extensionPathResolver->getPath('module', 'assets_metadata') . '/src/Form/AssetsMetadataDownloadForm.php',
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback for deleting unused files.
   *
   * @param array $fids
   *   Array of file IDs to check.
   * @param int $batch_size
   *   Batch size for processing.
   * @param bool $db_only
   *   Whether to delete only from database and preserve physical files.
   * @param array $context
   *   Batch context.
   */
  public function deleteUnusedFilesBatch(array $fids, $batch_size, $db_only, &$context) {
    // Initialize results if this is the first run
    if (!isset($context['results']['deletion'])) {
      $context['results']['deletion'] = [
        'unused_files_count' => 0,
        'entities_deleted' => 0,
        'physical_files_deleted' => 0,
        'errors' => 0,
      ];
    }

    // Get the file storage
    $file_storage = $this->entityTypeManager->getStorage('file');
    
    // Process a subset of files in this batch operation
    $current_batch = !empty($context['sandbox']['current_batch']) ? $context['sandbox']['current_batch'] : 0;
    $total_batches = ceil(count($fids) / $batch_size);
    
    // Initialize progress information
    if (empty($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_batch'] = 0;
      $context['sandbox']['max'] = count($fids);
    }
    
    // Get the current batch of file IDs
    $current_fids = array_slice($fids, $current_batch * $batch_size, $batch_size);
    
    // Load the files
    $files = $file_storage->loadMultiple($current_fids);
    
    foreach ($files as $file) {
      // Check if file is used in any entity
      $usage = $this->database->select('file_usage', 'fu')
        ->fields('fu')
        ->condition('fid', $file->id())
        ->execute()
        ->fetchAll();
      
      if (empty($usage)) {
        // File is unused, delete it
        try {
          $uri = $file->getFileUri();
          $real_path = $this->fileSystem->realpath($uri);
          
          // Check if physical file exists before attempting to delete
          $file_exists = file_exists($real_path);
          
          // Delete physical file if it exists and we're not in db-only mode
          if ($file_exists && !$db_only) {
            if ($this->fileSystem->delete($uri)) {
              $context['results']['deletion']['physical_files_deleted']++;
            }
          }
          
          // Delete the file entity from database
          $file->delete();
          $context['results']['deletion']['entities_deleted']++;
          $context['results']['deletion']['unused_files_count']++;
          
        }
        catch (\Exception $e) {
          $context['results']['deletion']['errors']++;
        }
      }
      
      // Update progress
      $context['sandbox']['progress']++;
    }
    
    // Update batch information
    $context['sandbox']['current_batch']++;
    
    // Determine if we're finished
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    
    // Update message
    $context['message'] = $this->t('Deleted @count unused files out of @total processed...', [
      '@count' => $context['results']['deletion']['entities_deleted'],
      '@total' => $context['sandbox']['progress'],
    ]);
  }

    /**
   * Batch operation callback for processing files.
   *
   * @param array $fids
   *   Array of file IDs to process.
   * @param string $file_path
   *   Path where the metadata file will be saved.
   * @param string $assets_site_url
   *   The base URL for assets.
   * @param array $context
   *   Batch context.
   */
  public function processFilesBatch(array $fids, $file_path, $assets_site_url, &$context) {
    // Initialize results array if this is the first run
    if (!isset($context['results']['metadata'])) {
      $context['results']['metadata'] = [];
      $context['results']['file_path'] = $file_path;
      $context['results']['processed'] = 0;
      $context['results']['duplicate_files'] = [];
      $context['results']['assets_site_url'] = $assets_site_url;
    }

    // Load the files
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($fids);
    
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
        'drupal_url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
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
      foreach ($context['results']['metadata'] as $existing) {
        if ($existing['path'] === $transformed_uri) {
          $duplicate = TRUE;
          $context['results']['duplicate_files'][] = $file_metadata;
          break;
        }
      }
      
      if (!$duplicate) {
        $context['results']['metadata'][] = $file_metadata;
      }
      
      $context['results']['processed']++;
    }
    
    // Update progress
    $context['message'] = $this->t('Processed @count files...', ['@count' => $context['results']['processed']]);
    
    // Save progress to file
    file_put_contents($file_path, json_encode($context['results']['metadata'], JSON_PRETTY_PRINT));
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   Batch results.
   * @param array $operations
   *   Batch operations.
   */
  public function batchFinished($success, $results, $operations) {
    if ($success) {
      $count = count($results['metadata']);
      $duplicate_count = isset($results['duplicate_files']) ? count($results['duplicate_files']) : 0;
      
      // Create a file entity for the metadata file
      $file_uri = $results['file_path'];
      $file = $this->entityTypeManager->getStorage('file')->create([
        'uri' => $file_uri,
        'uid' => $this->currentUser()->id(),
        'status' => FileInterface::STATUS_PERMANENT,
      ]);
      $file->save();
      
      // Create a link to the file
      $url = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
      $link = Link::fromTextAndUrl($this->t('Download Metadata File'), Url::fromUri($url))->toString();
      
      // Display deletion results if applicable
      if (isset($results['deletion'])) {
        $this->messenger()->addStatus($this->t('Deleted @count unused file entities with @physical physical files deleted and @errors errors.', [
          '@count' => $results['deletion']['entities_deleted'],
          '@physical' => $results['deletion']['physical_files_deleted'],
          '@errors' => $results['deletion']['errors'],
        ]));
      }
      
      $this->messenger()->addStatus($this->t('Successfully processed @count files with @duplicates duplicates.', [
        '@count' => $count,
        '@duplicates' => $duplicate_count,
      ]));
      
      $this->messenger()->addStatus($this->t('Metadata file is ready: @link', [
        '@link' => $link,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('An error occurred while processing the files.'));
    }
  }
  
}