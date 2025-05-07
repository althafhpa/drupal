<?php

namespace Drupal\metatag_formatter\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'metatag_all_plain' formatter.
 *
 * @FieldFormatter(
 *   id = "metatag_all_plain",
 *   label = @Translation("Plain text All metatags"),
 *   field_types = {
 *     "metatag"
 *   }
 * )
 */
class MetatagPlainAllFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'metatag_name' => 'robots',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['metatag_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Metatag name'),
      '#default_value' => $this->getSetting('metatag_name'),
      '#description' => $this->t('Enter the machine name of the metatag to display (e.g. robots, description, keywords)'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Displaying metatag: @name', ['@name' => $this->getSetting('metatag_name')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $metatag_name = $this->getSetting('metatag_name');
    $entity = $items->getEntity();

    foreach ($items as $delta => $item) {
      if (!empty($item->value)) {
        $metatags = unserialize($item->value);
        if (is_array($metatags) && isset($metatags[$metatag_name])) {
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
          
          $value = is_array($metatags[$metatag_name])
            ? $metatags[$metatag_name]['value']
            : $metatags[$metatag_name];

          $elements[$delta] = [
            '#markup' => strip_tags($value),
          ];
        }
      }
    }

    return $elements;
  }
}
