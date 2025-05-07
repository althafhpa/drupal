Entity Display JSON
A Drupal module that provides JSON endpoints for accessing entity data based on display settings.

Features
Site settings
The route '/ejson' provides basic site info:

Site name
Site slogan
Default language
Languages (each with langcode, labels and path)
Homepage entity information (UUID and entity type)
Retrieving content
By Entity Type and UUID
Access content in JSON format through the route /ejson/{entity_type}/{uuid}/{display_id}. This endpoint:

Handles References: Automatically iterates through all referenced entities to compile comprehensive content data, ensuring a complete representation of the requested entity.
Optional Display ID: The {display_id} parameter is optional. If omitted, 'default' is used, allowing flexibility in specifying display modes for entities.
Entity Display Customization: Leverage entity display settings to control which fields are visible. This is especially useful for tailoring the JSON output, including customizing image URLs for thumbnails and other media.
Metatags: Includes metatag information when available, providing SEO-related data for the entity.
Language Specific Content:

To retrieve content in a specific language, append a query parameter like ?lang=en to the URL. This feature enables on-the-fly language customization for your content, making the module adaptable for multilingual sites.

By Path
Access content using its path alias through the route /ejson/path?path={path}. This endpoint:

Resolves the provided path to an entity
Returns the same JSON representation as the entity type/UUID endpoint
Supports the same query parameters, including language selection with ?lang=en
Example: /ejson/path?path=about-us

Listing Nodes
Get a list of nodes with their metadata through the route /ejson/nodes/list. This endpoint:

Returns basic information about nodes including ID, title, UUID, entity type, bundle, creation/modification dates, and paths
Supports filtering by content type with ?bundle=article
Supports filtering by path with ?path=about-us
Supports pagination with ?page=0&limit=10
Example: /ejson/nodes/list?bundle=article&limit=5&page=0

Field Support
The module provides comprehensive support for various field types:

Text fields (plain, formatted, with summary)
Entity reference fields (including nested entity data)
File and media fields (including URLs and metadata)
Link fields (with proper URL transformation)
Field groups (maintaining the structure defined in the display settings)
Views Support
When accessing a view entity, the module:

Returns view results as processed entities
Includes pagination information
Includes exposed filter information
Preserves the view's display settings
Technical Details
The module uses Drupal's entity display system to determine which fields to include in the JSON output and how to format them. This means that the JSON representation of an entity will match how it would appear when rendered using the specified display mode.

The module also handles entity references recursively, ensuring that all referenced entities are included in the JSON output with their own display settings applied.

Example:

List nodes based on content type:

Get Path Alias/node id/uuid  ...

limit=50

List a node page/page based on path and list all the fields:

Get all the fields and contents.

Using path alias:

https://mysite.ddev.site/ejson/fields?path=/about/mysite-business-school/financhttps://mysite8.ddev.site/ejson/node/6dd0fd57-0746-426f-8d10-d7b78e7c7667e-department/events-0/monetary-policy-transmission-rental-housing-market

OR

Using Drupal Internal path:

https://mysite.ddev.site/ejson/fields?path=node/430216

OR

Using UUID:
https://mysite.ddev.site/ejson/node/55c6b6e4-d2e9-4cfc-b155-803edb4faf9e
