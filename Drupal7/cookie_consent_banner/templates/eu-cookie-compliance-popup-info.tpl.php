<?php

/**
 * @file
 * Template file for consent banner.
 *
 * When overriding this template it is important to note that jQuery will use
 * the following classes to assign actions to buttons:
 *
 * agree-button      - agree to setting cookies
 * find-more-button  - link to an information page
 *
 * Variables available:
 * - $message:  Contains the text that will be display within the pop-up
 * - $agree_button: Label for the primary/agree button. Note that this is the
 *   primary button. For backwards compatibility, the name remains agree_button.
 * - $disagree_button: Contains Cookie policy button title. (Note: for
 *   historical reasons, this label is called "disagree" even though it just
 *   displays the privacy policy.)
 * - $secondary_button_label: Contains the action button label. The current
 *   action depends on whether you're running the module in Opt-out or Opt-in
 *   mode.
 * - $primary_button_class: Contains class names for the primary button.
 * - $secondary_button_class: Contains class names for the secondary button
 *   (if visible).
 * - $cookie_categories: Contains a array with cookie categories that can be
 *   agreed or disagreed to separately.
 * - $save_preferences_button_label: Label text for a button to save the consent
 *   preferences.
 *   consent category cannot be unchecked.
 * - $privacy_settings_tab_label: Label text for the Privacy settings tab.
 * - $withdraw_button_on_info_popup: Show the withdraw button on this popup.
 * - $method: Chosen consent method.
 */
?>
<?php if ($privacy_settings_tab_label) : ?>
  <button type="button" class="eu-cookie-withdraw-tab"><?php print $privacy_settings_tab_label; ?></button>
<?php endif ?>
<?php $classes = array(
  'eu-cookie-compliance-banner',
  'eu-cookie-compliance-banner-info',
  'eu-cookie-compliance-banner--' . str_replace('_', '-', $method),
); ?>
<div class="<?php print implode(' ', $classes); ?>">
  <div class="popup-content info">
    <?php if ($close_button_enabled) : ?>
      <button class='eu-cookie-compliance-close-button'>Close</button>
    <?php endif; ?>
    <div id="popup-text">
      <?php print $message ?>
      <?php if ($disagree_button) : ?>
        <button type="button" class="find-more-button eu-cookie-compliance-more-button"><?php print $disagree_button; ?>
        </button>
      <?php endif; ?>
    </div>
    <?php if ($cookie_categories) : ?>
      <div id="eu-cookie-compliance-categories" class="eu-cookie-compliance-categories">
        <div class="eu-cookie-compliance-categories-wrapper accordion">
          <?php foreach ($cookie_categories as $key => $category) { ?>
            <div class="eu-cookie-compliance-category accordion-container">

              <div class="eu-cookie-compliance-category-label-wrapper">
                <span class="category-accordion-icon">
                  <label class="cookie-category-<?php print drupal_html_class($key); ?>" for="cookie-category-<?php print drupal_html_class($key); ?>"><?php print filter_xss($category['label']); ?></label>
                </span>
                <?php if ($category['checkbox_default_state'] === 'required') { ?>
                  <input type="checkbox" class="ccb-categories" name="cookie-categories" id="cookie-category-<?php print drupal_html_class($key); ?>" value="<?php print $key; ?>" checked disabled>
                  <label class="required-cookie" for="<?php print $key; ?>"><span>Always Active</span></label>
                <?php }
                else { ?>
                  <label class="switch">
                    <input type="checkbox" class="ccb-categories" name="cookie-categories" id="cookie-category-<?php print drupal_html_class($key); ?>" value="<?php print $key; ?>" <?php if ($category['checkbox_default_state'] === 'checked') : ?>checked<?php
                   endif; ?>>
                      <span class="slider round"></span>
                  </label>
                <?php } ?>
              </div>

              <?php if (isset($category['description'])) : ?>
                <div class="eu-cookie-compliance-category-description"><?php print filter_xss($category['description']) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php } ?>
        </div>

        <?php if ($save_preferences_button_label) : ?>
          <div class="eu-cookie-compliance-categories-buttons">
            <button type="button" class="eu-cookie-compliance-save-preferences-button"><?php print $save_preferences_button_label; ?></button>
          </div>
        <?php endif; ?>
      </div>
      <!-- Customize cookie button -->
      <?php $eu_ccb_settings = variable_get('eu_ccb_settings', []); ?>
      <div class="eu-cookie-compliance-categories-buttons">
        <button type="button" class="eu-cookie-compliance-costomize-preferences-button"><?php print $eu_ccb_settings['customize_cookie_preferences_button_label']; ?></button>
      </div>
    <?php endif; ?>
    <div id="popup-buttons" class="<?php if ($cookie_categories) : ?>eu-cookie-compliance-has-categories<?php
   endif; ?>">
      <?php if ($tertiary_button_label) : ?>
        <button type='button' class='<?php print $tertiary_button_class ?>'><?php print $tertiary_button_label ?>
        </button>
      <?php endif; ?>
      <button type="button" class="<?php print $primary_button_class; ?>"><?php print $agree_button; ?></button>
      <?php if ($secondary_button_label) : ?>
        <button type="button" class="<?php print $secondary_button_class; ?>" ><?php print $secondary_button_label; ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>
