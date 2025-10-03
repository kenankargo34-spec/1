<?php
/**
 * Plugin Name: My Custom Plugin
 * Plugin URI: https://example.com/my-custom-plugin
 * Description: Özel WordPress eklentisi - Kısa kodlar ve admin paneli ile gelir.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: my-custom-plugin
 * Domain Path: /languages
 */

// Doğrudan erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

// Eklenti sabitleri
define('MCP_VERSION', '1.0.0');
define('MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MCP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Ana eklenti sınıfı
 */
class MyCustomPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Eklentiyi başlat
     */
    public function init() {
        // Admin menü
        if (is_admin()) {
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        }
        
        // Frontend
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
        add_shortcode('custom_content', array($this, 'custom_content_shortcode'));
        
        // AJAX işlemleri
        add_action('wp_ajax_mcp_save_data', array($this, 'save_data'));
        add_action('wp_ajax_nopriv_mcp_save_data', array($this, 'save_data'));
    }
    
    /**
     * Eklenti aktifleştirildiğinde çalışır
     */
    public function activate() {
        $this->create_tables();
        add_option('mcp_settings', array(
            'title' => 'Varsayılan Başlık',
            'content' => 'Varsayılan içerik',
            'color' => '#3498db'
        ));
    }
    
    /**
     * Eklenti deaktif edildiğinde çalışır
     */
    public function deactivate() {
        // Temizlik işlemleri
    }
    
    /**
     * Veritabanı tablolarını oluştur
     */
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mcp_data';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            content text NOT NULL,
            date_created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Admin menüsü ekle
     */
    public function admin_menu() {
        add_options_page(
            'My Custom Plugin Ayarları',
            'My Custom Plugin',
            'manage_options',
            'my-custom-plugin',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin sayfası
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $settings = array(
                'title' => sanitize_text_field($_POST['title']),
                'content' => wp_kses_post($_POST['content']),
                'color' => sanitize_hex_color($_POST['color'])
            );
            update_option('mcp_settings', $settings);
            echo '<div class="notice notice-success"><p>Ayarlar kaydedildi!</p></div>';
        }
        
        $settings = get_option('mcp_settings');
        ?>
        <div class="wrap">
            <h1>My Custom Plugin Ayarları</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="title">Başlık</label>
                        </th>
                        <td>
                            <input type="text" id="title" name="title" value="<?php echo esc_attr($settings['title']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="content">İçerik</label>
                        </th>
                        <td>
                            <textarea id="content" name="content" rows="5" cols="50" class="large-text"><?php echo esc_textarea($settings['content']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="color">Renk</label>
                        </th>
                        <td>
                            <input type="color" id="color" name="color" value="<?php echo esc_attr($settings['color']); ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Kullanım</h2>
            <p>Bu eklentiyi kullanmak için aşağıdaki kısa kodu sayfanıza ekleyin:</p>
            <code>[custom_content]</code>
            
            <h2>Veri Ekle</h2>
            <div id="mcp-form">
                <input type="text" id="new-title" placeholder="Başlık" />
                <textarea id="new-content" placeholder="İçerik"></textarea>
                <button id="save-data" class="button button-primary">Kaydet</button>
            </div>
            <div id="mcp-message"></div>
        </div>
        <?php
    }
    
    /**
     * Admin scriptleri
     */
    public function admin_scripts($hook) {
        if ('settings_page_my-custom-plugin' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'mcp-admin-js',
            MCP_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            MCP_VERSION,
            true
        );
        
        wp_localize_script('mcp-admin-js', 'mcp_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mcp_nonce')
        ));
        
        wp_enqueue_style(
            'mcp-admin-css',
            MCP_PLUGIN_URL . 'assets/admin.css',
            array(),
            MCP_VERSION
        );
    }
    
    /**
     * Frontend scriptleri
     */
    public function frontend_scripts() {
        wp_enqueue_style(
            'mcp-frontend-css',
            MCP_PLUGIN_URL . 'assets/frontend.css',
            array(),
            MCP_VERSION
        );
    }
    
    /**
     * Shortcode işlevi
     */
    public function custom_content_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => '',
            'content' => ''
        ), $atts);
        
        $settings = get_option('mcp_settings');
        
        $title = !empty($atts['title']) ? $atts['title'] : $settings['title'];
        $content = !empty($atts['content']) ? $atts['content'] : $settings['content'];
        
        $output = '<div class="mcp-content-box" style="border-color: ' . esc_attr($settings['color']) . ';">';
        $output .= '<h3 class="mcp-title" style="color: ' . esc_attr($settings['color']) . ';">' . esc_html($title) . '</h3>';
        $output .= '<div class="mcp-content">' . wp_kses_post($content) . '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * AJAX veri kaydetme
     */
    public function save_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'mcp_nonce')) {
            wp_die('Güvenlik kontrolü başarısız.');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_data';
        
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'title' => $title,
                'content' => $content
            ),
            array('%s', '%s')
        );
        
        if ($result) {
            wp_send_json_success('Veri başarıyla kaydedildi!');
        } else {
            wp_send_json_error('Veri kaydedilemedi.');
        }
    }
}

// Eklentiyi başlat
new MyCustomPlugin();
?>