(function ($, Drupal) {
  Drupal.behaviors.jsonFormatter = {
    attach: function (context, settings) {
      $('.field-formatter-metatag-json', context).once('json-formatter').each(function () {
        var content = $(this).html();
        try {
          // Check if it's already valid JSON (for pre-processed content)
          JSON.parse(content);
          $(this).html('<pre class="json-formatter">' + content + '</pre>');
        } catch (e) {
          // If not valid JSON, leave it as is
        }
      });
    }
  };
})(jQuery, Drupal);
