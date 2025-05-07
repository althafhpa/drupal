# Assets Metadata Drush Commands

## Refresh Assets Metadata
Collects and refreshes metadata for all files in the system.

```bash
drush assets-meta:refresh
```

### Options:
- `--force`: Force a fresh start, ignoring any existing progress
- `--resume`: Resume from the last processed batch
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)
- `--start-date=<date>`: Start date in YYYY-MM-DD format (filter by changed date)
- `--end-date=<date>`: End date in YYYY-MM-DD format (filter by changed date)
- `--skip-file-check`: Skip checking if files exist on disk

### Examples:
```bash
# Start fresh
drush assets-meta:refresh --force

# Resume from where it left off
drush assets-meta:refresh --resume

# Process in larger batches
drush assets-meta:refresh --batch-size=100

# Process files changed between specific dates
drush assets-meta:refresh --start-date=2023-01-01 --end-date=2023-03-31

# Skip checking if files exist on disk (faster)
drush assets-meta:refresh --skip-file-check
```

## Check Remote Files
Checks if files exist on a remote server and generates metadata with transformed URIs.

```bash
drush assets-meta:check-remote
```

### Options:
- `--base-url=<url>`: Base URL for remote assets (defaults to current site URL)
- `--output=<filename>`: Output file path for the metadata JSON (default: assets-remote-missing.json)
- `--timeout=<seconds>`: Connection timeout in seconds (default: 10)
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)
- `--missing-only`: Save only missing files to the metadata file
- `--start-batch=<number>`: Batch number to start from (for resuming)
- `--force`: Force starting from the beginning, ignoring saved progress
- `--start-date=<date>`: Start date in YYYY-MM-DD format (filter by changed date)
- `--end-date=<date>`: End date in YYYY-MM-DD format (filter by changed date)

### Examples:
```bash
# Check files with default settings
drush assets-meta:check-remote

# Check files and only save missing ones
drush assets-meta:check-remote --missing-only

# Use a different remote URL
drush assets-meta:check-remote --base-url=https://example.com/assets

# Check files changed between specific dates
drush assets-meta:check-remote --start-date=2023-01-01 --end-date=2023-12-31

# Resume from where it left off
drush assets-meta:check-remote --start-batch=5
```

## Delete Unused Files
Finds and optionally deletes unused file entities.

```bash
drush assets-meta:delete-unused
```

### Options:
- `--dry-run`: Only report unused files without deleting them
- `--batch-size=<size>`: Number of files to process in each batch (default: 50)
- `--age-days=<days>`: Only consider files older than this many days
- `--resume`: Resume from the last processed batch
- `--force`: Force starting from the beginning, ignoring saved progress
- `--db-only`: Delete only from database, preserve physical files
- `--assets-site-url=<url>`: Base URL for assets site (defaults to current site URL)

### Examples:
```bash
# Find unused files without deleting (safe mode)
drush assets-meta:delete-unused --dry-run

# Delete unused files older than 30 days
drush assets-meta:delete-unused --age-days=30

# Delete only database entries, preserve physical files
drush assets-meta:delete-unused --db-only

# Check file existence against a specific assets site URL
drush assets-meta:delete-unused --assets-site-url=https://example.com

# Resume processing from where it stopped previously
drush assets-meta:delete-unused --resume
```
