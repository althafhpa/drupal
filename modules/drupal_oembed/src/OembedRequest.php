<?php

declare(strict_types=1);

namespace Drupal\drupal_oembed;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Defines a class to represent an oEmbed request.
 */
final class OembedRequest {

  protected string $rawHtml;

  public function __construct(
    protected string $resourcePath,
    protected ParameterBag $parameters,
    protected bool $sidebar,
  ) {}

  /**
   * Returns TRUE if has a sidebar.
   */
  public function hasSidebar(): bool {
    return $this->sidebar;
  }

  /**
   * Gets resource path for current request.
   */
  public function getResourcePath(): string {
    return $this->resourcePath;
  }

  /**
   * Gets parameters for current request.
   */
  public function getParameters(): ParameterBag {
    return $this->parameters;
  }

  /**
   * Gets raw HTML for request.
   */
  public function getRawHtml(): string {
    return $this->rawHtml;
  }

  /**
   * Sets raw HTML.
   */
  public function setRawHtml(string $rawHtml): static {
    $this->rawHtml = $rawHtml;
    return $this;
  }

}
