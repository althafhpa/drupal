# Example commands for assets metadata delete unused
ddev drush assets-meta:delete-unused --dry-run --assets-site-url=https://example.com --start-date=2023-01-01 --end-date=2023-12-31

# Example commands for assets metadata download API
/api/assets-metadata?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&delete-unused&assets-site-url=https://example.com

# Assets metadata download Form
/admin/content/assets-metadata/download
