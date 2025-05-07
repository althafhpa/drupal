<?php

namespace Drupal\drupal_oembed\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\drupal_oembed\OembedRequest;
use Drupal\drupal_oembed\ResourceBuilder;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Class Endpoint.
 *
 * Handles oEmbed callback.
 *
 * @package Drupal\drupal_oembed\Controller
 */
final class Endpoint extends ControllerBase {

  const MAX_AGE = 2764800;

  /**
   * Class constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected PathValidatorInterface $pathValidator,
    protected RendererInterface $renderer,
    protected HttpKernelInterface $httpKernel,
    protected ResourceBuilder $resourceBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): Endpoint {
    return new static(
      $container->get('request_stack'),
      $container->get('path.validator'),
      $container->get('renderer'),
      $container->get('http_kernel.basic'),
      $container->get('drupal_oembed.resource_builder')
    );
  }

  /**
   * Returns an oEmbed response.
   *
   * This method processes the request parameters, validates the URL, handles
   * redirects, and retrieves the resource content to build a cacheable
   * response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request containing query parameters.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   A cacheable response object containing the resource content.
   *
   * @throws \Exception
   */
  public function response(Request $request): CacheableJsonResponse {

    $queryParameters = $request->query;

    $resourcePath = (string) $queryParameters->get('url', '');

    if (!$resourcePath) {
      return $this->errorResponse('URL cannot be empty');
    }

    $sidebar = (bool) ($queryParameters->get('sidebar') ?? 1);

    // Not required for fetching resource. Since sidebar parameter
    // is used to remove sidebar from generated HTML.
    $queryParameters->remove('sidebar');

    // Add leading slash if not present.
    if (!str_starts_with($resourcePath, '/')) {
      $resourcePath = '/' . $resourcePath;
    }

    // Remove parameter 'url' from query parameters.
    // Reason, drupal_oembed parameter 'url' is set already as $resourcePath.
    $queryParameters->remove('url');

    $resourcePath = parse_url($resourcePath, PHP_URL_PATH);

    if (!\is_string($resourcePath)) {
      return $this->errorResponse('Valid URL is required');
    }

    // Check Url is a redirect. If yes use redirect url for resource url.
    $response = $this->makeSubrequest($resourcePath, $queryParameters);
    $redirect_count = 1;
    while ($response->headers->has('location') && $redirect_count < 5) {
      $location = $response->headers->get('location');
      $resourcePath = parse_url($location, PHP_URL_PATH);
      if (!\is_string($resourcePath)) {
        return $this->errorResponse('Valid Redirect is required');
      }
      $response = $this->makeSubrequest($resourcePath, $queryParameters);
      $redirect_count += 1;
    }

    if ($response->headers->has('location')) {
      $this->errorResponse('Redirect loop');
    }

    // Url path exists in Drupal?
    if (!$this->pathValidator->isValid($resourcePath)) {
      return $this->errorResponse('Valid URL is required');
    }

    $oembedRequest = new OEmbedRequest(
      $resourcePath,
      $queryParameters,
      $sidebar,
    );

    // Render context is required to avoid early rendering.
    $renderContext = new RenderContext();

    $cache = new CacheableMetadata();
    $oembedRequest->setRawHtml($response->getContent() ?: '');

    // Get cacheable metadata to add to drupal_oembed response cache dependency.
    if ($response instanceof CacheableResponseInterface) {
      $cache->addCacheableDependency($response->getCacheableMetadata());
    }

    // Get html from page using sub-request response in render context.
    $resourceResponse = $this->renderer
      ->executeInRenderContext($renderContext, fn () => $this->resourceBuilder->processResourcePage($oembedRequest));

    if (!$renderContext->isEmpty()) {
      $cache->addCacheableDependency($renderContext->pop());
    }
    if ($resourceResponse) {
      return $this->getCacheableResponse($resourceResponse, $cache);
    }
    return $this->errorResponse('Resource not found');
  }

  /**
   * Performs a sub-request.
   *
   * This method creates a sub-request to the specified resource URL and
   *  handles the response. Additional query parameters can also be passed.
   *  If the response has a `getCacheableMetadata()` method, it retrieves the
   *  cacheable metadata.
   *  If the response has a `getContent()` method, it retrieves the response
   *  content.
   *
   * @param string $url
   *   The URL to make the subrequest to.
   * @param \Symfony\Component\HttpFoundation\ParameterBag $parameters
   *   Parameters.
   *
   * @return ?\Symfony\Component\HttpFoundation\Response
   *   Subrequest response.
   *
   * @throws \Exception
   *   Thrown if there is an issue with handling the sub-request.
   */
  public function makeSubrequest(string $url, ParameterBag $parameters): ?Response {
    $current_request = $this->requestStack->getCurrentRequest();
    $server_info = $current_request && $current_request->server ? $current_request->server->all() : [];

    $sub_request = Request::create(
      $url,
      'GET',
      \iterator_to_array($parameters),
      [],
      [],
      $server_info
    );

    if ($current_request && $current_request->hasSession()) {
      $sub_request->setSession($current_request->getSession());
    }

    try {
      return $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
    }
    catch (RequestException $e) {
      $this->loggerFactory->get('drupal_oembed')->error($e->getMessage());
      return NULL;
    }
  }

  /**
   * Returns a cacheable response object.
   *
   * @param array $content
   *   The content to be included in the response.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   Cache.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The cacheable response object.
   */
  protected function getCacheableResponse(
    array $content,
    CacheableMetadata $cache,
  ): CacheableJsonResponse {
    $oembedResponse = new CacheableJsonResponse(
      $content,
      Response::HTTP_OK,
      ['Content-Type' => 'application/json']
    );
    $cache->addCacheContexts([
      'url.query_args:url',
    ])
      // Add cache tag for route, so any changes to the endpoint configuration
      // will invalidate all previously cached responses.
      ->addCacheTags([
        'drupal_oembed.endpoint',
      ]);
    $oembedResponse->addCacheableDependency($cache->setCacheMaxAge(self::MAX_AGE))
      // Cache response for 32 days.
      ->setMaxAge(self::MAX_AGE);

    // Set Cache-Control header.
    $oembedResponse->headers->set('Cache-Control', 'public, max-age=2764800');

    return $oembedResponse;
  }

  /**
   * Creates a cacheable response object for an error response.
   *
   * @param string $message
   *   The error message to set in the response.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The cacheable response object.
   */
  protected function errorResponse(string $message): CacheableJsonResponse {
    $errorResponse = new CacheableJsonResponse(
      $message,
      Response::HTTP_NOT_FOUND,
      ['Content-Type' => 'application/json']
    );
    $errorResponse->getCacheableMetadata()->addCacheContexts([
      'url.query_args:url',
    ]);
    $errorResponse->getCacheableMetadata()->addCacheTags([
      'drupal_oembed.endpoint',
    ]);
    return $errorResponse;
  }

}
