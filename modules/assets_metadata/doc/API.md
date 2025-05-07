# Assets Metadata API

The Assets Metadata API provides access to file metadata within the Drupal system. It allows filtering by date range and includes options for deleting unused files.

## Endpoints

### Get Metadata

```
GET /api/assets-metadata
```

Retrieves metadata for files changed within a specified date range.

#### Query Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| start_date | Start date in YYYY-MM-DD format | 7 days ago |
| end_date | End date in YYYY-MM-DD format | Current date |
| delete-unused | Delete unused files before generating metadata | Not set |
| assets-site-url | Base URL for assets (used for generating asset URLs) | Current site URL |

#### Response Format

```json
{
  "metadata": [
    {
      "fid": 123,
      "filename": "example.jpg",
      "uri": "sites/default/files/example.jpg",
      "path": "sites/default/files/example.jpg",
      "real_path": "/var/www/html/sites/default/files/example.jpg",
      "drupal_url": "https://example.com/sites/default/files/example.jpg",
      "assets_url": "https://assets.example.com/sites/default/files/example.jpg",
      "metadata": {
        "filesize": 12345,
        "changed": 1672531200,
        "created": 1672444800,
        "mime": "image/jpeg",
        "md5": "a1b2c3d4e5f6g7h8i9j0"
      }
    }
  ],
  "count": 1,
  "duplicate_count": 0,
  "total_processed": 1,
  "start_date": "2023-01-01",
  "end_date": "2023-01-31",
  "assets_site_url": "https://assets.example.com"
}
```

When the `delete-unused` parameter is used, the response will include deletion results:

```json
{
  "deletion_results": {
    "unused_files_count": 5,
    "entities_deleted": 5,
    "physical_files_deleted": 3,
    "errors": 0,
    "db_only_mode": true,
    "assets_site_url": "https://assets.example.com",
    "unused_files": [
      {
        "fid": 456,
        "filename": "unused.jpg",
        "uri": "public://unused.jpg",
        "real_path": "/var/www/html/sites/default/files/unused.jpg",
        "size": 5678,
        "changed": 1672531200,
        "exists_on_remote": false,
        "remote_url": "https://assets.example.com/sites/default/files/unused.jpg"
      }
    ]
  }
}
```

#### Examples

##### Get metadata for the last 7 days (default)
```
GET /api/assets-metadata
```

##### Get metadata for a specific date range
```
GET /api/assets-metadata?start_date=2023-01-01&end_date=2023-03-31
```

##### Get metadata for a single day
```
GET /api/assets-metadata?start_date=2023-01-15&end_date=2023-01-15
```

##### Get metadata and delete unused files
```
GET /api/assets-metadata?delete-unused
```

##### Get metadata with a custom assets site URL
```
GET /api/assets-metadata?assets-site-url=https://assets.example.com
```

##### Get metadata, delete unused files, and use a custom assets site URL
```
GET /api/assets-metadata?delete-unused&assets-site-url=https://assets.example.com
```

#### Notes

- The date range cannot exceed 90 days
- Files are filtered by their 'changed' date, not 'created' date
- The API returns both unique files and information about duplicates found
- When `delete-unused` is used with a different `assets-site-url` than the current site, only database entries will be deleted and physical files will be preserved
- When `delete-unused` is used with the same `assets-site-url` as the current site, both database entries and physical files will be deleted
