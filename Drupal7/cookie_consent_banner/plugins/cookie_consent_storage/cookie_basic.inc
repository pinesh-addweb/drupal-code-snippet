<?php

/**
 * @file
 * Basic consent storage.
 */

$plugin = array(
  'title' => t('Cookie Basic storage'),
  'cookie_consent_storage_callback' => 'cookie_consent_banner_store_consent_basic',
);

/**
 * Store record of consent in the database.
 */
function cookie_consent_banner_store_consent_basic($type) {
  $eu_cookie_settings = variable_get('eu_cookie_compliance', array());
  $page_url = str_replace($_SERVER['HTTP_ORIGIN'], '', $_SERVER['HTTP_REFERER']);
  $cookie_name = ($eu_cookie_settings['cookie_name']) ?? 'cookie-agreed';
  $cookie_version = $cookie_name . '-version';
  $cookie_categories = $cookie_name . '-categories';
  $consent_choice = (isset($_COOKIE[$cookie_categories])) ? $_COOKIE[$cookie_categories] : $_COOKIE[$cookie_name];
  $cookie_action = (isset($_COOKIE['cookie-action'])) ? $_COOKIE['cookie-action'] : 'Agreed';
  unset($_COOKIE['cookie-action']);
  $match = preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
  $banner_message = ($match) ? $eu_cookie_settings['mobile_popup_info']['value'] : $eu_cookie_settings['popup_info']['value'];

  db_insert('cookie_compliance_basic_consent')
    ->fields(array(
      'unique_key' => session_id(),
      'ip_address' => ip_address(),
      'timestamp' => time(),
      'page_url' => $page_url,
      'browser_agent' => $_SERVER['HTTP_USER_AGENT'],
      'consent_choice' => $consent_choice,
      'consent_method' => $cookie_action,
      'policy_version' => $_COOKIE[$cookie_version],
      'banner_message' => $banner_message,
    ))
    ->execute();

  return TRUE;
}
