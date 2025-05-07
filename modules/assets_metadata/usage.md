# Assets Metadata Module Usage

## FORM
/admin/content/assets-metadata/download

Maximum date range is 90 days.

## API

### Get metadata for the last 7 days (default)
GET /api/assets-metadata

### Get metadata for a specific date range
GET /api/assets-metadata?start_date=2023-01-01&end_date=2023-03-31

Maximum date range is 90 days.

### Get metadata for a single day
GET /api/assets-metadata?start_date=2023-01-15&end_date=2023-01-15

## DRUSH COMMANDS

### Refresh Assets Metadata
Collects and refreshes metadata for all files in the system.

```bash
drush assets-meta:refresh
```

Options:
- `--force`: Force a fresh start, ignoring any existing progress
- `--resume`: Resume from the last processed batch
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)

Examples:
```bash
# Start fresh
drush assets-meta:refresh --force

# Resume from where it left off
drush assets-meta:refresh --resume

# Process in larger batches
drush assets-meta:refresh --batch-size=100
```

### Check Remote Files
Checks if files exist on a remote server and generates metadata with transformed URIs.

```bash
drush assets-meta:check-remote
```

Options:
- `--base-url=<url>`: Base URL for remote assets (default: https://myremotesite.com/globalassets)
- `--output=<filename>`: Output file path for the metadata JSON (default: assets-remote-missing.json)
- `--timeout=<seconds>`: Connection timeout in seconds (default: 10)
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)
- `--missing-only`: Save only missing files to the metadata file
- `--start-batch=<number>`: Batch number to start from (for resuming)
- `--force`: Force starting from the beginning, ignoring saved progress

Examples:
```bash
# Check files with default settings
drush assets-meta:check-remote

# Check files and only save missing ones
drush assets-meta:check-remote --missing-only

# Use a different remote URL
drush assets-meta:check-remote --base-url=https://example.com/assets
```

### Check Changes
Checks for file changes between source and destination.

```bash
drush assets-metadata:check-changes /path/to/destination
```

### Show Differences
Shows changes between current and previous state.

```bash
drush assets-metadata:diff
```

### Delete Unused Files
Finds and optionally deletes unused file entities.

```bash
drush assets-meta:delete-unused
```

Options:
- `--dry-run`: Only report unused files without deleting them
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)
- `--age-days=<days>`: Only consider files older than this many days
- `--resume`: Resume from the last processed batch
- `--force`: Force starting from the beginning, ignoring saved progress

Examples:
```bash
# Find unused files without deleting (safe mode)
drush assets-meta:delete-unused --dry-run

# Delete unused files older than 30 days
drush assets-meta:delete-unused --age-days=30
```

### Test External File
Test external file checking.

```bash
drush assets-meta:test-external https://example.com/file.pdf
```

Options:
- `--check-method=<method>`: Method to use (head/partial/full) (default: head)
- `--timeout=<seconds>`: Connection timeout in seconds (default: 10)
- `--verify-ssl`: Whether to verify SSL certificates (default: TRUE)

Examples:
```bash
# Test with partial download method
drush assets-meta:test-external https://example.com/file.jpg --check-method=partial

# Test with longer timeout
drush assets-meta:test-external https://example.com/large.zip --timeout=30
```
