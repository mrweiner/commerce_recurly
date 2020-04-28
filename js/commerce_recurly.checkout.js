(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.recurlyJsForm = {
    attach: function (context) {
      let recurlyForm = function () {
        var data = JSON.parse(drupalSettings.commerce_recurly);
        var public_key = data.public_key;

        // Initialize recurlyjs with public key
        recurly.configure(public_key);

        // Get billing token before
        $('form.commerce-checkout-flow').on('submit', function (event) {
          event.preventDefault();

          let recurly_form = this;

          recurly.token(recurly_form, function (error, token) {
            if (error) {
              console.log('Recurly token error');
              console.log(error);
            }
            else {
              recurly_form.submit();
            }
          });
        });

      };

      $('.checkout-pane', context).once('recurly-js-form').each(recurlyForm);
    }
  };

}(jQuery, Drupal, drupalSettings));
