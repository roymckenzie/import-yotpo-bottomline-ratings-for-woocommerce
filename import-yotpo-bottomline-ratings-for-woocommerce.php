<?php

defined( 'ABSPATH' ) or die( 'Just what do you think you\'re doing?' );

/*
Plugin Name:  Import Yotpo Bottomline Ratings for WooCommerce
Description:  Import Yotpo Bottomline ratings to your WooCommerce products.
Version:      0.1.0
Author:       Roy McKenzie
Author URI:   https://wordpresshelpwanted.com/
License:      MIT
License URI:  https://opensource.org/licenses/MIT
*/


/**
* Import Yotpo Bottomline Ratings class holds all the logic for the app
*/
class ImportYotpoBottomlineRatings
{

  // URI for getting API key / secret
  private $yotpo_api_dashboard_uri = 'https://yap.yotpo.com/#/header/account_settings/store_settings';
  private $frequencies = array(
    0 => 'off',
    1 => 'daily',
    2 => 'twicedaily',
    3 => 'hourly'
  );

  function __construct() {
    // Register Activation Hook for plugin
    register_activation_hook( __FILE__, array( $this, 'iybr_activate' ) );
    // Register deactivation hook for plugin
    register_deactivation_hook( __FILE__, array( $this, 'iybr_deactivate' ) );
    // Add action for admin menu
    add_action( 'admin_menu', array( $this, 'iybr_options_page' ) );
    // Add action for CRON
    add_action( 'iybr_import_hook', array( $this, 'iybr_import_ratings') );
    add_action( 'iybr_import_once_hook', array( $this, 'iybr_import_ratings') );
    // Register settings
    add_action( 'admin_init', array( $this, 'iybr_register_yotpo_settings' ) );
    add_action( 'admin_init', array( $this, 'iybr_register_general_settings' ) );

    // Register meta fields for products
    $this->iybr_register_meta();

    if ( !$this->has_api_settings() ) {
      update_option( 'iybr-frequency', 0 );
    }

    if ( !$this->import_enabled_and_has_api_settings() ) {
      $this->iybr_cancel_scheduled_import();
    } else {
      $this->iybr_schedule_import();
    }
  }

  // Registers meta values on WooCommerce products
  function iybr_register_meta() {
    register_meta( 'product',
      'yotpo_product_score', 
      array(
        'type' => 'number',
        'description' => 'YOTPO average rating.',
        'show_in_rest' => true
      )
    );

    register_meta( 'product',
      'yotpo_total_reviews', 
      array(
        'type' => 'integer',
        'description' => 'YOTPO average rating.',
        'show_in_rest' => true
      )
    );
  }

  function import_enabled() {
    return get_option( 'iybr-frequency' ) > 0;
  }

  function has_api_settings() {
    $api_key      = get_option( 'iybr-yotpo_api_key' );
    $api_secret   = get_option( 'iybr-yotpo_api_secret' );

    if ( empty($api_key) || empty($api_secret) ) {
      return false;
    }

    return true;
  }

  function import_enabled_and_has_api_settings() {
    if ( !$this->import_enabled() || !$this->has_api_settings() ) {
      return false;
    }

    return true;
  }

  // Activation hook for plugin
  function iybr_activate() {
    $this->iybr_schedule_import();
  }

  // Deactivation hook for plugin
  function iybr_deactivate() {
    $this->iybr_cancel_scheduled_import();
  }

  // Import logic
  function iybr_import_ratings( $page = 1 ) {
    // Amount of bottomlines to ask per query
    $count        = 100;

    // Resuest bottomlines from yotpo
    $yotpo_result = $this->get_yotpo_bottomlines( $page, $count, true );

    // Parse out bottomlines
    $bottomlines  = $yotpo_result->response->bottomlines;

    // Count results
    $result_count = count( $bottomlines );

    // If there aren't any results, forget it!
    if ( empty( $bottomlines ) ) { return; }
    
    // Save bottomlines to each product
    $this->process_bottomlines( $bottomlines );

    // If the count of results returned is less than the count asked for, then it's done!
    if ( $result_count < $count ) { 
      update_option( 'finished_recent_import', true );
      return;
    }
    
    // Continue asking for ratings
    $this->iybr_import_ratings( $page+1 );
  }

