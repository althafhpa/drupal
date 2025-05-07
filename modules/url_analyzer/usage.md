# URL Analyzer Usage Guide

## Accessing the Interface

Navigate to `Admin → Configuration → URL Analyzer` or visit `/admin/config/url-analyzer`

## Exporting URLs

1. Visit the URL Analyzer page
2. Click either:
  - "Export JSON" for structured data export
  - "Export CSV" for spreadsheet-compatible format

## Permissions

The module requires the 'administer site configuration' permission.

## Technical Details

Theme Integration
Custom template: url-analyzer-list.html.twig
CSS: Located in templates/css/url-analyzer.css
JS: Located in templates/js/url-analyzer.js

### Service Integration

The module provides a service `url_analyzer.url_collector` that can be used in custom code:

```php
$url_collector = \Drupal::service('url_analyzer.url_collector');
$urls = $url_collector->collectUrls();
