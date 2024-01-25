/**
 * @file
 * Contains EU Cookie settings.
 */

(function ($, Drupal, undefined) {
  Drupal.behaviors.cookie_consent_banner = {
    attach: function (context, settings) {
      $('#eu-cookie-compliance-admin-categories-form table#categories-order thead tr th').each(function () {
        if ($(this).text().includes('Checkbox default state')) {
          $(this).text('Category default state');
        }
      });
      $('body', context).on('click', '.eu-cookie-compliance-costomize-preferences-button', function () {
        $('.eu-cookie-compliance-categories-wrapper', context).toggle();
        $('.eu-cookie-compliance-categories-buttons .eu-cookie-compliance-save-preferences-button', context).show();
        $('.eu-cookie-compliance-categories-buttons .eu-cookie-compliance-costomize-preferences-button', context).hide();
        $('.eu-cookie-compliance-categories-wrapper', context).toggleClass('cookie-categories-visible');
      });

      // Categoty cookie accordion.
      $('body', context).on('click', '.eu-cookie-compliance-category-label-wrapper .category-accordion-icon', function () {
        $(this, context).parent().parent().toggleClass('active-accordion');
      });

      // Store action in cookie.
      $('body', context).on('click', '.agree-button.eu-cookie-compliance-default-button', function () {
        $.cookie('cookie-action', 'Accept All', { path: '/' });
      });
      $('body', context).on('click', '.agree-button.eu-cookie-compliance-secondary-button', function () {
        $.cookie('cookie-action', 'Accept All', { path: '/' });
      });
      $('body', context).on('click', '.decline-button.eu-cookie-compliance-default-button', function () {
        $.cookie('cookie-action', 'Reject All', { path: '/' });
      });
      $('body', context).on('click', '.decline-button.eu-cookie-compliance-secondary-button', function () {
        $.cookie('cookie-action', 'Reject All', { path: '/' });
      });
      $('body', context).on('click', '.eu-cookie-compliance-save-preferences-button', function () {
        $.cookie('cookie-action', 'Save Preferences', { path: '/' });
      });
      $('body', context).on('click', '.eu-cookie-compliance-default-button.eu-cookie-compliance-reject-button', function () {
        $.cookie('cookie-action', 'Reject All', { path: '/' });
      });
      $('body', context).on('click', '.eu-cookie-compliance-close-button', function () {
        $.cookie('cookie-action', 'Closed pop-up', { path: '/' });
      });
    }
  };
})(jQuery, Drupal);
