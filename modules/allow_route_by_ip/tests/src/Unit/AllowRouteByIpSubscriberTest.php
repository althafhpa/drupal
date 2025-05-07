<?php

namespace Drupal\Tests\allow_route_by_ip\Unit;

use Drupal\allow_route_by_ip\EventSubscriber\AllowRouteByIpSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Tests the AllowRouteByIpSubscriber class.
 *
 * @group allow_route_by_ip
 * @coversDefaultClass \Drupal\allow_route_by_ip\EventSubscriber\AllowRouteByIpSubscriber
 */
class AllowRouteByIpSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   *
   * @var \Drupal\allow_route_by_ip\EventSubscriber\AllowRouteByIpSubscriber
   */
  protected $subscriber;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock objects for dependencies.
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->expects($this->any())
      ->method('get')
      ->with('allow_route_by_ip')
      ->willReturn($this->logger);

    // Create the subscriber.
    $this->subscriber = new AllowRouteByIpSubscriber(
          $this->configFactory,
          $this->currentUser,
          $this->loggerFactory
      );
  }

  /**
   * Test functionality is disabled via config.
   *
   * @covers ::onRequest
   */
  public function testOnRequestWhenDisabled() {
    // Create a mock request.
    $request = $this->createMock(Request::class);

    // Create a mock RequestEvent that returns the request.
    $event = $this->createMock(RequestEvent::class);
    $event->expects($this->once())
      ->method('isMainRequest')
      ->willReturn(TRUE);
    $event->expects($this->never())
      ->method('getRequest');

    // Create a mock config that returns enabled = FALSE.
    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())
      ->method('get')
      ->with('enabled')
      ->willReturn(FALSE);

    // Set up configFactory to return our mock config.
    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('allow_route_by_ip.settings')
      ->willReturn($config);

    // Call onRequest and verify it exits early.
    $this->subscriber->onRequest($event);
  }

  /**
   * Test functionality is enabled via config.
   *
   * @covers ::onRequest
   */
  public function testOnRequestWhenEnabled() {
    // Create a mock request with path and client IP.
    $request = $this->createMock(Request::class);
    $request->expects($this->once())
      ->method('getPathInfo')
      ->willReturn('/test/path');
    $request->expects($this->once())
      ->method('getClientIp')
      ->willReturn('192.168.1.1');
    $request->attributes = new ParameterBag(['_route' => 'test.route']);

    // Create a mock RequestEvent that returns the request.
    $event = $this->createMock(RequestEvent::class);
    $event->expects($this->once())
      ->method('isMainRequest')
      ->willReturn(TRUE);
    $event->expects($this->once())
      ->method('getRequest')
      ->willReturn($request);

    // Create a mock config that returns enabled = TRUE.
    $moduleConfig = $this->createMock(ImmutableConfig::class);
    $moduleConfig->expects($this->once())
      ->method('get')
      ->with('enabled')
      ->willReturn(TRUE);

    // Config for route IP map.
    $routeConfig = $this->createMock(ImmutableConfig::class);
    $routeConfig->expects($this->once())
      ->method('get')
      ->with('route_ip_map')
      ->willReturn(['test__route' => ['192.168.1.1']]);

    // Set up configFactory to return our mock configs.
    $this->configFactory->expects($this->exactly(2))
      ->method('get')
      ->with('allow_route_by_ip.settings')
      ->willReturnOnConsecutiveCalls($moduleConfig, $routeConfig);

    // Set up the current user to be anonymous.
    $this->currentUser->expects($this->once())
      ->method('isAuthenticated')
      ->willReturn(FALSE);

    // Call onRequest.Should check allowedPath and allowedRouteForIp.
    $this->subscriber->onRequest($event);
  }

  /**
   * Tests the isAllowedPath method.
   *
   * @covers ::isAllowedPath
   * @dataProvider providerIsAllowedPath
   */
  public function testIsAllowedPath($path, $expected) {
    // Use reflection to access protected method.
    $method = new \ReflectionMethod(AllowRouteByIpSubscriber::class, 'isAllowedPath');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->subscriber, $path);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testIsAllowedPath.
   */
  public function providerIsAllowedPath() {
    return [
      'user path' => ['/user/login', TRUE],
      'saml path' => ['/saml/login', TRUE],
      'random path' => ['/node/123', FALSE],
      'api path' => ['/api/v1/content', FALSE],
    ];
  }

  /**
   * Tests the isPrivilegedUser method.
   *
   * @covers ::isPrivilegedUser
   * @dataProvider providerIsPrivilegedUser
   */
  public function testIsPrivilegedUser($isAuthenticated, $expected) {
    $this->currentUser->expects($this->once())
      ->method('isAuthenticated')
      ->willReturn($isAuthenticated);

    // Use reflection to access protected method.
    $method = new \ReflectionMethod(AllowRouteByIpSubscriber::class, 'isPrivilegedUser');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->subscriber);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testIsPrivilegedUser.
   */
  public function providerIsPrivilegedUser() {
    return [
      'authenticated user' => [TRUE, TRUE],
      'anonymous user' => [FALSE, FALSE],
    ];
  }

  /**
   * Tests the isAllowedRouteForIp method.
   *
   * @covers ::isAllowedRouteForIp
   * @dataProvider providerIsAllowedRouteForIp
   */
  public function testIsAllowedRouteForIp($routeName, $clientIp, $routeMap, $expected) {
    // Create a mock request with attributes.
    $request = $this->createMock(Request::class);
    $request->attributes = new ParameterBag(['_route' => $routeName]);

    $request->expects($this->any())
      ->method('getClientIp')
      ->willReturn($clientIp);

    // Create a mock for RequestEvent.
    $event = $this->createMock(RequestEvent::class);

    // Create a mock config object.
    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())
      ->method('get')
      ->with('route_ip_map')
      ->willReturn($routeMap);

    // Set up configFactory to return our mock config.
    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('allow_route_by_ip.settings')
      ->willReturn($config);

    // Use reflection to access protected method.
    $method = new \ReflectionMethod(AllowRouteByIpSubscriber::class, 'isAllowedRouteForIp');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->subscriber, $event, $request, $clientIp, '/test/path');
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testIsAllowedRouteForIp.
   */
  public function providerIsAllowedRouteForIp() {
    return [
      'route not in map' => [
        'test.route',
        '192.168.1.1',
              [],
        FALSE,
      ],
      'ip in allowed range' => [
        'test.route',
        '192.168.1.1',
              ['test__route' => ['192.168.1.1']],
        TRUE,
      ],
      'ip in allowed cidr range' => [
        'test.route',
        '192.168.1.100',
              ['test__route' => ['192.168.1.0/24']],
        TRUE,
      ],
      'ip not in allowed range' => [
        'test.route',
        '192.168.2.1',
              ['test__route' => ['192.168.1.0/24']],
        FALSE,
      ],
    ];
  }

}
