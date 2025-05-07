<?php

namespace Drupal\entity_display_json\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns a JSON response with site information.
 */
class EntityDisplayJsonInfo extends ControllerBase {

  /**
   * The site configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $siteConfig;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new EntityDisplayJsonController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, LanguageManagerInterface $languageManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->siteConfig = $configFactory;
    $this->languageManager = $languageManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns a JSON response with site information.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getInfo() {
    $siteName = $this->siteConfig->get('system.site')->get('name');
    $siteSlogan = $this->siteConfig->get('system.site')->get('slogan');
    $homepageUrl = $this->siteConfig->get('system.site')->get('page.front');
    $defaultLanguage = $this->languageManager->getDefaultLanguage()->getId();
    $enabledLanguages = $this->languageManager->getLanguages();
    $languages = [];
    $languagePrefixes = $this->siteConfig->get('language.negotiation')->get('url.prefixes');
    foreach ($enabledLanguages as $languageCode => $language) {
      $label = $language->getName();
      $path = $languagePrefixes[$languageCode] ?? NULL;
      $languages[$languageCode] = [
        'label' => $label,
        'path' => $path,
      ];
    }
    $response = [
      'apiVersion' => '1.0',
      'site_name' => $siteName,
      'site_slogan' => $siteSlogan,
      'default_language' => $defaultLanguage,
      'languages' => $languages,
    ];
    $url = Url::fromUri('internal:' . $homepageUrl);
    if ($url->isRouted()) {
      $route_name = $url->getRouteName();
      $parts = explode('.', $route_name);
      if ($parts[0] === 'entity' && isset($parts[1]) && $this->entityTypeManager->hasDefinition($parts[1])) {
        $entityType = $parts[1];
      }
    }

    $urlArray = explode('/', $homepageUrl);
    $urlParts = array_slice($urlArray, -2, 2);
    if (isset($entityType) && isset($urlParts[1]) && is_numeric($urlParts[1])) {
      $entityId = $urlParts[1];
      $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
      if ($entity) {
        $uuid = $entity->uuid();
        $response['homepage_uuid'] = $uuid;
        $response['homepage_entity_type'] = $entityType;
      }
    }
    return new JsonResponse($response);
  }

}
