<?php

namespace Drupal\Tests\drupal_oembed\Functional\Installed;

use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\drupal_profile\Functional\drupalTestBase;
use Drupal\drupal_oembed\Controller\Endpoint;

/**
 * Defines a class for testing drupal_oembed endpoint.
 *
 * @group drupal_oembed
 */
class EndpointTest extends drupalTestBase {

  /**
   * The site directory.
   *
   * @var string
   */
  protected $siteDirectory;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'drupal';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_oembed',
  ];

  /**
   * Tests drupal_oembed endpoint for page node rendered using view builder.
   *
   * Test URL:`/drupal_oembed/content?url=/study`
   *
   * Verify drupal_oembed endpoint response for drupal_oembed endpoint works by checking the
   * response contains `"type":"rich"` and `"html"`.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testOembedNode() {
    $url_path = 'drupal_oembed/content';
    $query_params = ['url' => '/study'];

    $this->drupalGet($url_path, ['query' => $query_params]);

    $response = $this->getSession()->getPage()->getContent();
    $response_data = json_decode($response, TRUE);

    // Check that the response is a valid JSON.
    $this->assertNotNull($response_data, 'Response is valid JSON.');

    // Check that the response contains the expected JSON keys and values.
    $this->assertArrayHasKey('type', $response_data);
    $this->assertEquals('rich', $response_data['type']);
    $this->assertArrayHasKey('html', $response_data);

    // Additional assertions to verify the response is cacheable.
    $cache_control_header = $this->getSession()->getResponseHeader('Cache-Control');
    $cache_control_values = array_map('trim', explode(',', $cache_control_header));
    $this->assertContains('public', $cache_control_values);
    $this->assertContains('max-age=' . Endpoint::MAX_AGE, $cache_control_values);

    // Log all headers for debugging.
    $headers = $this->getSession()->getResponseHeaders();
    \Drupal::logger('drupal_oembed')->debug('<pre>' . print_r($headers, TRUE) . '</pre>');

    // Check if the cache tags header exists before asserting its value.
    $cache_tags_header = $this->getSession()->getResponseHeader('X-Drupal-Cache');
    $this->assertNotNull($cache_tags_header, 'X-Drupal-Cache header is present.');
    $this->assertNotEmpty($cache_tags_header, 'Cache tags are present.');
  }

  /**
   * Test drupal_oembed response gets response if url param is a redirect.
   *
   * Test URL: `/drupal_oembed/content?url=/scholarship-search`
   *
   * Verify drupal_oembed endpoint response for drupal_oembed endpoint works by
   * checking the response contains `"type":"rich"` and `"html"`.
   */
  public function testOembedRedirect() {
    // Url /scholarship is redirected to /scholarships
    // So it should still return drupal_oembed response.
    $url_path = 'drupal_oembed/content';

    $query_params = ['url' => '/scholarship'];

    $this->drupalGet($url_path, ['query' => $query_params]);

    $response = $this->getSession()->getPage()->getContent();
    $response_data = json_decode($response, TRUE);

    // Check that the response is a valid JSON.
    $this->assertNotNull($response_data, 'Response is valid JSON.');

    // Check that the response contains the expected JSON keys and values.
    $this->assertArrayHasKey('type', $response_data);
    $this->assertEquals('rich', $response_data['type']);
    $this->assertArrayHasKey('html', $response_data);

    // Additional assertions to verify the response is cacheable.
    $cache_control_header = $this->getSession()->getResponseHeader('Cache-Control');
    $cache_control_values = array_map('trim', explode(',', $cache_control_header));
    $this->assertContains('max-age=' . Endpoint::MAX_AGE, $cache_control_values);

    // Log all headers for debugging.
    $headers = $this->getSession()->getResponseHeaders();
    \Drupal::logger('drupal_oembed')->debug('<pre>' . print_r($headers, TRUE) . '</pre>');

    // Check if the cache tags header exists before asserting its value.
    $cache_tags_header = $this->getSession()->getResponseHeader('X-Drupal-Cache');
    $this->assertNotNull($cache_tags_header, 'X-Drupal-Cache header is present.');
    $this->assertNotEmpty($cache_tags_header, 'Cache tags are present.');
  }

  /**
   * Tests drupal_oembed endpoint for invalid url validation.
   *
   * Test URL:`/drupal_oembed/content?url=/testing-drupal_oembed-fake-url`
   *
   * Verify if the response contains string "Valid URL is required".
   */
  public function testOembedValidUrl() {
    $url_path = 'drupal_oembed/content';
    $query_params = ['url' => '/testing-drupal_oembed-fake-url'];
    $this->drupalGet($url_path, ['query' => $query_params]);

    // Ensure the response is loaded and contains the expected content.
    $response = $this->getSession()->getPage()->getContent();
    $this->assertNotEmpty($response, 'Response is not empty.');

    // Decode the JSON response.
    $response_data = json_decode($response, TRUE);
    $this->assertNotNull($response_data, 'Response is valid JSON.');

    // Check if the response contains the expected error message.
    $this->assertStringContainsString('Valid URL is required', $response);
  }

  /**
   * Tests drupal_oembed endpoint for empty url parameter.
   *
   * Test URL:`/drupal_oembed/content`
   * Test URL:`/drupal_oembed/content?url=`
   *
   * Verify if the response contains string "URL cannot be empty".
   */
  public function testOembedEmptyUrl() {
    $url_path = 'drupal_oembed/content';
    $query_params = ['url' => ''];
    $this->drupalGet($url_path, ['query' => $query_params]);

    // Ensure the response is loaded and contains the expected content.
    $response = $this->getSession()->getPage()->getContent();
    $this->assertNotEmpty($response, 'Response is not empty.');

    // Check if the response contains the expected error message.
    $this->assertStringContainsString('URL cannot be empty', $response);
  }

  /**
   * Tests drupal_oembed response function.
   *
   * Verify status code is 200.
   * Verify the Cache-Control header for Cacheable Response.
   * Verify that the JSON is valid.
   * Verify json_decoded response body is an array and has expected keys
   * used in our drupal_oembed response.
   * Verify drupal_oembed endpoint url cache gets automatically invalidated when
   * Drupal node is updated.
   */
  public function testResponse() {
    $node = $this->createBasicPage();
    $title = $node->getTitle();

    // Create a path alias for node.
    $path_alias = PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/drupal_oembed/test-content',
    ]);
    $path_alias->save();

    $url_path = 'drupal_oembed/content';
    $query_params = ['url' => '/drupal_oembed/test-content'];
    $this->drupalGet($url_path, ['query' => $query_params]);

    // Verify status code is 200.
    $status_code = $this->getSession()->getStatusCode();
    $this->assertEquals(200, $status_code);

    // Additional assertions to verify the response is cacheable.
    $cache_control_header = $this->getSession()->getResponseHeader('Cache-Control');
    $cache_control_values = array_map('trim', explode(',', $cache_control_header));
    $this->assertContains('max-age=' . Endpoint::MAX_AGE, $cache_control_values);

    // Check if the cache tags header exists before asserting its value.
    $cache_tags_header = $this->getSession()->getResponseHeader('X-Drupal-Cache');
    $this->assertNotNull($cache_tags_header, 'X-Drupal-Cache header is present.');
    $this->assertNotEmpty($cache_tags_header, 'Cache tags are present.');

    $data = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($title, $data);

    $response_content = json_decode($data, TRUE);

    // Add some defensive checks.
    if (is_null($response_content)) {
      $this->fail('Failed to parse JSON response');
    }

    // Verify response content is an array and has expected keys.
    $this->assertIsArray($response_content);
    $this->assertArrayHasKey('version', $response_content);
    $this->assertArrayHasKey('title', $response_content);
    $this->assertArrayHasKey('html', $response_content);
    $this->assertArrayHasKey('width', $response_content);
    $this->assertArrayHasKey('height', $response_content);
    $this->assertArrayHasKey('meta_tags', $response_content);

    // Update the node title and verify the updated
    // title content in the response.
    $node->setTitle('Comparing Oembed Title with Before');
    $node->save();
    $title_new = $node->getTitle();

    $this->drupalGet($url_path, ['query' => $query_params]);
    $data = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($title_new, $data);
  }

}