  // Save bottomlines to each product
  function process_bottomlines( $bottomlines ) {
    foreach ($bottomlines as $bottomline) {
      update_post_meta( $bottomline->domain_key, 'yotpo_product_score', $bottomline->product_score );
      update_post_meta( $bottomline->domain_key, 'yotpo_total_reviews', $bottomline->total_reviews );
    }
  }

  // Schedule CRON for import action
  function iybr_schedule_import() {
    $current_scheduled_frequency = wp_get_schedule( 'iybr_import_hook' );
    $frequency_key = get_option( 'iybr-frequency' );
    $frequency_value = $this->frequencies[$frequency_key];

    $schedules_match = $current_scheduled_frequency == $frequency_value;

    if ( ( !wp_next_scheduled( 'iybr_import_hook' ) || !$schedules_match ) && $this->import_enabled() ) {
      $this->iybr_cancel_scheduled_import();
      wp_schedule_event( time(), $frequency_value, 'iybr_import_hook' );
    }
  }

  // Schedule CRON for import action
  function iybr_schedule_import_once() {
    wp_schedule_single_event( time(), 'iybr_import_once_hook');
  }

  // Clear CRON for import action
  function iybr_cancel_scheduled_import() {
    wp_clear_scheduled_hook('iybr_import_hook');
  }

  // IYBR options page HTML
  function iybr_options_page_html() {
    // Check if user role has access
    if ( !current_user_can('manage_options') ) { return; }

    // Actions for Import now
    if ( isset( $_GET['import_now'] ) && boolval( $_GET['import_now'] ) == true ) {
      $this->iybr_cancel_scheduled_import();
      $this->iybr_schedule_import_once();
      $this->iybr_notice_started_import();
    }

    if ( boolval( get_option( 'finished_recent_import' ) ) ) {
      update_option( 'finished_recent_import', false );
      $this->iybr_notice_completed_import();
    }

    if ( !$this->has_api_settings() ) {
      $this->iybr_notice_need_api_settings();
    }

    ?>

      <div class="wrap">
        <h1><?= esc_html( get_admin_page_title() ); ?></h1>
        <form method="post" action="<?= esc_url( admin_url( 'options.php' ) ) ?>"> 
          <?= settings_fields('iybr-settings'); ?>
          <?= do_settings_sections('iybr-settings-page'); ?>
          <?= submit_button('Save Settings', 'primary', null, false); ?>
          <?php if ( $this->has_api_settings() ) { ?>
            <a class="button" href="<?php menu_page_url( 'iybr-settings-page' ); ?>&import_now=true">Import Now</a>
          <?php } ?>
        </form>
      </div>

    <?php

  }

  // Add submenu to Settings submenu
  function iybr_options_page() {

    add_submenu_page( 'woocommerce', 
      'Import Yotpo Bottomline Ratings Settings', 
      'Import Yotpo Ratings', 
      'manage_options', 
      'iybr-settings-page', 
      array( $this, 'iybr_options_page_html' )
    );
  }

  // For Yotpo settings
  function iybr_register_yotpo_settings() {
    // Register API Key/Secret 
    register_setting( 'iybr-settings', 
      'iybr-yotpo_api_key', 
      array(
        'type'                => 'string',
        'description'         => 'Yotpo API Key',
        'sanitize_callback'   => 'sanitize_text_field'
      )
    );
    register_setting( 'iybr-settings', 
      'iybr-yotpo_api_secret', 
      array(
        'type'                => 'string',
        'description'         => 'Yotpo API Secret',
        'sanitize_callback'   => 'sanitize_text_field'
      )
    );

    // Add Section for yotpo
    add_settings_section( 'iybr-yotpo_api_settings-section', 
      'Yotpo API Settings', 
      array( $this, 'iybr_yotpo_api_settings_cb'), 
      'iybr-settings-page'
    );

    add_settings_field( 'iybr-yotpo_api_key', 
      'API Key', 
      array( $this, 'iybr_yotpo_api_key_field_cb'), 
      'iybr-settings-page',
      'iybr-yotpo_api_settings-section'
    );

    add_settings_field( 'iybr-yotpo_api_secret', 
      'API Secret', 
      array( $this, 'iybr_yotpo_api_secret_field_cb'), 
      'iybr-settings-page',
      'iybr-yotpo_api_settings-section'
    );
  }

  function iybr_yotpo_api_settings_cb() {
    if ( !$this->has_api_settings() ) {
      echo "<p>To start importing Yotpo Bottomline reviews for your products, we'll need your Yotpo API Key and API Secret.</p><p>You can retrieve your Yotpo API Key and API Secret pair by visiting the <em><a href=\"$this->yotpo_api_dashboard_uri\" target=\"_blank\">Store tab</a></em> in <em>Account Settings</em> in your Yotpo Dashboard.</p>";
    }
  }

