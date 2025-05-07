<?php

namespace Drupal\allow_route_by_ip\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber that restricts access to routes based on IP address.
 *
 * This subscriber checks incoming requests against allowed IP addresses
 * for specific routes and returns a 404 response for unauthorized IPs.
 */
class AllowRouteByIpSubscriber implements EventSubscriberInterface {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new AllowRouteByIpSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('allow_route_by_ip');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest'];
    return $events;
  }

  /**
   * Handles the kernel request event.
   *
   * Checks if the current request is allowed based on path, user privileges,
   * and IP restrictions. Restricts access if needed.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    // Check if the feature is enabled, exit early if not.
    $config = $this->configFactory->get('allow_route_by_ip.settings');
    if (!$config->get('enabled')) {
      return;
    }

    $request = $event->getRequest();
    $client_ip = $request->getClientIp();
    $path_info = $request->getPathInfo();

    // Check for allowed paths first.
    if ($this->isAllowedPath($path_info) || $this->isPrivilegedUser()) {
      return;
    }

    if ($this->isAllowedRouteForIp($event, $request, $client_ip, $path_info)) {
      return;
    }

    throw new NotFoundHttpException();
  }

  /**
   * Checks if the current route is allowed for the given IP address.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $client_ip
   *   The client IP address.
   * @param string $path_info
   *   The path information.
   *
   * @return bool
   *   TRUE if the route is allowed for this IP, FALSE otherwise.
   */
  protected function isAllowedRouteForIp(RequestEvent $event, $request, $client_ip, $path_info): bool {
    $route_name = $request->attributes->get('_route');
    $route_key = str_replace('.', '__', $route_name);
    $route_map = $this->configFactory->get('allow_route_by_ip.settings')->get('route_ip_map') ?: [];

    if (empty($route_map[$route_key])) {
      $this->logger->warning('Blocked access to non-whitelisted route - IP: @ip, Path: @path', [
        '@ip' => $client_ip,
        '@path' => $path_info,
      ]);
      return FALSE;
    }

    foreach ($route_map[$route_key] as $range) {
      if ($this->isIpInRange($client_ip, $range)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the given path is in the allowed paths list.
   *
   * @param string $path_info
   *   The path to check.
   *
   * @return bool
   *   TRUE if the path is allowed, FALSE otherwise.
   */
  protected function isAllowedPath($path_info): bool {
    // Allow all paths under specific directories.
    $allowed_path_prefixes = [
      '/user',
      '/saml',
    ];

    // Check if path starts with any of the allowed prefixes.
    foreach ($allowed_path_prefixes as $prefix) {
      if (str_starts_with($path_info, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the current user is privileged.
   *
   * @return bool
   *   TRUE if the user is privileged, FALSE otherwise.
   */
  protected function isPrivilegedUser(): bool {
    return $this->currentUser->isAuthenticated();
  }

  /**
   * Checks if an IP address is within a given range.
   *
   * Supports both single IP addresses and CIDR notation.
   *
   * @param string $ip
   *   The IP address to check.
   * @param string $range
   *   The IP range (single IP or CIDR notation).
   *
   * @return bool
   *   TRUE if the IP is in the range, FALSE otherwise.
   */
  protected function isIpInRange($ip, $range): bool {
    if (strpos($range, '/') !== FALSE) {
      // Check if the range is specified in CIDR notation (e.g., 192.168.1.0/24)
      [$subnet, $bits] = explode('/', $range);

      // Convert IP string to a 32-bit integer (long) representation.
      $ip = ip2long($ip);

      // Convert subnet base address string to a 32-bit integer representation.
      $subnet = ip2long($subnet);

      // Create a binary subnet mask based on the CIDR prefix length
      // -1 is all 1s in binary,
      // then left-shift to set the appropriate number of bits to 0.
      $mask = -1 << (32 - $bits);

      // Apply the subnet mask to the subnet address using
      // bitwise AND this ensures we're working with the
      // network address (starting address of the range)
      $subnet &= $mask;

      // Check if the IP address belongs to the subnet
      // by applying the same mask to the IP and
      // comparing the result with the network address.
      return ($ip & $mask) == $subnet;

    }

    // If not CIDR notation, check for exact IP match.
    return $ip === $range;
  }

}
