<?php

namespace Drupal\scanner_extended\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Finder\Finder;

/**
 * Drush commands for the Scanner Extended module.
 */
class ScannerExtendedCommands extends DrushCommands {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new ScannerExtendedCommands object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   */
  public function __construct(
    Connection $database,
    FileSystemInterface $file_system,
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    DateFormatterInterface $date_formatter
  ) {
    $this->database = $database;
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
    $this->themeHandler = $theme_handler;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Search for a pattern in code files and database.
   *
   * @param string $pattern
   *   The search pattern.
   * @param array $options
   *   An associative array of options.
   *
   * @option file-extensions
   *   Comma-separated list of file extensions to search in.
   * @option directories
   *   Comma-separated list of directories to search in.
   * @option tables
   *   Comma-separated list of database tables to search in.
   * @option exclude
   *   Comma-separated list of directories to exclude.
   * @option case-sensitive
   *   Whether the search should be case-sensitive.
   * @option regex
   *   Whether the pattern is a regular expression.
   *
   * @command scanner:search
   * @aliases scan-search
   * @usage drush scanner:search "pattern" --file-extensions=php,js,twig
   *   Search for "pattern" in PHP, JS, and Twig files.
   */
  public function search($pattern, array $options = [
    'file-extensions' => 'php,js,twig,yml,json,css',
    'directories' => 'modules,themes,profiles',
    'tables' => '',
    'exclude' => 'vendor,node_modules',
    'case-sensitive' => false,
    'regex' => false,
  ]) {
    $this->output()->writeln("<info>Searching for '{$pattern}'...</info>");
    
    $results = [];
    $start_time = microtime(true);
    
    // Search in files
    $file_results = $this->searchFiles($pattern, $options);
    $results['files'] = $file_results;
    
    // Search in database
    if (!empty($options['tables'])) {
      $db_results = $this->searchDatabase($pattern, $options);
      $results['database'] = $db_results;
    }
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    // Generate report
    $report_path = $this->generateReport($pattern, $results, $execution_time);
    
    // Output summary
    $this->outputSummary($results, $execution_time, $report_path);
  }
  
  /**
   * Search for pattern in files.
   */
  protected function searchFiles($pattern, array $options) {
    $results = [];
    $extensions = explode(',', $options['file-extensions']);
    $directories = explode(',', $options['directories']);
    $exclude = explode(',', $options['exclude']);
    
    $finder = new Finder();
    $finder->files();
    
    // Add extensions
    foreach ($extensions as $ext) {
      $finder->name("*.$ext");
    }
    
    // Add directories to search
    $drupal_root = DRUPAL_ROOT;
    foreach ($directories as $directory) {
      if (is_dir("$drupal_root/$directory")) {
        $finder->in("$drupal_root/$directory");
      }
    }
    
    // Add custom modules and themes
    if (is_dir("$drupal_root/sites/all")) {
      $finder->in("$drupal_root/sites/all");
    }
    
    // Add site-specific directories
    $finder->in("$drupal_root/sites/default");
    
    // Exclude directories
    foreach ($exclude as $dir) {
      $finder->notPath($dir);
    }
    
    // Set case sensitivity
    $case_sensitive = $options['case-sensitive'];
    
    // Search in files
    $count = 0;
    foreach ($finder as $file) {
      $content = $file->getContents();
      $relative_path = str_replace($drupal_root . '/', '', $file->getRealPath());
      
      if ($options['regex']) {
        $flags = $case_sensitive ? '' : 'i';
        if (preg_match_all("/$pattern/$flags", $content, $matches, PREG_OFFSET_CAPTURE)) {
          foreach ($matches[0] as $match) {
            $line_number = $this->getLineNumber($content, $match[1]);
            $results[] = [
              'file' => $relative_path,
              'line' => $line_number,
              'match' => $match[0],
              'context' => $this->getContext($content, $match[1]),
            ];
            $count++;
          }
        }
      }
      else {
        $search_function = $case_sensitive ? 'strpos' : 'stripos';
        $pos = 0;
        while (($pos = $search_function($content, $pattern, $pos)) !== false) {
          $line_number = $this->getLineNumber($content, $pos);
          $results[] = [
            'file' => $relative_path,
            'line' => $line_number,
            'match' => substr($content, $pos, strlen($pattern)),
            'context' => $this->getContext($content, $pos),
          ];
          $pos += strlen($pattern);
          $count++;
        }
      }
    }
    
    $this->output()->writeln("<info>Found $count matches in files.</info>");
    return $results;
  }
  
  /**
   * Search for pattern in database.
   */
  protected function searchDatabase($pattern, array $options) {
    $results = [];
    $tables = explode(',', $options['tables']);
    $case_sensitive = $options['case-sensitive'];
    $count = 0;
    
    foreach ($tables as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        $this->output()->writeln("<warning>Table '$table' does not exist.</warning>");
        continue;
      }
      
      // Get all columns from the table
      $columns = $this->database->schema()->getTableInfo($table)->getFields();
      $text_columns = [];
      
      // Filter for text/varchar columns
      foreach ($columns as $column => $spec) {
        if (in_array($spec['type'], ['varchar', 'text', 'char', 'string', 'longtext'])) {
          $text_columns[] = $column;
        }
      }
      
      if (empty($text_columns)) {
        continue;
      }
      
      // Build query for each text column
      foreach ($text_columns as $column) {
        $query = $this->database->select($table, 't');
        $query->fields('t');
        
        if ($options['regex']) {
          // Use REGEXP for MySQL/MariaDB
          $query->condition($column, $pattern, 'REGEXP');
        }
        else {
          if ($case_sensitive) {
            $query->condition($column, '%' . $this->database->escapeLike($pattern) . '%', 'LIKE');
          }
          else {
            // LOWER() for case-insensitive search
            $query->where("LOWER($column) LIKE LOWER(:pattern)", [':pattern' => '%' . $this->database->escapeLike($pattern) . '%']);
          }
        }
        
        $records = $query->execute()->fetchAll();
        
        foreach ($records as $record) {
          $results[] = [
            'table' => $table,
            'column' => $column,
            'id' => isset($record->id) ? $record->id : (isset($record->nid) ? $record->nid : ''),
            'value' => $record->$column,
          ];
          $count++;
        }
      }
    }
    
    $this->output()->writeln("<info>Found $count matches in database.</info>");
    return $results;
  }
  
