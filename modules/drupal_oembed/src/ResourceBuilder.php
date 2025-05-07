<?php

namespace Drupal\drupal_oembed;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ResourceBuilder.
 *
 * A class that builds the oEmbed rich response for a given resource.
 *
 * @package Drupal\drupal_oembed
 */
class ResourceBuilder {

  /**
   * Class constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ResourceProcessor $resourceProcessor,
  ) {}

  /**
   * Processes the resource page and returns its rendered oEmbed resource.
   *
   * This method performs the following steps:
   * 1. Renders the page HTML using a sub-request.
   * 2. Sends the HTML for Processing.
   * 3. Retrieves oEmbed response data.
   *
   * @param \Drupal\drupal_oembed\OembedRequest $oembedRequest
   *   Request params.
   *
   * @return false|array
   *   - The rendered page content or oEmbed response data array on success.
   *   - FALSE on failure.
   */
  public function processResourcePage(OembedRequest $oembedRequest): false|array {
    try {
      return $this->buildOembedResource($oembedRequest)->generate();
    }
    catch (\Exception) {
      $this->loggerFactory->get('drupal_oembed')->error('Could not render page.');
      return FALSE;
    }
  }

  /**
   * Generates an oEmbed resource based on the processed HTML content.
   *
   * This method processes the HTML content, retrieves the page title
   * and meta tags,and sets various properties for the oEmbed resource
   * object. It then attempts to generate the oEmbed response. If any
   * exception occurs during the generation, it logs the error and
   * returns false.
   *
   * @param \Drupal\drupal_oembed\OembedRequest $request
   *   The drupal_oembed processing request.
   *
   * @return \Drupal\drupal_oembed\OembedResource
   *   The generated oEmbed resource.
   *
   * @throws \Exception
   *   If an error occurs while generating the oEmbed response.
   */
  protected function buildOembedResource(OembedRequest $request): OembedResource {
    $authorUrl = "/" . $request->getResourcePath();
    $currentRequest = $this->requestStack->getCurrentRequest();
    $providerUrl = $currentRequest->getSchemeAndHttpHost();

    try {
      return $this->resourceProcessor->processResource($request)
        ->setAuthorUrl($authorUrl)
        ->setProviderUrl($providerUrl);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupal_oembed')->error($e->getMessage());
      throw $e;
    }
  }

}
