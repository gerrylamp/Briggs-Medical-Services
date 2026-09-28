<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'pph_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'pph_submissions';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'pph_settings' );
delete_option( 'pph_forms' );
delete_option( 'pph_db_version' );
delete_option( 'pph_webhook_health' );
delete_option( 'pph_portal_page_id' );
delete_option( 'pph_login_page_id' );
delete_option( 'pph_registration_page_id' );
delete_option( 'pph_plugin_version' );

// Remove only verification metadata created by this plugin.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_pph_email_verified','_pph_verify_token','_pph_verify_expires')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

remove_role( 'patient_portal_patient' );
