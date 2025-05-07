<?php

namespace Drupal\drupal_oembed;

/**
 * A class that generates oEmbed `rich` format resource.
 *
 * This class represents an oEmbed resource with properties such as title,
 * author name, provider name, width, height, HTML, and meta tags.
 * It provides methods to set these properties and generate a formatted version
 * of the resource. Class is set to use default drupal_oembed response type of `rich`
 * format.
 */
class OembedResource {

  /**
   * The resource type.
   *
   * @var string
   */
  protected string $type;

  /**
   * The oEmbed version number.
   *
   * @var string
   */
  protected string $version = '1.0';

  /**
   * A text title, describing the resource.
   *
   * @var string
   */
  protected string $title;

  /**
   * The name of the author/owner of the resource.
   *
   * @var string
   */
  protected string $authorName;

  /**
   * A URL for the author/owner of the resource.
   *
   * @var string
   */
  protected string $authorUrl;

  /**
   * The name of the resource provider.
   *
   * @var string
   */
  protected string $providerName;

  /**
   * The url of the resource provider.
   *
   * @var string
   */
  protected string $providerUrl;

  /**
   * The resource width (in pixels).
   *
   * @var int
   */
  protected int $width;

  /**
   * The resource height (in pixels).
   *
   * @var int
   */
  protected int $height;

  /**
   * The HTML required to display the resource.
   *
   * @var string
   */
  protected string $html;

  /**
   * The meta tags of the resource.
   *
   * @var array
   */
  protected array $metaTags;

  /**
   * The template columns of the resource.
   *
   * @var int
   */
  protected int $templateColumns;

  /**
   * Constructs an OembedResource object.
   */
  public function __construct() {
    $this->type = 'rich';
  }

  /**
   * Sets the "title" parameter.
   *
   * @param string $title
   *   The "title" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setTitle(string $title): static {
    $this->title = $title;
    return $this;
  }

  /**
   * Sets the "authorName" parameter.
   *
   * @param string $author_name
   *   The "author_name" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setAuthorName(string $author_name): static {
    $this->authorName = $author_name;
    return $this;
  }

  /**
   * Sets the "authorUrl" parameter.
   *
   * @param string $author_url
   *   The "author_url" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setAuthorUrl(string $author_url): static {
    $this->authorUrl = $author_url;
    return $this;
  }

  /**
   * Sets the "providerName" parameter.
   *
   * @param string $provider_name
   *   The "provider_name" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setProviderName(string $provider_name): static {
    $this->providerName = $provider_name;
    return $this;
  }

  /**
   * Sets the "providerUrl" parameter.
   *
   * @param string $provider_url
   *   The "provider_url" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setProviderUrl(string $provider_url): static {
    $this->providerUrl = $provider_url;
    return $this;
  }

  /**
   * Sets the "width" parameter.
   *
   * @param int $width
   *   The "width" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setWidth(int $width): static {
    $this->width = $width;
    return $this;
  }

  /**
   * Sets the "height" parameter.
   *
   * @param int $height
   *   The "height" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setHeight(int $height): static {
    $this->height = $height;
    return $this;
  }

  /**
   * Sets the "html" parameter.
   *
   * @param string $html
   *   The "html" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setHtml(string $html): static {
    $this->html = $html;
    return $this;
  }

  /**
   * Sets the "metaTags" parameter.
   *
   * @param array $meta_tags
   *   The "meta_tags" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setMetaTags(array $meta_tags): static {
    $this->metaTags = $meta_tags;
    return $this;
  }

  /**
   * Sets the "templateColumns" parameter.
   *
   * @param int $template_columns
   *   The "template_columns" parameter.
   *
   * @return static
   *   The current instance.
   */
  public function setTemplateColumns(int $template_columns): static {
    $this->templateColumns = $template_columns;
    return $this;
  }

  /**
   * Validates the resource object.
   *
   * @return bool
   *   TRUE if the resource is valid, FALSE otherwise.
   */
  protected function validateResource(): bool {
    if (empty($this->html) || empty($this->width) || empty($this->height)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generates a formatted version of the resource.
   *
   * @return array
   *   The formatted resource.
   *
   * @throws \Exception
   *   Thrown if the resource is not valid.
   */
  public function generate(): array {
    if (!$this->validateResource()) {
      throw new \Exception('HTML, width, and height parameters are
      required for drupal_oembed rich type resource.');
    }

    return [
      'type' => $this->type,
      'version' => $this->version,
      'title' => $this->title ?? '',
      'author_name' => $this->authorName ?? '',
      'author_url' => $this->authorUrl ?? '',
      'provider_name' => $this->providerName ?? '',
      'provider_url' => $this->providerUrl ?? '',
      'width' => $this->width,
      'height' => $this->height,
      'html' => $this->html,
      'meta_tags' => $this->metaTags ?? [],
      'template_columns' => $this->templateColumns,
    ];
  }

}
