<?php
/**
 * Eklenti kaldırıldığında çalışır
 */

// WordPress çekirdek dosyası yüklenmemişse çık
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Seçenekleri sil
delete_option('scg_api_provider');
delete_option('scg_api_keys');
delete_option('scg_api_rotation');
delete_option('scg_keywords');
delete_option('scg_used_keywords');
delete_option('scg_post_category');
delete_option('scg_post_status');
delete_option('scg_auto_generation_enabled');
delete_option('scg_db_version');

// Scheduled events'leri temizle
wp_clear_scheduled_hook('scg_daily_content_generation');
wp_clear_scheduled_hook('scg_process_bulk_generation');

// Post meta verilerini temizle
global $wpdb;
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_scg_generated'));
$wpdb->delete($wpdb->postmeta, array('meta_key' => 'scg_source_urls'));
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_scg_attached_images'));
$wpdb->delete($wpdb->postmeta, array('meta_key' => 'scg_faq_jsonld'));

// Transient'ları temizle
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_scg_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_scg_%'");

// Önbelleği temizle
wp_cache_flush();
?>