  /**
   * Generate HTML report and save it to files directory.
   */
  protected function generateReport($pattern, array $results, $execution_time) {
    $timestamp = $this->dateFormatter->format(time(), 'custom', 'Y-m-d_H-i-s');
    $filename = "scanner_report_{$timestamp}.html";
    $directory = 'public://scanner_reports';
    
    // Create directory if it doesn't exist
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    
    // Build HTML report
    $html = $this->buildReportHtml($pattern, $results, $execution_time);
    
    // Save report
    $uri = $directory . '/' . $filename;
    $this->fileSystem->saveData($html, $uri, FileSystemInterface::EXISTS_REPLACE);
    
    return $this->fileSystem->realpath($uri);
  }
  
  /**
   * Build HTML report.
   */
  protected function buildReportHtml($pattern, array $results, $execution_time) {
    $file_count = count($results['files'] ?? []);
    $db_count = count($results['database'] ?? []);
    $total_count = $file_count + $db_count;
    
    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Scanner Report: ' . htmlspecialchars($pattern) . '</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1, h2 { color: #333; }
    .summary { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    .highlight { background-color: #ffff99; }
    pre { background: #f8f8f8; padding: 10px; border-radius: 3px; overflow: auto; }
  </style>
</head>
<body>
  <h1>Scanner Report</h1>
  
  <div class="summary">
    <p><strong>Search Pattern:</strong> ' . htmlspecialchars($pattern) . '</p>
    <p><strong>Date:</strong> ' . $this->dateFormatter->format(time(), 'medium') . '</p>
    <p><strong>Total Matches:</strong> ' . $total_count . ' (' . $file_count . ' in files, ' . $db_count . ' in database)</p>
    <p><strong>Execution Time:</strong> ' . $execution_time . ' seconds</p>
  </div>';
  
    // File results
    if (!empty($results['files'])) {
      $html .= '<h2>File Matches</h2>
  <table>
    <tr>
      <th>File</th>
      <th>Line</th>
      <th>Context</th>
    </tr>';
      
      foreach ($results['files'] as $result) {
        $html .= '<tr>
      <td>' . htmlspecialchars($result['file']) . '</td>
      <td>' . $result['line'] . '</td>
      <td><pre>' . $this->highlightPattern($result['context'], $pattern) . '</pre></td>
    </tr>';
      }
      
      $html .= '</table>';
    }
    
    // Database results
    if (!empty($results['database'])) {
      $html .= '<h2>Database Matches</h2>
  <table>
    <tr>
      <th>Table</th>
      <th>Column</th>
      <th>ID</th>
      <th>Value</th>
    </tr>';
      
      foreach ($results['database'] as $result) {
        $html .= '<tr>
            <td>' . htmlspecialchars($result['table']) . '</td>
      <td>' . htmlspecialchars($result['column']) . '</td>
      <td>' . htmlspecialchars($result['id']) . '</td>
      <td>' . $this->highlightPattern(htmlspecialchars($result['value']), $pattern) . '</td>
    </tr>';
      }
      
      $html .= '</table>';
    }
    
    $html .= '</body>
</html>';
    
    return $html;
  }
  
  /**
   * Output summary to console.
   */
  protected function outputSummary(array $results, $execution_time, $report_path) {
    $file_count = count($results['files'] ?? []);
    $db_count = count($results['database'] ?? []);
    $total_count = $file_count + $db_count;
    
    $this->output()->writeln("");
    $this->output()->writeln("<info>Scan Summary</info>");
    $this->output()->writeln("-------------");
    $this->output()->writeln("Total matches: $total_count");
    $this->output()->writeln("File matches: $file_count");
    $this->output()->writeln("Database matches: $db_count");
    $this->output()->writeln("Execution time: $execution_time seconds");
    $this->output()->writeln("");
    $this->output()->writeln("Report saved to: $report_path");
  }
  
  /**
   * Get line number for a position in text.
   */
  protected function getLineNumber($content, $position) {
    $lines = explode("\n", substr($content, 0, $position));
    return count($lines);
  }
  
  /**
   * Get context around a match.
   */
  protected function getContext($content, $position, $context_length = 100) {
    $start = max(0, $position - $context_length);
    $length = min(strlen($content) - $start, $context_length * 2);
    $context = substr($content, $start, $length);
    
    // Trim to complete lines
    if ($start > 0) {
      $context = preg_replace('/^[^\n]*/', '', $context);
    }
    if ($start + $length < strlen($content)) {
      $context = preg_replace('/[^\n]*$/', '', $context);
    }
    
    return trim($context);
  }
  
  /**
   * Highlight the pattern in text.
   */
  protected function highlightPattern($text, $pattern) {
    if (empty($pattern)) {
      return $text;
    }
    
    // Escape the pattern for use in regex
    $escaped_pattern = preg_quote($pattern, '/');
    
    // Replace with highlighted version
    return preg_replace('/(' . $escaped_pattern . ')/i', '<span class="highlight">$1</span>', $text);
  }

}