# Assets Metadata

A Drupal module for managing and validating file metadata, including external file checks.

## Features

- File metadata collection and validation
- External file accessibility checks
- File usage tracking
- Configurable file hashing
- Domain-based access controls
- Batch processing support

## Installation

1. Enable the module:
```bash
ddev drush en assets_metadata -y
```

2. Configure settings at `/admin/config/media/assets-metadata`

## Configuration

Visit `/admin/config/media/assets-metadata` to configure:

### File Processing
- **File Hash Generation**: Enable SHA256 hash calculation for files
- **Access Time Tracking**: Track when files were last accessed
- **File Usage Tracking**: Monitor how files are used across the site

### External File Validation
- **External File Checking**: Enable validation of external files
- **Domain Patterns**:
  - Allow Pattern: Whitelist specific domains (e.g., `*.example.com`)
  - Deny Pattern: Blacklist specific domains
- **Timeout Settings**: Configure connection timeouts
- **SSL Verification**: Enable/disable SSL certificate validation
- **Authentication**: Configure basic auth for external requests

## Metadata Collection

The module provides two modes for collecting metadata:

### Basic Mode (Default)
Fast collection of essential metadata:
```bash
ddev drush assets-meta:refresh
```
Collects:
- File ID and filename
- URI and public URL
- MIME type and size
- Created/modified timestamps
- Basic file status

### Advanced Mode
Comprehensive metadata collection based on admin settings:
```bash
ddev drush assets-meta:refresh --advanced
```
Additional data (if enabled in settings):
- SHA256 file hashes
- Last access timestamps
- File usage information
- External file validation
- Relationship tracking

### Targeting Specific Files
Process specific files by their IDs:
```bash
ddev drush assets-meta:refresh --fids=1,2,3
```

## JSON Structure

### Basic Mode
```json
{
  "metadata": {
    "version": "1.0",
    "generated": "<timestamp>",
    "source": {
      "type": "drupal",
      "version": "<version>"
    },
    "files": [
      {
        "id": "<file_id>",
        "type": "file",
        "attributes": {
          "filename": "<name>",
          "uri": "<uri>",
          "url": "<public_url>",
          "mime_type": "<mime>",
          "size": "<bytes>",
          "timestamps": {
            "created": "<timestamp>",
            "modified": "<timestamp>"
          },
          "status": {
            "exists": true|false,
            "error": "<error_if_any>"
          }
        },
        "meta": {
          "permanent": true|false,
          "managed": true,
          "scheme": "<storage_scheme>"
        }
      }
    ]
  }
}
```

### Advanced Mode
Adds the following fields (if enabled):
```json
{
  "attributes": {
    "hash": {
      "algorithm": "sha256",
      "value": "<hash>"
    },
    "timestamps": {
      "accessed": "<timestamp>"
    }
  },
  "relationships": {
    "source": {
      "type": "<source_type>",
      "usage": "<usage_data>"
    },
    "references": "<reference_data>"
  }
}
```

## Performance Considerations

- Basic mode is optimized for speed and minimal resource usage
- Advanced mode performs additional checks and may take longer
- Use `--fids` option to process specific files when possible
- Batch size is set to 50 files per batch for optimal performance
- Memory usage warnings appear at 60% consumption

## Troubleshooting

### Common Issues

1. Memory Warnings
   - Increase PHP memory limit in settings.php
   - Process fewer files at a time using `--fids`

2. External File Timeouts
   - Adjust timeout settings in admin configuration
   - Check domain patterns and SSL settings

3. Missing Metadata
   - Verify admin settings for desired metadata types
   - Check file permissions and accessibility

### Logging

- Check recent log messages:
```bash
ddev drush watchdog:show --type=assets_metadata
```

## Development

### Adding New Metadata Types

1. Add configuration in `assets_metadata.settings.yml`
2. Update the settings form in `AssetsMetadataForm.php`
3. Add processing logic in `FileMetadataManager.php`
4. Update schema in `config/schema/assets_metadata.schema.yml`

### Testing

Run tests:
```bash
ddev exec ../vendor/bin/phpunit web/modules/custom/assets_metadata
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

## License

This project is licensed under the GPL v2 or later.

## Maintainers

- Previous Next Team
