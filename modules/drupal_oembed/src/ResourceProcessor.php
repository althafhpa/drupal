<?php

namespace Drupal\drupal_oembed;

use Drupal\Component\Utility\Html;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\metatag\MetatagManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ResourceProcessor.
 *
 * A class that processes the HTML for an drupal_oembed resource.
 *
 * @package Drupal\drupal_oembed
 */
class ResourceProcessor {

  // Default page title for oEmbed.
  const PAGE_TITLE = 'University of Technology Sydney';

  /**
   * Class constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected AliasManagerInterface $aliasManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected MetatagManagerInterface $metaTagManager,
  ) {}

  /**
   * Performing a series of transformations and updates on HTML.
   *
   * This method performs the following steps:
   * 1. Cleans the HTML content.
   * 2. Loads the HTML content into a DOMDocument object.
   * 3. Initializes a DOMXPath object for querying the DOM.
   * 4. Removes form actions from the HTML.
   * 5. Updates node aliases in the HTML.
   * 6. Sets meta tags for the page.
   * 7. Removes breadcrumbs from the HTML.
   * 8. Removes the sidebar from the HTML.
   * 9. Sets the template columns.
   * 10. Extracts the main content HTML.
   * 11. Sets the page title.
   * 12. Normalizes and returns the processed HTML content.
   *
   * @param \Drupal\drupal_oembed\OembedRequest $request
   *   Oembed processing request.
   *
   * @return \Drupal\drupal_oembed\OembedResource
   *   The processed resource.
   */
  public function processResource(OembedRequest $request): OembedResource {
    $resource = new OembedResource();
    $html = $this->getCleanHtml($request->getRawHtml());
    $domDocument = Html::load($html);
    $xPath = new \DOMXPath($domDocument);
    $this->removeFormActions($xPath);
    $this->updateNodeAlias($domDocument);
    $metaTags = $this->buildMetaTags($xPath);
    $resource->setMetaTags($metaTags);
    $this->removeBreadcrumbs($xPath);

    if (!$request->hasSidebar()) {
      $this->removeSidebar($xPath);
    }

    $resource->setTemplateColumns($this->getTemplateColumns($xPath));
    $html = $this->getMainContentHtml($domDocument, $xPath, $html);
    $resource->setTitle($this->getPageTitle($metaTags));

    $html = Html::normalize($html);
    return $resource->setHtml($html)
      ->setAuthorName('Drupal')
      ->setProviderName('University of Technology Sydney')
      ->setWidth(650)
      ->setHeight(500);
  }

  /**
   * Cleans the HTML content.
   *
   * Performs the following actions:
   * - Removes backslashes from the HTML content.
   * - Replaces the host to keep asset paths relative.
   * - Normalizes the HTML content.
   *
   * @param string $html
   *   HTML to process.
   *
   * @return string
   *   Processed HTML.
   */
  protected function getCleanHtml(string $html): string {
    $html = stripslashes($html);
    $currentRequest = $this->requestStack->getCurrentRequest();
    $host = $currentRequest->getSchemeAndHttpHost();

    // Keep assets path relative.
    $html = str_replace($host, "", $html);

    // Add $host to paths beginning with /sites/default/files.
    $html = preg_replace(
      '/(\w+)=["\']?(\/sites\/default\/files[^"\'\s>]+)/i',
      '$1="' . $host . '$2"',
      $html
    );

    return Html::normalize($html);
  }