  function iybr_yotpo_api_key_field_cb() {
    $setting  = get_option( 'iybr-yotpo_api_key' );
    ?>
    <input type="text" name="iybr-yotpo_api_key" id="iybr-yotpo_api_key" value="<?= $setting; ?>" class="regular-text code">
    <?php
  }

  function iybr_yotpo_api_secret_field_cb() {
    $setting = get_option( 'iybr-yotpo_api_secret' );
    ?>
    <input type="text" name="iybr-yotpo_api_secret" id="iybr-yotpo_api_secret" value="<?= $setting; ?>" class="regular-text code">
    <?php
  }

  function iybr_register_general_settings() {
    // Register enabled status and frequency (once a day, twice a day, hourly)
    register_setting( 'iybr-settings', 
      'iybr-frequency', 
      array(
        'type'                => 'number',
        'description'         => 'Frequency',
        'sanitize_callback'   => 'sanitize_text_field',
        'default'             => 0
      )
    );

    if ( !$this->has_api_settings() ) { return; }

    // Add Section for general settings
    add_settings_section( 'iybr-import-section', 
      'Import Settings', 
      array( $this, 'iybr_general_settings_cb'), 
      'iybr-settings-page'
    );

    add_settings_field( 'iybr-frequency', 
      'Frequency', 
      array( $this, 'iybr_frequency_field_cb'), 
      'iybr-settings-page',
      'iybr-import-section'
    );

  }

  function iybr_general_settings_cb() {
    echo '<p>How often should the importer run?</p>';
  }

  function iybr_frequency_field_cb() {
    $setting = get_option('iybr-frequency');
    $next_update = wp_next_scheduled( 'iybr_import_hook' );
    $friendly_next_update = empty($next_update) ? '' : 'Next import: ' . human_time_diff( $next_update );
    ?>
    <select name="iybr-frequency" id="iybr-frequency">
      <option value="0" <?= selected(0, $setting) ?>>Never</option>
      <option value="1" <?= selected(1, $setting) ?>>Daily</option>
      <option value="2" <?= selected(2, $setting) ?>>Twice Daily</option>
      <option value="3" <?= selected(3, $setting) ?>>Hourly</option>
    </select>
    <p class="description"><?= $friendly_next_update ?></p>
    <?php

  }

  function iybr_notice_started_import() {
    echo '<div class="notice notice-info is-dismissible"><p>Importing Yotpo ratings...</p></div>';
  }
  
  function iybr_notice_completed_import() {
    echo '<div class="notice notice-success is-dismissible"><p>Finished importing Yotpo ratings.</p></div>';
  }

  function iybr_notice_need_api_settings() {
    echo '<div class="notice notice-info"><p>Please add your API Settings to enabled the importer.</p></div>';
  }

  function get_yotpo_bottomlines( $page = 1, $count = 100, $decode = false ) {

    // API key / secret
    $api_key      = get_option( 'iybr-yotpo_api_key' );
    $api_secret   = get_option( 'iybr-yotpo_api_secret' );

    // Create the API URL. 
    $api_url = "https://api.yotpo.com/v1/apps/$api_key/bottom_lines?count=$count&page=$page&utoken=$api_secret";

    // Setup cURL
    $curl_session = curl_init(); 
    curl_setopt( $curl_session, CURLOPT_URL, $api_url ); 
    curl_setopt( $curl_session, CURLOPT_TIMEOUT, 10 ); 
    curl_setopt( $curl_session, CURLOPT_RETURNTRANSFER, 1 ); 

    // Execute cURL request
    $result = curl_exec( $curl_session ); 

    // Print out errors if there are any and if debug is set to true
    if ( curl_error( $curl_session ) && $debug ) {
      print(curl_error( $curl_session ));
      print(curl_errno( $curl_session ));
      print_r(curl_getinfo( $curl_session ));
    }

    // Close cURL request
    curl_close( $curl_session );

    // if $decode is true then send a decoded result
    // good if you want to read the result in PHP
    // otherwise leave it in JSON format for reading
    // by javascript
    if ( $decode ) {
      return json_decode( $result, false );
    }

    // return the JSON encoded response
    return $result;
  }

}

new ImportYotpoBottomlineRatings;
