(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.commerceRecurlyAdmin = {
    attach: function (context) {
      let adminForm = function () {

        const $toggler = $('#edit-configuration-recurly-js-checkout-use-recurly-module-creds');
        const $toggleTarget = $('#dependent-elements');
        let targetInputs = [];

        $toggleTarget.find('input').each(function () {
          targetInputs.push($(this));
        });

        const executeToggle = () => {
          const checked = $toggler.is(':checked');

          $.each(targetInputs, function () {
            $(this).attr({
              'required': !checked,
              'aria-required': !checked,
            })
          });

          if (checked === true) {
            return $toggleTarget.hide();
          }

          return $toggleTarget.show();
        };

        executeToggle();

        $toggler.on('change', function () {
          executeToggle();
        });
      };

      $('#commerce-payment-gateway-edit-form', context).once('commerce-recurly-payment-gateway-admin-form').each(adminForm);
    }
  };

}(jQuery, Drupal, drupalSettings));
