<?php

namespace Drupal\metatag_formatter\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'metatag_plain' formatter.
 *
 * @FieldFormatter(
 *   id = "metatag_plain",
 *   label = @Translation("Plain text metatags"),
 *   field_types = {
 *     "metatag"
 *   }
 * )
 */
class MetatagPlainFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $entity = $items->getEntity();

    foreach ($items as $delta => $item) {
      if (!empty($item->value)) {
        $metatags = unserialize($item->value);
        if (is_array($metatags) && isset($metatags['robots'])) {
          // Use Metatag Manager service to process tokens
          if (\Drupal::hasService('metatag.manager')) {
            // Get the metatag manager service
            $metatag_service = \Drupal::service('metatag.manager');
            
            // First get the metatags with tokens from the entity
            $metatags_token = $metatag_service->tagsFromEntityWithDefaults($entity);
            
            // Merge the field-specific metatags with the defaults
            foreach ($metatags as $key => $value) {
              $metatags_token[$key] = $value;
            }
            
            // Now generate the actual values from tokens
            $metatags = $metatag_service->generateTokenValues($metatags_token, $entity);
          }
          
          $robots = is_array($metatags['robots']) ? $metatags['robots']['value'] : $metatags['robots'];
          
          $elements[$delta] = [
            '#markup' => strpos($robots, 'noindex') !== false ? 'noindex' : '',
          ];
        }
      }
    }

    return $elements;
  }
}
