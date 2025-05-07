<?php

namespace Drupal\metatag_formatter\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to present metatag fields as JSON.
 *
 * @ViewsField("metatag_json_field")
 */
class MetatagJsonField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    if (!empty($value)) {
      $metatags = unserialize($value);
      if (is_array($metatags)) {
        return json_encode($metatags);
      }
    }
    return '';
  }

}
