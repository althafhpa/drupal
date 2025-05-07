<?php

namespace Drupal\metatag_formatter\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'metatag_json' formatter.
 *
 * @FieldFormatter(
 *   id = "metatag_json",
 *   label = @Translation("JSON metatags"),
 *   field_types = {
 *     "metatag"
 *   }
 * )
 */
class MetatagJsonFormatter extends FormatterBase {

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
          
          // Clean and prepare the data
          $clean_data = [];
          
          foreach ($metatags as $name => $tag_data) {
            if (is_array($tag_data) && isset($tag_data['value'])) {
              $clean_data[$name] = strip_tags($tag_data['value']);
            }
            else if (!is_array($tag_data) && !empty($tag_data)) {
              $clean_data[$name] = strip_tags($tag_data);
            }
          }

          // Add debug information in development environments
          if (\Drupal::state()->get('system.maintenance_mode') || 
              \Drupal::request()->query->get('debug') === 'tokens') {
            $clean_data['_debug'] = [
              'original_metatags' => $metatags,
              'entity_type' => $entity->getEntityTypeId(),
              'bundle' => $entity->bundle(),
            ];
          }

          // Decide on JSON encoding options
          $options = JSON_UNESCAPED_SLASHES;
          if ($this->getSetting('pretty_print')) {
            $options |= JSON_PRETTY_PRINT;
          }

          // Output as JSON
          $elements[$delta] = [
            '#markup' => json_encode($clean_data, $options),
            '#attached' => [
              'library' => [
                'metatag_formatter/json-formatter',
              ],
            ],
            '#prefix' => '<div class="field-formatter-metatag-json">',
            '#suffix' => '</div>',
          ];
        }
        else {
          $elements[$delta] = [
            '#markup' => json_encode(['error' => 'Invalid metatag format']),
            '#prefix' => '<div class="field-formatter-metatag-json">',
            '#suffix' => '</div>',
          ];
        }
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'pretty_print' => TRUE,
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    if ($this->getSetting('pretty_print')) {
      $summary[] = $this->t('JSON output: Pretty printed');
    }
    else {
      $summary[] = $this->t('JSON output: Compact');
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['pretty_print'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pretty print JSON'),
      '#description' => $this->t('If checked, JSON will be formatted with indentation and line breaks.'),
      '#default_value' => $this->getSetting('pretty_print'),
    ];

    return $form;
  }
}
