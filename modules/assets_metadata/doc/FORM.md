# Assets Metadata Download Form

The Assets Metadata Download Form provides a user interface for downloading file metadata within a specified date range.

## Location

The form is available at:

```
/admin/content/assets-metadata/download
```

## Features

- Select a date range for files to include in the metadata
- Configure batch size for processing
- Specify a custom assets site URL (optional)
- Option to delete unused files before generating metadata

## Form Fields

### Date Range
- **Start Date**: Beginning of the date range (required)
- **End Date**: End of the date range (required)

### Batch Size
- Number of files to process in each batch (default: 50)

### Assets Site URL
- Base URL for assets (optional)
- If not specified, the current site URL will be used

### Delete Unused Files
- Option to delete unused file entities and physical files before generating metadata
- Note: If the assets site URL differs from the current site URL, only file entities will be deleted and physical files will be preserved

## Notes

- The maximum date range is 90 days
- Files are filtered by their 'changed' date, not 'created' date
- The form uses Drupal's batch API to process large numbers of files efficiently
- The metadata is saved as a JSON file that can be downloaded after processing
- The metadata includes file IDs, paths, URLs, and various file attributes
- When deleting unused files, the system automatically determines whether to preserve physical files based on the assets site URL