  /**
   * Updates node links in the HTML content to their corresponding aliases.
   *
   * This method iterates through all 'a' tags in the DOM document, identifies
   * links that match the '/node/{nid}' pattern, and replaces them with their
   * corresponding path aliases. It uses caching to improve performance for
   * repeated node IDs.
   *
   * @param \DOMDocument $document
   *   Document.
   */
  protected function updateNodeAlias(\DOMDocument $document): void {
    try {
      $cache = [];
      foreach ($document->getElementsByTagName('a') as $link) {
        $href = $link->getAttribute("href");
        if (preg_match('/^\/node\/(\d+)/', $href, $matches)) {
          $nid = $matches[1];
          if (!isset($cache[$nid])) {
            $path = '/node/' . $nid;
            $alias = $this->aliasManager->getAliasByPath($path);
            $cache[$nid] = $alias !== $path ? $alias : $href;
          }
          $link->setAttribute('href', $cache[$nid]);
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupal_oembed')->error('Unable to update node links: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Removes the breadcrumb element from the DOM.
   *
   * This function uses the CssSelectorConverter to convert a CSS selector
   * to an XPath query, which is then used to find the breadcrumb element
   * in the DOM. If the breadcrumb element is found, it is removed from
   * its parent node.
   */
  protected function removeBreadcrumbs(\DOMXPath $xpath): void {
    $converter = new CssSelectorConverter();
    $breadcrumbs = $xpath->query($converter->toXPath('.breadcrumbs__wrapper'));

    if ($breadcrumbs === FALSE) {
      return;
    }

    if ($breadcrumbs->length > 0) {
      $breadcrumb = $breadcrumbs->item(0);
      $breadcrumb->parentNode->removeChild($breadcrumb);
    }
  }

  /**
   * Checks for sidebar and returns template columns accordingly.
   */
  protected function getTemplateColumns(\DOMXPath $xpath): int {
    $converter = new CssSelectorConverter();
    $sidebar = $xpath->query($converter->toXPath('.sidebar'));
    return $sidebar !== FALSE && $sidebar->length > 0 ? 2 : 1;
  }

  /**
   * Removes the sidebar navigation from the DOM.
   */
  protected function removeSidebar(\DOMXPath $xpath): void {
    // Hide sidebar navigation.
    $converter = new CssSelectorConverter();
    $sidebarNav = $xpath->query($converter->toXPath('.sidebar-grid__sidebar'));
    if ($sidebarNav !== FALSE && $sidebarNav->length > 0) {
      foreach ($sidebarNav as $nav) {
        $nav->parentNode->removeChild($nav);
      }
    }
  }

  /**
   * Removes the 'action' attribute from all form elements..
   *
   * This method queries the document for all form elements
   * that have an 'action' attribute and removes that attribute
   * from each form element found.
   *
   * @param \DOMXPath $xpath
   *   Xpath.
   */
  protected function removeFormActions(\DOMXPath $xpath): void {
    $forms = $xpath->query('//form[@action]');

    if ($forms !== FALSE && count($forms) > 0) {
      foreach ($forms as $form) {
        \assert($form instanceof \DOMElement);
        $form->removeAttribute('action');
      }
    }
  }

  /**
   * Retrieves and combines HTML content from various elements in the DOM.
   *
   * This method:
   * 1. Checks for course search block and prepends if found
   * 2. Gets content from element with data-block="content-main"
   * 3. Finds any .one-col div that appears after the main content block
   * 4. Combines these elements in the correct order.
   *
   * @param \DOMDocument $document
   *   The DOM document.
   * @param \DOMXPath $xpath
   *   XPath object for querying the DOM.
   * @param string $contentHtml
   *   Original content HTML as fallback.
   *
   * @return string
   *   Combined HTML content or original content if no elements found.
   */
  protected function getMainContentHtml(\DOMDocument $document, \DOMXPath $xpath, string $contentHtml): string {
    $course_search = $document->getElementById('block-course-search');

    $match = $xpath->query('//*[@data-block="content-main"]');
    if ($match === FALSE) {
      return $contentHtml;
    }

    $mainContentNode = $match->item(0);
    if ($course_search) {
      $course_search_html = $document->saveHTML($course_search);
      $contentHtml = $course_search_html . $contentHtml;
      if ($mainContentNode === NULL) {
        return $contentHtml;
      }
    }

    // Get the main content HTML.
    $resultHtml = $document->saveHTML($mainContentNode) ?: '';

    // Look for .one-col element that follows content-main.
    $oneColElement = $xpath->query('//div[@data-block="content-main"]/following-sibling::div[contains(@class, "one-col")]')->item(0);
    if ($oneColElement !== NULL) {
      $resultHtml .= $document->saveHTML($oneColElement);
    }

    // Return the combined HTML or fall back to the original content.
    return !empty($resultHtml) ? $resultHtml : $contentHtml;
  }

  /**
   * Sets the page meta tags by querying the DOM for meta tags.
   *
   * This method uses XPath to find all meta tags in the head of
   * the document that have either a 'name' or 'property' attribute.
   * It then stores these meta tags in the $metaTags array, using
   * the attribute value as the key and the content as the value.
   *
   * @param \DOMXPath $xpath
   *   Xpath.
   *
   * @return array
   *   Metatags.
   */
  protected function buildMetaTags(\DOMXPath $xpath): array {
    $metatags = [];
    try {
      $nodes_name = $xpath->query('//head/meta[@name]');
      $nodes_property = $xpath->query('//head/meta[@property]');

      if ($nodes_name !== FALSE) {
        foreach ($nodes_name as $meta_name) {
          \assert($meta_name instanceof \DOMElement);
          $metatags[$meta_name->getAttribute('name')] = $meta_name->getAttribute('content');
        }
      }

      if ($nodes_property !== FALSE) {
        foreach ($nodes_property as $meta_property) {
          \assert($meta_property instanceof \DOMElement);
          $metatags[$meta_property->getAttribute('property')] = $meta_property->getAttribute('content');
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupal_oembed')->error('Unable to get meta tags ' . $e->getMessage());
    }
    return $metatags;
  }

  /**
   * Sets the page title based on the node object title or meta tags.
   *
   * This method determines the page title by checking if the node object exists
   * and has a title. If so, it uses that title; otherwise, it falls back to the
   * 'og:title' meta tag. It then appends the site name from the 'og:site_name'
   * meta tag or a default constant PAGE_TITLE.
   */
  protected function getPageTitle(array $metatags): string {
    $title = $metatags['og:title'] ?? NULL;
    $site_name = $metatags['og:site_name'] ?? self::PAGE_TITLE;
    if ($title === NULL) {
      return $site_name;
    }
    return $title . " | " . $site_name;
  }

}
