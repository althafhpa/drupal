<?php

namespace Drupal\allow_route_by_ip\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for IP-based route access restrictions.
 *
 * Provides an administrative interface to manage which IP addresses
 * are allowed to access specific routes, creating a whitelist system
 * for route access control based on client IP.
 */
class AllowRouteByIpSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['allow_route_by_ip.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'allow_route_by_ip_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Retrieve the module's configuration settings.
    $config = $this->config('allow_route_by_ip.settings');

    // Add a checkbox to enable/disable the IP restriction functionality.
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable IP-based route restrictions'),
      '#description' => $this->t('When disabled, all routes will be accessible regardless of IP address.'),
      // Use the configured value if available,
      // otherwise default to FALSE (disabled)
      '#default_value' => $config->get('enabled') ?? FALSE,
    ];

    // Add a textarea field for entering route and IP address mappings.
    $form['route_ip_pairs'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Route and IP Mappings'),
      '#description' => $this->t('Enter route and IP pairs in the format: route_name|ip_address or route_name|ip_range (one per line). Examples: entity.node.canonical|192.168.1.100 or entity.node.canonical|192.168.1.0/24'),
      // Format the stored route-IP mappings
      // into a human-readable format for the form.
      '#default_value' => $this->formatRouteIpPairs($config->get('route_ip_map') ?: []),
      // Set the textarea to display 10 rows.
      '#rows' => 10,
    ];

    // Let the parent class add standard
    // form elements (like the submit button)
    return parent::buildForm($form, $form_state);

  }

  /**
   * Formats route-IP mappings for display in the form.
   *
   * Converts the structured route_ip_map array into a human-readable
   * string format with one route-IP pair per line.
   *
   * @param array $route_ip_map
   *   The route-IP mapping array from configuration.
   *
   * @return string
   *   Formatted string representation of route-IP pairs.
   */
  protected function formatRouteIpPairs($route_ip_map) {
    // Initialize an empty array to store the formatted route-IP pairs.
    $pairs = [];

    // Loop through each route and its associated
    // IP addresses in the configuration.
    foreach ($route_ip_map as $route_key => $ips) {
      // Convert the storage format with double underscores
      // back to route format with dots.
      $route = str_replace('__', '.', $route_key);

      // For each IP address associated with this route.
      foreach ($ips as $ip) {
        // Format a single route-IP pair with the
        // pipe separator and add to the array.
        $pairs[] = $route . '|' . $ip;
      }
    }

    // Join all pairs with newlines to create the multi-line
    // string for the form.
    return implode("\n", $pairs);
  }

  /**
   * Validates an IP address or CIDR range.
   *
   * @param string $ip
   *   The IP address or CIDR range to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidIpOrRange($ip) {
    // Check if the input contains a slash, indicating it
    // might be a CIDR range notation.
    if (strpos($ip, '/') !== FALSE) {
      // Split the CIDR notation into the subnet address and bit length parts.
      [$subnet, $bits] = explode('/', $ip);

      // Validate that:
      // 1. The subnet portion is a valid IP address
      // 2. The bits portion is a numeric value
      // 3. The bits value is between 0 and 32
      // (valid CIDR range for IPv4)
      return filter_var($subnet, FILTER_VALIDATE_IP) && is_numeric($bits) && $bits >= 0 && $bits <= 32;
    }

    // If not a CIDR range, simply validate as a single IP address.
    return filter_var($ip, FILTER_VALIDATE_IP);

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Split the form input into individual lines,
    // each representing a route-IP pair.
    $pairs = explode("\n", $form_state->getValue('route_ip_pairs'));

    // Process each route-IP pair for validation.
    foreach ($pairs as $pair) {
      // Remove any leading/trailing whitespace.
      $pair = trim($pair);

      // Skip empty lines.
      if (empty($pair)) {
        continue;
      }

      // Split each pair by the pipe character and ensure
      // we have both parts
      // array_pad ensures we have exactly 2 elements even
      // if the split results in fewer.
      [$route, $ip] = array_pad(explode('|', $pair), 2, '');

      // Check if either the route or IP part is missing.
      if (empty($route) || empty($ip)) {
        // Set a form error for invalid format.
        $form_state->setError($form['route_ip_pairs'], $this->t('Invalid format: @pair', ['@pair' => $pair]));
        continue;
      }

      // Validate the IP address or CIDR range format.
      if (!$this->isValidIpOrRange($ip)) {
        // Set a form error for invalid IP address or range.
        $form_state->setError($form['route_ip_pairs'], $this->t('Invalid IP address or range: @ip', ['@ip' => $ip]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Split the route-IP pairs textarea into an array of lines,
    // trim whitespace from each, and filter out any empty lines.
    $pairs = array_filter(array_map('trim', explode("\n", $form_state->getValue('route_ip_pairs'))));

    // Initialize an empty array to store the processed
    // route-IP mappings in storage format.
    $route_ip_map = [];

    // Process each route-IP pair line.
    foreach ($pairs as $pair) {
      // Split each pair into route name and IP address
      // using the pipe separator.
      [$route, $ip] = explode('|', $pair);

      // Convert route names with dots (e.g., entity.node.canonical)
      // to storage format with
      // double underscores (e.g., entity__node__canonical)
      // to use as array keys.
      $route_key = str_replace('.', '__', $route);

      // Add the IP address to the array for this route,
      // allowing multiple IPs per route.
      $route_ip_map[$route_key][] = $ip;
    }

    // Save the updated configuration values to the
    // module's configuration.
    $this->config('allow_route_by_ip.settings')
      // Save the enabled/disabled state of the feature.
      ->set('enabled', $form_state->getValue('enabled'))
      // Save the processed route-IP mappings in storage format.
      ->set('route_ip_map', $route_ip_map)
      // Write the configuration to storage.
      ->save();

    // Call the parent implementation to handle standard form submission tasks.
    parent::submitForm($form, $form_state);

  }

}
