<?php

defined( 'ABSPATH' ) or die( 'Just what do you think you\'re doing?' );

/*
Plugin Name:  Import Yotpo Bottomline Ratings for WooCommerce
Plugin URI:   https://roymckenzie.me/import-yotpo-bottomline-ratings-for-woocommerce/
Description:  Import Yotpo Bottomline ratings to your WooCommerce products.
Version:      0.1.0
Author:       Roy McKenzie
Author URI:   https://roymckenzie.me/
License:      GNU General Public License v2 or later
License URI:  http://www.gnu.org/licenses/gpl-2.0.html

Import Yotpo Bottomline Ratings for WooCommerce is free software: you 
can redistribute it and/or modify it under the terms of the 
GNU General Public License as published by the Free Software Foundation, 
either version 2 of the License, or any later version.
 
Import Yotpo Bottomline Ratings for WooCommerce is distributed 
in the hope that it will be useful, but WITHOUT ANY WARRANTY; 
without even the implied warranty of MERCHANTABILITY or FITNESS 
FOR A PARTICULAR PURPOSE. See the GNU General Public License 
for more details.
 
You should have received a copy of the GNU General Public License
along with Import Yotpo Bottomline Ratings for WooCommerce. If not, 
see http://www.gnu.org/licenses/gpl-2.0.html.
*/


/**
* Import_Yotpo_Bottomline_Ratings class holds all the logic for the app
*/
class Import_Yotpo_Bottomline_Ratings {

  // URI for getting API key / secret
  private $yotpo_api_dashboard_uri = 'https://yap.yotpo.com/#/header/account_settings/store_settings';
  private $frequencies = array(
    0 => 'off',
    1 => 'daily',
    2 => 'twicedaily',
    3 => 'hourly'
  );

  private function __construct() {

    // Register activation hook for plugin
    register_activation_hook( __FILE__, array( $this, 'activation' ) );

    // Register deactivation hook for plugin
    register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

    // Do initial setup tasks and checks
    add_action( 'admin_init', array( $this, 'admin_init' ) );

    // Add action for admin menu
    add_action( 'admin_menu', array( $this, 'admin_menu' ) );

    // Add action for CRON
    add_action( 'import_bottomlines_cron_hook', array( $this, 'import_bottomlines' ) );
    add_action( 'import_bottomlines_cron_once_hook', array( $this, 'import_bottomlines' ) );

    // Add plugin action link for settings page
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
  }

  // Initial checks and setup
  function admin_init() {
    $this->check_plugin_prerequisites();
    $this->register_general_settings();
    $this->register_yotpo_settings();
    $this->schedule_import_cron();
  }

  // Activation hook for plugin
  function activation() {
    $this->check_plugin_prerequisites( true );
    $this->schedule_import_cron();
    $this->register_meta();
  }

  // Deactivation hook for plugin
  function deactivation() {
    $this->cancel_scheduled_import_cron();
  }

  // Check if WooCommerce is activated
  private function is_woocommerce_active() {
    if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
      return true;
    }

