/**
 * @file
 * Handles export button refresh functionality.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.entityDisplayJsonExport = {
    attach: function (context, settings) {
      once('export-handler', '.export-with-refresh', context).forEach(function (element) {
        element.addEventListener('click', function(e) {
          setTimeout(function() {
            window.location.reload();
          }, 2000);
        });
      });
    }
  };
})(jQuery, Drupal, once);