# Drupal oEmbed

## Overview

The Drupal oEmbed module provides functionality to expose Drupal pages via oEmbed endpoints. This allows external applications to embed content from your Drupal site using the oEmbed protocol, a standard for embedding content from one website into another.

The module creates an endpoint that returns JSON responses in the oEmbed format, allowing your Drupal content to be embedded in other websites that support oEmbed consumers.

## Features

- Exposes Drupal pages as oEmbed resources
- Returns responses in the oEmbed "rich" format
- Handles URL redirects automatically
- Provides caching for improved performance
- Supports customization of embedded content
- Removes unnecessary elements like breadcrumbs and sidebars from embedded content
- Updates internal node links to their aliases

## Requirements

- Drupal 9.x or 10.x
- Path Alias module
- Metatag module (recommended)

## Installation

1. Download and place the module in your Drupal installation's modules directory.
2. Enable the module via the Drupal admin interface or using Drush:

```bash
drush en drupal_oembed
```

## Usage

### Basic Usage

The module provides an endpoint at `/drupal_oembed/content` that accepts the following parameters:

- `url`: The path to the Drupal content to be embedded (required)
- `sidebar`: Whether to include the sidebar in the embedded content (default: 1)

Example request:
```
https://example.com/drupal_oembed/content?url=/about-us
```

This will return a JSON response in the oEmbed format containing the HTML content of the `/about-us` page.

### Response Format

The response follows the oEmbed specification and includes:

- `type`: Always "rich" for this module
- `version`: oEmbed version (1.0)
- `title`: Page title
- `author_name`: Author of the content
- `provider_name`: Site name
- `width`: Default width of the embedded content
- `height`: Default height of the embedded content
- `html`: HTML content to be embedded
- `meta_tags`: Meta tags from the original page
- `template_columns`: Number of columns in the template (1 or 2 depending on sidebar)

## Customization

### Adapting for Different Sites

The module can be reused in other Drupal sites with different HTML structures by modifying the `ResourceProcessor` class. Here's how to adapt it:

1. **Identify Content Selectors**: The module currently looks for content using the data attribute `data-block="content-main"`. To use different selectors:

   Edit `modules/drupal_oembed/src/ResourceProcessor.php` and modify the `getMainContentHtml()` method to use your site's specific selectors:

   ```php
   // Example: Change the selector from data-block="content-main" to a different attribute
   $match = $xpath->query('//*[@data-region="content"]'); // Change to your selector
   ```

2. **Customize Element Removal**: If your site has different elements that should be removed:

   Edit the `removeBreadcrumbs()` and `removeSidebar()` methods to target your specific elements:

   ```php
   // Example: Change breadcrumb selector
   $breadcrumbs = $xpath->query($converter->toXPath('.your-breadcrumb-class'));
   
   // Example: Change sidebar selector
   $sidebarNav = $xpath->query($converter->toXPath('.your-sidebar-class'));
   ```

3. **Adjust Template Columns Logic**: If your site uses a different approach for layout:

   Modify the `getTemplateColumns()` method:

   ```php
   // Example: Check for a different class to determine columns
   $sidebar = $xpath->query($converter->toXPath('.your-layout-indicator-class'));
   ```

4. **Change Page Title Logic**: If your site has a different way of determining page titles:

   Update the `getPageTitle()` method to use your site's specific meta tags or other elements.

### Adding New Features

To extend the module with new features:

1. **Add New Processing Methods**: Add new methods to the `ResourceProcessor` class to handle additional content transformations.

2. **Extend the OembedResource Class**: Add new properties and methods to the `OembedResource` class if you need to include additional data in the oEmbed response.

3. **Modify the Response Format**: Update the `generate()` method in the `OembedResource` class to include your custom fields in the response.

## Troubleshooting

### Common Issues

1. **Empty Content**: If the embedded content is empty, check that your content selectors match the HTML structure of your pages.

2. **Missing Styles**: The module only includes the HTML content, not the CSS. You may need to include necessary styles in the embedded HTML.

3. **Caching Issues**: If changes to your content aren't reflected in the oEmbed response, try clearing the Drupal cache.

### Debugging

The module logs errors to the Drupal log. Check the log messages for any issues with processing the content.

## Testing

The module includes functional tests that verify:

- Basic oEmbed responses for valid URLs
- Handling of URL redirects
- Validation of invalid URLs
- Caching behavior

Run the tests using PHPUnit or Drush:

```bash
drush test:class "Drupal\Tests\drupal_oembed\Functional\Installed\EndpointTest"
```

## License

This module is licensed under the GPL v2 or later.

## Credits

Developed by the University of Technology Sydney.
