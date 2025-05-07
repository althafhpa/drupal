<?php

namespace Drupal\metatag_formatter\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'metatag_all' formatter.
 *
 * @FieldFormatter(
 *   id = "metatag_all",
 *   label = @Translation("All metatags display"),
 *   field_types = {
 *     "metatag"
 *   }
 * )
 */
class MetatagAllFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $entity = $items->getEntity();

    foreach ($items as $delta => $item) {
      if (!empty($item->value)) {
        $metatags = unserialize($item->value);
        if (is_array($metatags)) {
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
          
          $formatted_tags = [];
          foreach ($metatags as $name => $tag_data) {
            if (is_array($tag_data) && isset($tag_data['value'])) {
              $formatted_tags[] = "$name: " . strip_tags($tag_data['value']);
            }
            else if (!is_array($tag_data) && !empty($tag_data)) {
              $formatted_tags[] = "$name: " . strip_tags($tag_data);
            }
          }

          $elements[$delta] = [
            '#markup' => implode(', ', $formatted_tags),
          ];
        }
      }
    }

    return $elements;
  }
}