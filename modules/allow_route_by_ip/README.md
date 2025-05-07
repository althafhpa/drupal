# Allow Route by IP

## Overview

The Allow Route by IP module provides IP-based access control for Drupal routes. It allows administrators to restrict access to specific routes based on client IP addresses, creating a whitelist system that works alongside Drupal's standard permission system.

This module is particularly useful for:
- Restricting access to specific sections of your site based on IP addresses
- Creating a staging or testing environment that's only accessible from certain networks
- Adding an extra layer of security for sensitive administrative routes

## Features

- Enable/disable IP-based route restrictions globally
- Define route-specific IP whitelists using individual IPs or CIDR notation
- Automatic bypass for authenticated users
- Predefined allowed paths for authentication-related routes
- Detailed logging of blocked access attempts
- Simple administrative interface

## Requirements

- Drupal 9 or 10
- PHP 7.4 or higher

## Installation

1. Download and place the module in your Drupal installation's `modules/` directory.
2. Enable the module via the Drupal admin interface or using Drush:

```bash
drush en allow_route_by_ip
```

## Configuration

### Basic Configuration

1. Navigate to **Administration » Configuration » System » IP Route Access** (`/admin/config/system/allow-route-by-ip`).
2. Check the "Enable IP-based route restrictions" checkbox to activate the module.
3. Enter route and IP mappings in the format: `route_name|ip_address` or `route_name|ip_range` (one per line).

### Route and IP Mapping Format

Each line in the configuration should follow this format:
```
route_name|ip_address
```

or for CIDR notation:
```
route_name|ip_range/mask
```

Examples:
```
entity.node.canonical|192.168.1.100
entity.user.edit_form|10.0.0.0/8
system.admin|192.168.1.0/24
```

### Finding Route Names

To find the route name for a specific page:
1. Install the Devel module
2. Enable the "Devel" block
3. Visit the page you want to restrict
4. Look for the route name in the Devel information

Alternatively, you can use Drush to list all routes:
```bash
drush route:list
```

## How It Works

When a user attempts to access a route:

1. The module checks if IP restrictions are enabled
2. If the user is authenticated, access is granted
3. If the path is in the allowed paths list (e.g., `/user/*`, `/saml/*`), access is granted
4. The module checks if the route is in the configured route-IP map
5. If the route is found, the user's IP is checked against the allowed IPs for that route
6. If the IP matches, access is granted; otherwise, a 404 error is returned

## Default Allowed Paths

The following paths are always accessible regardless of IP restrictions:
- `/user/*` - All user-related paths (login, registration, etc.)
- `/saml/*` - All SAML authentication paths

## Logging

The module logs blocked access attempts with the following information:
- Client IP address
- Requested path

Logs can be viewed at **Administration » Reports » Recent log messages** (`/admin/reports/dblog`).

## Extending the Module

### Adding More Default Allowed Paths

To add more default allowed paths, modify the `isAllowedPath()` method in the `AllowRouteByIpSubscriber` class:

```php
protected function isAllowedPath($path_info): bool {
  // Allow all paths under specific directories.
  $allowed_path_prefixes = [
    '/user',
    '/saml',
    '/your-custom-path', // Add your custom path here
  ];

  // Check if path starts with any of the allowed prefixes.
  foreach ($allowed_path_prefixes as $prefix) {
    if (str_starts_with($path_info, $prefix)) {
      return TRUE;
    }
  }

  return FALSE;
}
```

### Changing the Privileged User Criteria

By default, any authenticated user bypasses IP restrictions. To modify this behavior, change the `isPrivilegedUser()` method in the `AllowRouteByIpSubscriber` class:

```php
protected function isPrivilegedUser(): bool {
  // Example: Only allow users with the 'administrator' role to bypass restrictions
  return $this->currentUser->hasPermission('administer site configuration');
}
```

## Troubleshooting

### Common Issues

1. **All routes are accessible even with restrictions enabled**
   - Check that the module is properly enabled
   - Verify that you're not logged in (authenticated users bypass restrictions)
   - Ensure the route names in your configuration are correct

2. **Routes are inaccessible even from allowed IPs**
   - Verify that your IP address matches what you've configured
   - Check that you're using the correct route name format
   - Ensure CIDR notation is properly formatted (e.g., `192.168.1.0/24`)

3. **404 errors for all restricted routes**
   - This is expected behavior for unauthorized IPs
   - The module returns 404 errors instead of 403 to avoid revealing the existence of restricted routes

## Testing

The module includes unit tests that verify:
- IP restriction functionality
- CIDR range validation
- Allowed paths logic
- Privileged user bypass

Run the tests using PHPUnit:

```bash
vendor/bin/phpunit modules/allow_route_by_ip
```

## License

This module is licensed under the GPL v2 or later.