    return false;
  }

  private function check_plugin_prerequisites( $is_activation = false ) {
    if ( ! $this->is_woocommerce_active() ) {
      deactivate_plugins( plugin_basename( __FILE__ ) );

      if ( $is_activation ) {
        wp_die( 'WooCommerce must be activated to use Import Yotpo Bottomline Ratings for WooCommerce.' );
      } else {
        add_action( 'admin_notices', array( $this, 'admin_notice_need_woocommerce' ), 10, 1 );
      }
    }
  }

  // Add Settings link to Plugin page row for this plugin
  function plugin_action_links( $links ) {
    $links = array_merge(
      array(
        '<a href="' . admin_url( 'admin.php?page=iybr-settings-page' ) . '">Settings</a>'
      ),
      $links
    );
    return $links;
  }

  // Registers meta values on WooCommerce products
  private function register_meta() {
    register_meta( 'product',
      'yotpo_product_score', 
      array(
        'type' => 'number',
        'description' => 'Yotpo Product Score.',
        'show_in_rest' => true
      )
    );

    register_meta( 'product',
      'yotpo_total_reviews', 
      array(
        'type' => 'integer',
        'description' => 'Yotpo Total Reviews.',
        'show_in_rest' => true
      )
    );
  }

  // Check if import is enabled
  private function import_enabled() {
    return get_option( 'iybr-frequency' ) > 0;
  }

  // Set import frequency to never
  private function set_import_frequency_to_never() {
    update_option( 'iybr-frequency', 0 );
  }

  private function has_yotpo_api_settings() {
    $api_key      = get_option( 'iybr-yotpo_api_key' );
    $api_secret   = get_option( 'iybr-yotpo_api_secret' );

    if ( empty( $api_key ) || empty( $api_secret ) ) {
      $this->set_import_frequency_to_never();
      return false;
    }

    return true;
  }

  private function import_enabled_and_has_yotpo_api_settings() {
    if ( ! $this->import_enabled() || ! $this->has_yotpo_api_settings() ) {
      return false;
    }

    return true;
  }

  // Import logic
  function import_bottomlines( $page = 1 ) {
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
      update_option( 'iybr-finished_recent_import', true );
      return;
    }
    
    // Continue asking for ratings
    $this->import_bottomlines( $page+1 );
  }

  // Save bottomlines to each product
  private function process_bottomlines( $bottomlines ) {
    foreach ( $bottomlines as $bottomline ) {
      update_post_meta( $bottomline->domain_key, 'yotpo_product_score', $bottomline->product_score );
      update_post_meta( $bottomline->domain_key, 'yotpo_total_reviews', $bottomline->total_reviews );
    }
  }

  // Schedule CRON for import action
  private function schedule_import_cron() {
    // If import disabled or there is no Yotpo API settings, cancel cron
    if ( ! $this->import_enabled_and_has_yotpo_api_settings() ) {
      $this->cancel_scheduled_import_cron();
      return;
    }

    $current_scheduled_frequency = wp_get_schedule( 'import_bottomlines_cron_hook' );
    $frequency_key = get_option( 'iybr-frequency' );
    $frequency_value = $this->frequencies[ $frequency_key ];

    $schedules_match = $current_scheduled_frequency == $frequency_value;

    if ( ( ! wp_next_scheduled( 'import_bottomlines_cron_hook' ) || ! $schedules_match ) && $this->import_enabled() ) {
      $this->cancel_scheduled_import_cron();
      wp_schedule_event( time(), $frequency_value, 'import_bottomlines_cron_hook' );
    }
  }

  // Schedule CRON for import action
  private function schedule_import_once() {
    wp_schedule_single_event( time(), 'import_bottomlines_cron_once_hook' );
  }

  // Clear CRON for import action
  private function cancel_scheduled_import_cron() {
    wp_clear_scheduled_hook( 'import_bottomlines_cron_hook' );
  }

  // IYBR options page HTML
  function options_page_html() {
    // Check if user role has access
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    // Actions for Import now
    if ( isset( $_GET['import_now'] ) && boolval( $_GET['import_now'] ) == true ) {
      $this->cancel_scheduled_import_cron();
      $this->schedule_import_once();
      $this->notice_started_import();
    }

    if ( boolval( get_option( 'iybr-finished_recent_import' ) ) ) {
      update_option( 'iybr-finished_recent_import', false );
      $this->notice_completed_import();
    }

    if ( ! $this->has_yotpo_api_settings() ) {
      $this->notice_need_api_settings();
    }

    ?>

      <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ) ?>"> 
          <?php settings_fields( 'iybr-settings' ); ?>
          <?php do_settings_sections( 'iybr-settings-page' ); ?>
          <?php submit_button( 'Save Settings', 'primary', null, false ); ?>
          <?php if ( $this->has_yotpo_api_settings() ) { ?>
            <a class="button" href="<?php menu_page_url( 'iybr-settings-page' ); ?>&import_now=true">Import Now</a>
          <?php } ?>
        </form>
      </div>

    <?php

  }

  // Add submenu to Settings submenu
  function admin_menu() {

    add_submenu_page( 'woocommerce', 
      'Import Yotpo Bottomline Ratings Settings', 
      'Import Yotpo Ratings', 
      'manage_options', 
      'iybr-settings-page', 
      array( $this, 'options_page_html' )
    );
  }

  // For Yotpo settings
  private function register_yotpo_settings() {
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
      array( $this, 'yotpo_api_settings_section_cb' ), 
      'iybr-settings-page'
    );

    add_settings_field( 'iybr-yotpo_api_key', 
      'API Key', 
      array( $this, 'yotpo_api_key_settings_field_cb' ), 
      'iybr-settings-page',
      'iybr-yotpo_api_settings-section'
    );

    add_settings_field( 'iybr-yotpo_api_secret', 
      'API Secret', 
      array( $this, 'yotpo_api_secret_settings_field_cb' ), 
      'iybr-settings-page',
      'iybr-yotpo_api_settings-section'
    );
  }

  function yotpo_api_settings_section_cb() {
    if ( ! $this->has_yotpo_api_settings() ) {
      echo "<p>To start importing Yotpo Bottomline reviews for your products, we'll need your Yotpo API Key and API Secret.</p><p>You can retrieve your Yotpo API Key and API Secret pair by visiting the <em><a href=\"$this->yotpo_api_dashboard_uri\" target=\"_blank\">Store tab</a></em> in <em>Account Settings</em> in your Yotpo Dashboard.</p>";
    }
  }

  function yotpo_api_key_settings_field_cb() {
    $setting  = get_option( 'iybr-yotpo_api_key' );
    ?>
    <input type="text" name="iybr-yotpo_api_key" id="iybr-yotpo_api_key" value="<?php echo $setting; ?>" class="regular-text code">
    <?php
  }

  function yotpo_api_secret_settings_field_cb() {
    $setting = get_option( 'iybr-yotpo_api_secret' );
    ?>
    <input type="text" name="iybr-yotpo_api_secret" id="iybr-yotpo_api_secret" value="<?php echo $setting; ?>" class="regular-text code">
    <?php
  }

  private function register_general_settings() {
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

    if ( ! $this->has_yotpo_api_settings() ) { return; }

    // Add Section for general settings
    add_settings_section( 'iybr-import-section', 
      'Import Settings', 
      array( $this, 'general_settings_section_cb' ), 
      'iybr-settings-page'
    );

    add_settings_field( 'iybr-frequency', 
      'Frequency', 
      array( $this, 'frequency_settings_field_cb' ), 
      'iybr-settings-page',
      'iybr-import-section'
    );

  }

  function general_settings_section_cb() {
    echo '<p>How often should the importer run?</p>';
  }

  function frequency_settings_field_cb() {
    $setting = get_option( 'iybr-frequency', 1 );
    $next_update = wp_next_scheduled( 'import_bottomlines_cron_hook' );

    if ( ! empty( $next_update ) && $next_update < time() ) {
      $friendly_next_update = "Next import: Just now.";
    } else {
      $friendly_next_update = empty( $next_update ) ? '' : 'Next import: ' . human_time_diff( $next_update );
    }
    
    ?>
    <select name="iybr-frequency" id="iybr-frequency">
      <option value="0" <?php selected(0, $setting) ?>>Never</option>
      <option value="1" <?php selected(1, $setting) ?>>Daily</option>
      <option value="2" <?php selected(2, $setting) ?>>Twice Daily</option>
      <option value="3" <?php selected(3, $setting) ?>>Hourly</option>
    </select>
    <p class="description"><?php echo $friendly_next_update ?></p>
    <?php

  }

  private function notice_started_import() {
    echo '<div class="notice notice-info is-dismissible"><p>Importing Yotpo ratings...</p></div>';
  }
  
  private function notice_completed_import() {
    echo '<div class="notice notice-success is-dismissible"><p>Finished importing Yotpo ratings.</p></div>';
  }

  private function notice_need_api_settings() {
    echo '<div class="notice notice-info"><p>Please add your API Settings to enabled the importer.</p></div>';
  }

  function admin_notice_need_woocommerce() {
    echo '<div class="notice notice-error"><p>WooCommerce must be activated to use Import Yotpo Bottomline Ratings for WooCommerce.</p></div>';
  }

  private function get_yotpo_bottomlines( $page = 1, $count = 100, $decode = false ) {

    // API key / secret
    $api_key      = get_option( 'iybr-yotpo_api_key' );
    $api_secret   = get_option( 'iybr-yotpo_api_secret' );

    // Create the API URL. 
    $api_url = "https://api.yotpo.com/v1/apps/$api_key/bottom_lines?count=$count&page=$page&utoken=$api_secret";

    $response = wp_remote_get( $api_url );

    $result = $response['body'];

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

new Import_Yotpo_Bottomline_Ratings;
