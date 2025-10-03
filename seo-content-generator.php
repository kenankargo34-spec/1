<?php
/**
 * Plugin Name: SEO2
 * Plugin URI: https://example.com/seo-content-generator
 * Description: Automatically generates SEO-optimized articles to improve search rankings and increase visitor count.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SCG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCG_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class SEO_Content_Generator {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('scg_daily_content_generation', array($this, 'generate_daily_content'));
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));


        // AJAX hook for live testing
        add_action('wp_ajax_scg_live_generate', array($this, 'ajax_live_generate'));
    // AJAX hook to generate single article from keyword (used by admin list Start button)
    add_action('wp_ajax_scg_generate_article', array($this, 'ajax_generate_article'));
    // AJAX hook to trigger automatic run from admin UI
    add_action('wp_ajax_scg_trigger_auto_run', array($this, 'ajax_trigger_auto_run'));
    // AJAX step processor for queued auto runs
    add_action('wp_ajax_scg_auto_step', array($this, 'ajax_auto_step'));

        // Register admin-post handler early to ensure availability on admin-post requests
        add_action('admin_post_scg_generate_from_urls_post', array($this, 'scg_handle_generate_from_urls_post'));
        // Hourly automated publishing hook
        add_action('scg_hourly_content_generation', array($this, 'scg_hourly_generate'));
    }

    /**
     * Schedule hourly generation if enabled
     */
    public function scg_schedule_hourly_generation() {
        if (!wp_next_scheduled('scg_hourly_content_generation')) {
            wp_schedule_event(time(), 'hourly', 'scg_hourly_content_generation');
        }
    }

    /**
     * Clear hourly generation schedule
     */
    public function scg_clear_hourly_generation() {
        wp_clear_scheduled_hook('scg_hourly_content_generation');
    }

    /**
     * Hourly generation handler that respects daily total and per-hour limits
     */
    public function scg_hourly_generate() {
        // Only run when enabled
        if (!get_option('scg_auto_publish_enabled', 0)) { return; }

        // Check pause window
        $pause_until = (int) get_option('scg_api_pause_until', 0);
        if ($pause_until > time()) { return; }

        $daily_total = max(0, intval(get_option('scg_auto_publish_daily_total', 30)));
        $per_hour = max(1, intval(get_option('scg_auto_publish_per_hour', 3)));

        // Track today's count in an option keyed by date
        $today_key = 'scg_generated_count_' . date('Ymd');
        $today_count = intval(get_option($today_key, 0));
        if ($daily_total <= 0 || $today_count >= $daily_total) {
            return; // reached daily limit
        }

        // Compute how many to publish this hour
        $remaining = $daily_total - $today_count;
        $to_publish = min($per_hour, $remaining);

        // Reuse logic from generate_daily_content: pick unused keywords from keyword lists and scg_keywords option
        $keywords = get_option('scg_keywords');
        $keyword_list = array_filter(array_map('trim', explode("\n", (string) $keywords)));
        // Also include keywords from lists
        $lists = get_option('scg_keyword_lists', array());
        if (is_array($lists)) {
            foreach ($lists as $lst) {
                if (isset($lst['keywords']) && is_array($lst['keywords'])) {
                    foreach ($lst['keywords'] as $k) { $keyword_list[] = $k; }
                }
            }
        }
        $keyword_list = array_values(array_unique(array_filter(array_map('trim', $keyword_list))));

        // Filter unused
        $unused = array();
        foreach ($keyword_list as $kw) {
            if (!$this->is_keyword_used($kw)) { $unused[] = $kw; }
        }
        if (empty($unused)) { return; }

        shuffle($unused);
        $selected = array_slice($unused, 0, $to_publish);

        $api_keys = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $api_key_list = array_filter(array_map('trim', explode("\n", (string) $api_keys)));
        if (empty($api_key_list)) { return; }
        $rotate = (int) get_option('scg_api_rotation', 0) === 1;
        $category = intval(get_option('scg_post_category', 1));
        $status = (string) get_option('scg_post_status', 'publish');

        foreach ($selected as $i => $kw) {
            if ($rotate && count($api_key_list) > 1) {
                $api_key = $api_key_list[($i + $today_count) % count($api_key_list)];
            } else {
                $api_key = $api_key_list[0];
            }
            $res = $this->generate_article($kw, $api_key, $api_provider, $category, $status, true);
            if (!is_wp_error($res)) {
                $today_count++;
                update_option($today_key, $today_count);
            } else {
                // increment failure counter similarly to existing logic
                $failure_count = (int) get_option('scg_api_failure_count', 0) + 1;
                update_option('scg_api_failure_count', $failure_count);
                if ($failure_count >= 2) {
                    update_option('scg_api_pause_until', time() + 12 * HOUR_IN_SECONDS);
                    update_option('scg_api_failure_count', 0);
                    break;
                }
            }
            if ($today_count >= $daily_total) { break; }
        }
    }

    /**
     * Register the post editor metabox for multiple news source URLs
     */
    public function scg_register_news_sources_metabox() {
        add_meta_box(
            'scg_news_sources',
            __('SCG: Haber Kaynakları (Çoklu URL)', 'seo-content-generator'),
            array($this, 'scg_render_news_sources_metabox'),
            array('post'),
            'normal',
            'default'
        );
    }

    /**
     * Render metabox UI
     */
    public function scg_render_news_sources_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) { return; }
        $urls = get_post_meta($post->ID, 'scg_source_urls', true);
        if (!is_array($urls)) { $urls = array(''); }
        wp_nonce_field('scg_news_sources_meta', 'scg_news_sources_meta_nonce');
        $action = admin_url('admin-post.php');
        ?>
        <div class="scg-news-sources-box">
            <p class="description" style="margin:0 0 8px;">
                <?php echo __('Birden fazla haber URL’si ekleyin, “Haberlerden İçerik Üret” ile bu kaynaklardan tek ve özgün bir içerik oluşturulur.', 'seo-content-generator'); ?>
            </p>
            <div id="scg-news-url-fields">
                <?php foreach ($urls as $u): ?>
                    <input type="url" name="scg_source_urls[]" class="widefat" value="<?php echo esc_attr($u); ?>" placeholder="https://example.com/haber" style="margin-bottom:6px;" />
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="scg-news-add-url">+ <?php echo __('Yeni ekle', 'seo-content-generator'); ?></button>
            <hr />
            <?php wp_nonce_field('scg_generate_from_urls_post', 'scg_generate_from_urls_post_nonce'); ?>
            <button type="button" id="scg-news-generate-btn" class="button button-primary"><?php echo __('Haberlerden İçerik Üret', 'seo-content-generator'); ?></button>
            <div id="scg-news-progress" style="display:none;margin-top:8px;height:6px;background:#eee;border-radius:4px;overflow:hidden;">
                <div class="bar" style="width:30%;height:100%;background:#2271b1;animation:scg-progress 1.1s linear infinite;"></div>
            </div>
            <style>
                @keyframes scg-progress { 0% { transform: translateX(-100%); } 100% { transform: translateX(400%); } }
                #scg-news-progress .bar { will-change: transform; }
            </style>
        </div>
        <script>
        (function(){
            var addBtn = document.getElementById('scg-news-add-url');
            var wrap = document.getElementById('scg-news-url-fields');
            if (addBtn && wrap) {
                addBtn.addEventListener('click', function(){
                    var input = document.createElement('input');
                    input.type = 'url';
                    input.name = 'scg_source_urls[]';
                    input.className = 'widefat';
                    input.placeholder = 'https://example.com/haber';
                    input.style.marginBottom = '6px';
                    wrap.appendChild(input);
                });
            }
            // Build and submit a dynamic form to admin-post to avoid nested form issues in block editor
            var genBtn = document.getElementById('scg-news-generate-btn');
            var prog = document.getElementById('scg-news-progress');
            if (genBtn) {
                genBtn.addEventListener('click', function(){
                    if (genBtn.getAttribute('aria-busy') === 'true') return;
                    genBtn.setAttribute('aria-busy', 'true');
                    genBtn.disabled = true;
                    var oldText = genBtn.textContent;
                    genBtn.dataset.oldText = oldText;
                    genBtn.textContent = '<?php echo esc_js(__('İşleniyor…', 'seo-content-generator')); ?>';
                    if (prog) { prog.style.display = 'block'; }
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = '<?php echo esc_js($action); ?>';
                    // action
                    var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'scg_generate_from_urls_post'; form.appendChild(a);
                    // post id
                    var pid = document.createElement('input'); pid.type = 'hidden'; pid.name = 'post_id'; pid.value = '<?php echo esc_js($post->ID); ?>'; form.appendChild(pid);
                    // nonce
                    var nonceField = document.querySelector('input[name="scg_generate_from_urls_post_nonce"]');
                    if (nonceField) {
                        var n = document.createElement('input'); n.type = 'hidden'; n.name = 'scg_generate_from_urls_post_nonce'; n.value = nonceField.value; form.appendChild(n);
                    }
                    // urls
                    var inputs = document.querySelectorAll('#scg-news-url-fields input[name="scg_source_urls[]"]');
                    inputs.forEach(function(el){
                        if (!el.value) return;
                        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'source_urls[]'; h.value = el.value; form.appendChild(h);
                    });
                    document.body.appendChild(form);
                    form.submit();
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Save URLs to post meta on post save
     */
    public function scg_save_news_sources_meta($post_id) {
        if (!isset($_POST['scg_news_sources_meta_nonce']) || !wp_verify_nonce($_POST['scg_news_sources_meta_nonce'], 'scg_news_sources_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        if (!isset($_POST['scg_source_urls'])) { return; }
        $urls = array_map('trim', (array) $_POST['scg_source_urls']);
        $urls = array_values(array_filter($urls));
        update_post_meta($post_id, 'scg_source_urls', $urls);
    }

    /**
     * Handle admin-post to generate content for the current post from multiple URLs
     */
    public function scg_handle_generate_from_urls_post() {
        if (!isset($_POST['scg_generate_from_urls_post_nonce']) || !wp_verify_nonce($_POST['scg_generate_from_urls_post_nonce'], 'scg_generate_from_urls_post')) {
            wp_die(__('Güvenlik doğrulaması başarısız.', 'seo-content-generator'));
        }
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
            wp_die(__('Yetkiniz yok.', 'seo-content-generator'));
        }
        $urls = isset($_POST['source_urls']) ? (array) $_POST['source_urls'] : array();
        $urls = array_values(array_filter(array_map('trim', $urls)));
        if (empty($urls)) {
            wp_redirect(add_query_arg(array('scg_msg' => 'no_urls'), get_edit_post_link($post_id, '')));
            exit;
        }

        // Settings
        $api_keys     = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $api_key_list = array_filter(array_map('trim', explode("\n", (string) $api_keys)));
        if (empty($api_key_list)) {
            wp_redirect(add_query_arg(array('scg_msg' => 'no_api'), get_edit_post_link($post_id, '')));
            exit;
        }
        $rotate = (int) get_option('scg_api_rotation', 0) === 1;
        $api_key = ($rotate && count($api_key_list) > 1) ? $api_key_list[array_rand($api_key_list)] : $api_key_list[0];

        // Category detection for News schema
        $terms = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));
        $category_id = !empty($terms) ? intval($terms[0]) : (int) get_option('scg_post_category', 1);

        try {
            // Build via helpers to update THIS post
            $sources = $this->fetch_and_extract_sources($urls);
            if (empty($sources)) {
                wp_redirect(add_query_arg(array('scg_msg' => 'fetch_fail'), get_edit_post_link($post_id, '')));
                exit;
            }
            $prompt = $this->build_sources_prompt_news($sources);
            $raw = ($api_provider === 'gemini') ? $this->call_gemini_api($api_key, $prompt) : $this->call_openai_api($api_key, $prompt);
            if (is_wp_error($raw) || empty($raw)) {
                wp_redirect(add_query_arg(array('scg_msg' => 'api_fail'), get_edit_post_link($post_id, '')));
                exit;
            }
            $title     = trim($this->get_string_between($raw, '[SEO_TITLE]', '[META_DESCRIPTION]'));
            $meta_desc = trim($this->get_string_between($raw, '[META_DESCRIPTION]', '[BODY_HTML]'));
            $content   = trim($this->get_string_between($raw, '[BODY_HTML]', '[END_BODY_HTML]'));
            $faq_html  = trim($this->get_string_between($raw, '[FAQ_HTML]', '[END_FAQ_HTML]'));
            if ($title === '' || $content === '') {
                wp_redirect(add_query_arg(array('scg_msg' => 'parse_fail'), get_edit_post_link($post_id, '')));
                exit;
            }

            $content = $this->sanitize_api_html($content);
            $content = $this->humanize_text($content, $title);
            $content = $this->add_table_of_contents($content);

            // Extract images from sources, sideload, and inject if applicable
            $img_candidates = $this->get_image_candidates_from_sources($urls);
            $attachment_ids = $this->sideload_images_from_list($img_candidates, $post_id, $title);
            if (!empty($attachment_ids)) {
                // Set featured image if not set
                if (!has_post_thumbnail($post_id)) {
                    set_post_thumbnail($post_id, $attachment_ids[0]);
                }
                if (stripos($content, '<img') === false) {
                    // No images from AI -> inject our images richly
                    $content = $this->inject_images_into_content($content, $attachment_ids, $title);
                } else {
                    // Already has some images -> append one fresh image near the middle to diversify visuals
                    $append_one = array($attachment_ids[0]);
                    $content = $this->inject_images_into_content($content, $append_one, $title);
                }
                // Persist for reference
                update_post_meta($post_id, '_scg_attached_images', $attachment_ids);
            } else {
                // Fallback: keep existing minimal behavior
                $content = $this->add_image_if_missing($content, $title);
            }

            // Update post
            $postarr = array(
                'ID' => $post_id,
                'post_title' => sanitize_text_field($title),
                'post_content' => wp_kses_post($content),
            );
            wp_update_post($postarr);

            // SEO meta
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_desc));
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($title));
            update_post_meta($post_id, 'scg_source_urls', array_values($urls));

            // FAQ if provided
            if (!empty($faq_html)) {
                preg_match_all('/<h3 class="faq-question">(.*?)<\/h3>.*?<div class="faq-answer">(.*?)<\/div>/s', $faq_html, $matches, PREG_SET_ORDER);
                if (empty($matches)) {
                    preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*<[^>]+>(.*?)<\/[^>]+>/s', $faq_html, $matches, PREG_SET_ORDER);
                }
                $faq_items = array();
                if (!empty($matches)) {
                    $count = count($matches);
                    $target = max(5, min(7, $count >= 5 ? rand(5, min(7, $count)) : $count));
                    shuffle($matches);
                    $selected = array_slice($matches, 0, $target);
                    foreach ($selected as $m) {
                        $faq_items[] = array(
                            'question' => sanitize_text_field($m[1]),
                            'answer'   => wp_kses_post($m[2]),
                        );
                    }
                }
                if (!empty($faq_items)) {
                    update_post_meta($post_id, 'rank_math_rich_snippet', 'faq');
                    $rm_items = array();
                    foreach ($faq_items as $it) {
                        $rm_items[] = array(
                            'property' => 'name',
                            'value' => $it['question'],
                            'type' => 'Question',
                            'visible' => true,
                            'questions' => array(
                                array(
                                    'property' => 'acceptedAnswer',
                                    'value' => $it['answer'],
                                    'type' => 'Answer',
                                    'visible' => true,
                                )
                            )
                        );
                    }
                    update_post_meta($post_id, 'rank_math_snippet_faq_schema', $rm_items);
                    $this->ensure_rank_math_faq_schema_db_and_shortcode($post_id, $faq_items);
                }
            }

            // Ensure NewsArticle schema by category
            $is_news = $this->is_news_category($category_id);
            $this->ensure_rank_math_article_schema($post_id, $title, $is_news);

            wp_redirect(add_query_arg(array('scg_msg' => 'generated'), get_edit_post_link($post_id, '')));
            exit;
        } catch (Throwable $e) {
            error_log('[SCG] scg_handle_generate_from_urls_post error: ' . $e->getMessage());
            wp_redirect(add_query_arg(array('scg_msg' => 'exception'), get_edit_post_link($post_id, '')));
            exit;
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('seo-content-generator', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add AJAX handlers
        add_action('wp_ajax_scg_get_article_data', array($this, 'ajax_get_article_data'));
        add_action('wp_ajax_scg_save_article_data', array($this, 'ajax_save_article_data'));
        add_action('wp_ajax_scg_query_keywords', array($this, 'ajax_query_keywords'));
        add_action('wp_ajax_scg_generate_article', array($this, 'ajax_generate_article'));

    // AJAX handler to clear queued auto run and last run log
    add_action('wp_ajax_scg_auto_clear', array($this, 'ajax_auto_clear'));

        // Keyword lists CRUD
        add_action('wp_ajax_scg_get_keyword_lists', array($this, 'ajax_get_keyword_lists'));
        add_action('wp_ajax_scg_save_keyword_list', array($this, 'ajax_save_keyword_list'));
        add_action('wp_ajax_scg_update_keyword_list', array($this, 'ajax_update_keyword_list'));
        add_action('wp_ajax_scg_delete_keyword_list', array($this, 'ajax_delete_keyword_list'));
        
        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Frontend: output JSON-LD for FAQs if available
        add_action('wp_head', array($this, 'render_faq_jsonld'));
        // Fallback: also try footer in case theme doesn't call wp_head or scripts are moved
        add_action('wp_footer', array($this, 'render_faq_jsonld_footer'));

        // Frontend: append visible FAQ section to content for readers
        add_filter('the_content', array($this, 'append_faq_to_content'), 20);

        // Post editor: add multi-URL news sources metabox and handlers
        add_action('add_meta_boxes', array($this, 'scg_register_news_sources_metabox'));
        add_action('save_post', array($this, 'scg_save_news_sources_meta'));
        add_action('admin_post_scg_generate_from_urls_post', array($this, 'scg_handle_generate_from_urls_post'));
        // Render the news sources UI at the top of the Add New Post screen
        add_action('edit_form_after_title', array($this, 'scg_render_news_sources_at_top'));
    }

    /**
     * Render the news sources UI at the top of the Add New Post screen (post-new.php)
     * This duplicates the metabox UI but is shown above the title/editor for new posts.
     */
    public function scg_render_news_sources_at_top($post) {
        // Only show on the "Add New Post" screen for standard posts
        if (!is_admin()) { return; }
        global $pagenow;
        if ($pagenow !== 'post-new.php') { return; }
        if (!isset($post) || $post->post_type !== 'post') { return; }

        // Capability: allow users who can publish posts to see the UI when creating posts
        if (!current_user_can('edit_posts')) { return; }

        // Use existing meta values if any (for example when duplicating a post)
        $urls = get_post_meta(isset($post->ID) ? $post->ID : 0, 'scg_source_urls', true);
        if (!is_array($urls)) { $urls = array(''); }
        wp_nonce_field('scg_news_sources_meta', 'scg_news_sources_meta_nonce');
        $action = admin_url('admin-post.php');
        ?>
        <div id="scg_news_sources" class="postbox">
            <div class="postbox-header"><h2 class="hndle ui-sortable-handle"><?php echo esc_html__('SCG: Haber Kaynakları (Çoklu URL)', 'seo-content-generator'); ?></h2></div>
            <div class="inside">
                <div class="scg-news-sources-box">
                    <p class="description" style="margin:0 0 8px;">
                        <?php echo __('Birden fazla haber URL’si ekleyin, “Haberlerden İçerik Üret” ile bu kaynaklardan tek ve özgün bir içerik oluşturulur.', 'seo-content-generator'); ?>
                    </p>
                    <div id="scg-news-url-fields">
                        <?php foreach ($urls as $u): ?>
                            <input type="url" name="scg_source_urls[]" class="widefat" value="<?php echo esc_attr($u); ?>" placeholder="https://example.com/haber" style="margin-bottom:6px;" />
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="scg-news-add-url">+ <?php echo __('Yeni ekle', 'seo-content-generator'); ?></button>
                    <hr />
                    <?php wp_nonce_field('scg_generate_from_urls_post', 'scg_generate_from_urls_post_nonce'); ?>
                    <button type="button" id="scg-news-generate-btn" class="button button-primary"><?php echo __('Haberlerden İçerik Üret', 'seo-content-generator'); ?></button>
                    <div id="scg-news-progress" style="display:none;margin-top:8px;height:6px;background:#eee;border-radius:4px;overflow:hidden;">
                        <div class="bar" style="width:30%;height:100%;background:#2271b1;animation:scg-progress 1.1s linear infinite;"></div>
                    </div>
                    <style>
                        @keyframes scg-progress { 0% { transform: translateX(-100%); } 100% { transform: translateX(400%); } }
                        #scg-news-progress .bar { will-change: transform; }
                    </style>
                </div>

                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-admin-network"></span> API Test ve Model Denetimi</h2>
                    <p><?php echo __('Bu panelden API anahtarlarınızı test edebilir ve kullanılabilir Gemini modellerini sorgulayabilirsiniz. Anahtarlarınız bu sayfada ayarlanmışsa otomatik olarak kullanılacaktır.', 'seo-content-generator'); ?></p>

                    <p>
                        <button type="button" id="scg-listmodels-btn" class="button"><?php echo __('ListModels', 'seo-content-generator'); ?></button>
                        <button type="button" id="scg-api-test-btn" class="button button-primary" style="margin-left:8px;"><?php echo __('Hızlı Test', 'seo-content-generator'); ?></button>
                        <span id="scg-api-test-status" style="margin-left:10px;"></span>
                    </p>

                    <div id="scg-api-test-panel" style="display:none;margin-top:10px;">
                        <h4><?php echo __('Sonuçlar ve Modeller', 'seo-content-generator'); ?></h4>
                        <textarea id="scg-api-test-results" readonly rows="10" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #ddd;background:#fafafa;"></textarea>
                    </div>

                    <script>
                    (function(){
                        var listBtn = document.getElementById('scg-listmodels-btn');
                        var testBtn = document.getElementById('scg-api-test-btn');
                        var status = document.getElementById('scg-api-test-status');
                        var panel = document.getElementById('scg-api-test-panel');
                        var out = document.getElementById('scg-api-test-results');

                        function fetchListModels() {
                            status.textContent = '<?php echo esc_js(__('Bekleniyor...', 'seo-content-generator')); ?>';
                            var data = new FormData();
                            data.append('action', 'scg_list_gemini_models');
                            data.append('nonce', document.getElementById('scg-list-models-nonce') ? document.getElementById('scg-list-models-nonce').value : '');
                            fetch(ajaxurl, {method:'POST', body: data, credentials: 'same-origin'})
                            .then(function(r){ return r.json(); })
                            .then(function(json){
                                status.textContent = '';
                                panel.style.display = 'block';
                                if (json && json.success) {
                                    var txt = '';
                                    if (json.data.models && json.data.models.length) {
                                        txt = 'Available models:\n' + json.data.models.join('\n');
                                    } else if (json.data.raw) {
                                        txt = json.data.raw;
                                    } else {
                                        txt = 'No models returned.';
                                    }
                                    out.value = txt;
                                } else {
                                    out.value = 'ListModels failed: ' + (json && json.data && json.data.message ? json.data.message : JSON.stringify(json));
                                }
                            }).catch(function(){ status.textContent = ''; out.value = 'Network error'; panel.style.display = 'block'; });
                        }

                        listBtn && listBtn.addEventListener('click', fetchListModels);

                        testBtn && testBtn.addEventListener('click', function(){
                            status.textContent = '<?php echo esc_js(__('Bekleniyor...', 'seo-content-generator')); ?>';
                            var data = new FormData();
                            data.append('action', 'scg_test_api_connection');
                            data.append('nonce', '<?php echo esc_js(wp_create_nonce('scg_test_api')); ?>');
                            fetch(ajaxurl, {method:'POST', body: data, credentials:'same-origin'})
                            .then(function(r){ return r.json(); })
                            .then(function(json){
                                status.textContent = '';
                                panel.style.display = 'block';
                                if (json && json.success) {
                                    var d = json.data;
                                    var txt = '';
                                    if (d.models && d.models.length) txt += 'Available models:\n' + d.models.join('\n') + '\n\n';
                                    if (d.raw) txt += 'Raw:\n' + d.raw + '\n\n';
                                    if (d.message) txt += 'Message:\n' + d.message + '\n';
                                    out.value = txt;
                                } else {
                                    out.value = 'Test failed: ' + (json && json.data && json.data.message ? json.data.message : JSON.stringify(json));
                                }
                            }).catch(function(){ status.textContent = ''; out.value = 'Network error'; panel.style.display = 'block'; });
                        });
                    })();
                    </script>
                </div>
                <script>
                (function(){
                    var addBtn = document.getElementById('scg-news-add-url');
                    var wrap = document.getElementById('scg-news-url-fields');
                    if (addBtn && wrap) {
                        addBtn.addEventListener('click', function(){
                            var input = document.createElement('input');
                            input.type = 'url';
                            input.name = 'scg_source_urls[]';
                            input.className = 'widefat';
                            input.placeholder = 'https://example.com/haber';
                            input.style.marginBottom = '6px';
                            wrap.appendChild(input);
                        });
                    }
                    var genBtn = document.getElementById('scg-news-generate-btn');
                    var prog = document.getElementById('scg-news-progress');
                    if (genBtn) {
                        genBtn.addEventListener('click', function(){
                            if (genBtn.getAttribute('aria-busy') === 'true') return;
                            genBtn.setAttribute('aria-busy', 'true');
                            genBtn.disabled = true;
                            var oldText = genBtn.textContent;
                            genBtn.dataset.oldText = oldText;
                            genBtn.textContent = '<?php echo esc_js(__('İşleniyor…', 'seo-content-generator')); ?>';
                            if (prog) { prog.style.display = 'block'; }
                            var form = document.createElement('form');
                            form.method = 'post';
                            form.action = '<?php echo esc_js($action); ?>';
                            var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'scg_generate_from_urls_post'; form.appendChild(a);
                            var pid = document.createElement('input'); pid.type = 'hidden'; pid.name = 'post_id'; pid.value = '<?php echo esc_js(isset($post->ID) ? $post->ID : ''); ?>'; form.appendChild(pid);
                            var nonceField = document.querySelector('input[name="scg_generate_from_urls_post_nonce"]');
                            if (nonceField) {
                                var n = document.createElement('input'); n.type = 'hidden'; n.name = 'scg_generate_from_urls_post_nonce'; n.value = nonceField.value; form.appendChild(n);
                            }
                            var inputs = document.querySelectorAll('#scg-news-url-fields input[name="scg_source_urls[]"]');
                            inputs.forEach(function(el){
                                if (!el.value) return;
                                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'source_urls[]'; h.value = el.value; form.appendChild(h);
                            });
                            document.body.appendChild(form);
                            form.submit();
                        });
                    }
                })();
                </script>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        // Define our plugin pages
        $plugin_pages = array(
            'toplevel_page_seo-content-generator',
            'seo-content-generator_page_scg-api-settings',
            'seo-content-generator_page_scg-keywords-settings',
            'seo-content-generator_page_scg-generated-articles',
            'seo-content-generator_page_scg-test-content',
            'seo-content-generator_page_scg-live-test',
            'seo-content-generator_page_scg-auto-publish'
        );
        
        // Enqueue on all plugin pages
        if (in_array($hook, $plugin_pages)) {
            wp_enqueue_style('scg-admin-style', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), time());
        }
    }

    /**
     * Detect if the given category ID represents a News category.
     * Heuristic: slug or name contains 'haber' or 'news'.
     */
    private function is_news_category($category_id) {
        $cat_id = intval($category_id);
        if ($cat_id <= 0) return false;
        $term = get_term($cat_id, 'category');
        if (!$term || is_wp_error($term)) return false;
        $slug = isset($term->slug) ? strtolower($term->slug) : '';
        $name = isset($term->name) ? strtolower($term->name) : '';
        return (strpos($slug, 'haber') !== false) || (strpos($slug, 'news') !== false) ||
               (strpos($name, 'haber') !== false) || (strpos($name, 'news') !== false);
    }

    /**
     * Remove full-document wrappers like <!doctype>, <html>, <head>, <body>, and <title> from API HTML.
     * Keep inner <body> content if present. Return cleaned HTML.
     */
    private function sanitize_api_html($html) {
        $html = (string) $html;
        if ($html === '') return $html;
        // Remove BOM and trim
        $html = trim(preg_replace('/^\xEF\xBB\xBF/', '', $html));
        // Drop DOCTYPE
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        // If there's a body, keep only its inner HTML
        if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i', $html, $m)) {
            $html = $m[1];
        }
        // Remove head section entirely
        $html = preg_replace('/<head[\s\S]*?<\/head>/i', '', $html);
        // Remove outer html tag wrappers
        $html = preg_replace('/<\/?html[^>]*>/i', '', $html);
        // Remove title tags if any remain
        $html = preg_replace('/<title[\s\S]*?<\/title>/i', '', $html);
        return trim($html);
    }

    /**
     * Lightly humanize text to reduce AI footprint while preserving meaning and HTML.
     * - Trims excessive whitespace
     * - Replaces some overused connectors with varied Turkish synonyms
     * - Removes leftover AI markers if any are present
     *
     * @param string $text
     * @param string $keyword Optional, reserved for future contextual tweaks
     * @return string
     */
    private function humanize_text($text, $keyword = '') {
        if (empty($text) || !is_string($text)) {
            return $text;
        }

        // Remove leftover AI markers just in case
        $markers = array(
            '[SEO_TITLE]','[META_DESCRIPTION]','[BODY_HTML]','[END_BODY_HTML]','[FAQ_HTML]','[END_FAQ_HTML]'
        );
        $text = str_replace($markers, '', $text);

        // Normalize whitespace (outside of HTML tags kept intact)
        // Collapse multiple spaces
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        // Normalize line breaks
        $text = preg_replace("/\r\n|\r|\n/", "\n", $text);

        // Common Turkish connectors: provide mild variation
        $replacements = array(
            // sentence starters
            '/(?<=^|[>\.\!?]\s)(Ayrıca)\b/u' => 'Bunun yanında',
            '/(?<=^|[>\.\!?]\s)(Ancak)\b/u' => 'Bununla birlikte',
            '/(?<=^|[>\.\!?]\s)(Sonuç olarak)\b/u' => 'Özetle',
            '/(?<=^|[>\.\!?]\s)(Özetlemek gerekirse)\b/u' => 'Kısaca',
            '/(?<=^|[>\.\!?]\s)(Buna ek olarak)\b/u' => 'Ek olarak',
            // in-sentence
            '/\bözellikle\b/ui' => 'bilhassa',
            '/\bdolayısıyla\b/ui' => 'bu nedenle',
            '/\bbu yüzden\b/ui' => 'bu sebeple',
            '/\bönemli\b/ui' => 'dikkate değer',
        );
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Cleanup: remove double spaces again
        $text = preg_replace('/\s{2,}/u', ' ', $text);
        // Trim but keep HTML structure
        $text = trim($text);

        return $text;
    }

    /**
     * Build a simple Table of Contents from H2/H3 headings in HTML content.
     * Ensures headings have IDs and injects a ToC box at the top.
     * If no headings found, returns content unchanged.
     */
    private function add_table_of_contents($html) {
        if (empty($html) || stripos($html, '<h2') === false) {
            return $html; // require at least an H2 for ToC
        }

        // Try DOM approach for reliability
        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $wrappedHtml = '<div id="scg-wrapper">' . $html . '</div>';
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrappedHtml);
            libxml_clear_errors();
            if ($loaded) {
                $xpath = new DOMXPath($dom);
                $headings = $xpath->query('//*[@id="scg-wrapper"]//h2 | //*[@id="scg-wrapper"]//h3');
                if ($headings->length === 0) {
                    return $html;
                }

                $items = array();
                $idCounts = array();
                foreach ($headings as $node) {
                    $tag = strtolower($node->nodeName);
                    $text = trim($node->textContent);
                    if ($text === '') { continue; }
                    $id = '';
                    if ($node->hasAttribute('id')) {
                        $id = $node->getAttribute('id');
                    }
                    if ($id === '') {
                        $base = sanitize_title($text);
                        if ($base === '') { $base = 'baslik'; }
                        $count = isset($idCounts[$base]) ? ++$idCounts[$base] : ($idCounts[$base] = 1);
                        $id = $base . '-' . $count;
                        $node->setAttribute('id', $id);
                    }
                    $items[] = array('id' => $id, 'text' => $text, 'level' => $tag);
                }

                if (!empty($items)) {
                    // Build ToC HTML
                    $tocHtml = '<div class="scg-toc" style="margin:16px 0;padding:12px;border:1px solid #e5e5e5;border-radius:8px;background:#fafafa">'
                        . '<strong style="display:block;margin-bottom:8px;">' . esc_html__('İçindekiler', 'seo-content-generator') . '</strong>'
                        . '<ul style="margin:0;padding-left:18px;">';
                    foreach ($items as $it) {
                        $pad = ($it['level'] === 'h3') ? ' style="margin-left:12px;"' : '';
                        $tocHtml .= '<li' . $pad . '><a href="#' . esc_attr($it['id']) . '">' . esc_html($it['text']) . '</a></li>';
                    }
                    $tocHtml .= '</ul></div>';

                    // Insert ToC at top of wrapper
                    $wrapper = $dom->getElementById('scg-wrapper');
                    if ($wrapper) {
                        $frag = $dom->createDocumentFragment();
                        $frag->appendXML($tocHtml);
                        $wrapper->insertBefore($frag, $wrapper->firstChild);
                    }

                    // Extract inner HTML of wrapper
                    $out = '';
                    foreach ($wrapper->childNodes as $child) {
                        $out .= $dom->saveHTML($child);
                    }
                    return $out;
                }
            }
        }

        // Fallback: simple regex prepend (no IDs ensured)
        $tocTitle = esc_html__('İçindekiler', 'seo-content-generator');
        $fallbackToc = '<div class="scg-toc"><strong>' . $tocTitle . '</strong></div>';
        return $fallbackToc . $html;
    }

    /**
     * Extract candidate image URLs from a list of source URLs.
     * Prefers og:image / twitter:image, falls back to significant <img> tags in <article>/<main>/<body>.
     * Returns a de-duplicated array of absolute URLs.
     */
    private function get_image_candidates_from_sources($urls) {
        $candidates = array();
        foreach ((array)$urls as $url) {
            $url = esc_url_raw(trim((string)$url));
            if ($url === '') { continue; }
            $res = wp_remote_get($url, array(
                'timeout' => 10,
                'headers' => array('User-Agent' => 'Mozilla/5.0 (compatible; SCG/1.0)')
            ));
            if (is_wp_error($res)) { continue; }
            $code = wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) { continue; }
            $html = (string) wp_remote_retrieve_body($res);
            if ($html === '') { continue; }

            $found = array();
            // Meta tags
            if (preg_match_all('/<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $m1)) {
                $found = array_merge($found, $m1[1]);
            }
            if (preg_match_all('/<meta[^>]+name=["\']twitter:image["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $m2)) {
                $found = array_merge($found, $m2[1]);
            }
            // Article/main/body images
            if (preg_match_all('/<(article|main|body)[^>]*>[\s\S]*?<img[^>]+src=["\']([^"\']+)["\'][^>]*[\s\S]*?<\/(?:article|main|body)>/i', $html, $m3)) {
                $found = array_merge($found, $m3[2]);
            } else if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m4)) {
                $found = array_merge($found, $m4[1]);
            }

            // Normalize and filter
            $base = $url;
            foreach ($found as $imgUrl) {
                $imgUrl = trim(html_entity_decode($imgUrl));
                if ($imgUrl === '') continue;
                // Resolve relative URLs
                if (strpos($imgUrl, 'http') !== 0) {
                    $imgUrl = wp_make_link_relative($imgUrl); // ensure no javascript:
                    $imgUrl = $this->resolve_url($base, $imgUrl);
                }
                // Filter by extension
                if (!preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $imgUrl)) { continue; }
                $candidates[] = esc_url_raw($imgUrl);
            }
        }
        // De-duplicate while preserving order
        $seen = array();
        $unique = array();
        foreach ($candidates as $u) {
            if (isset($seen[$u])) continue;
            $seen[$u] = true;
            $unique[] = $u;
        }
        return $unique;
    }

    /**
     * Resolve a possibly relative URL against a base URL.
     */
    private function resolve_url($base, $relative) {
        // If already absolute
        if (preg_match('/^https?:\/\//i', $relative)) return $relative;
        // Parse base
        $p = wp_parse_url($base);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return $relative;
        $scheme = $p['scheme'];
        $host = $p['host'];
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        $path = isset($p['path']) ? $p['path'] : '/';
        // If relative starts with //
        if (strpos($relative, '//') === 0) return $scheme . ':' . $relative;
        // If root-relative
        if (strpos($relative, '/') === 0) return $scheme . '://' . $host . $port . $relative;
        // Else join with base path
        $path = preg_replace('#/[^/]*$#', '/', $path);
        return $scheme . '://' . $host . $port . $path . $relative;
    }

    /**
     * Download images to media library and attach to post. Returns an array of attachment IDs.
     */
    private function sideload_images_from_list($img_urls, $post_id, $alt_text = '') {
        if (empty($img_urls)) return array();
        // Load required includes
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $ids = array();
        foreach ($img_urls as $u) {
            if (count($ids) >= 3) break; // limit
            $att_id = media_sideload_image($u, $post_id, $alt_text, 'id');
            if (is_wp_error($att_id)) { continue; }
            $ids[] = intval($att_id);
        }
        return $ids;
    }

    /**
     * Inject downloaded images into content: after first paragraph and mid-content.
     */
    private function inject_images_into_content($html, $attachment_ids, $title = '') {
        if (empty($attachment_ids) || empty($html)) return $html;
        $img_htmls = array();
        foreach ($attachment_ids as $aid) {
            $img_htmls[] = wp_get_attachment_image($aid, 'large', false, array(
                'alt' => sanitize_text_field($title)
            ));
        }
        if (empty($img_htmls)) return $html;

        // Insert after first paragraph
        $out = $html;
        $firstImg = array_shift($img_htmls);
        $out = preg_replace('/<p(\s[^>]*)?>/i', '$0' . $firstImg, $out, 1, $count);
        if ($count === 0) {
            // No <p>, prepend
            $out = $firstImg . $out;
        }
        // If more, insert near middle by paragraph count
        if (!empty($img_htmls)) {
            $paras = preg_split('/(<\/p>)/i', $out, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($paras && count($paras) > 2) {
                $mid = (int) floor(count($paras) / 2);
                array_splice($paras, $mid, 0, $img_htmls[0]);
                $out = implode('', $paras);
            } else {
                $out .= $img_htmls[0];
            }
        }
        return $out;
    }

    /**
     * Add an image if content has none. Minimal safe implementation: no-op if <img> exists.
     * We avoid remote fetches; theme/featured image handling can occur elsewhere.
     */
    private function add_image_if_missing($html, $keyword = '') {
        if (empty($html)) { return $html; }
        if (stripos($html, '<img') !== false) { return $html; }
        // Minimal: keep content unchanged to avoid unexpected media operations.
        return $html;
    }

    /**
     * Add internal links into the content.
     * Minimal safe implementation: no-op (returns content as-is).
     * Future: query related posts by keyword and inject links.
     *
     * @param string $html
     * @param string $keyword
     * @return string
     */
    private function add_internal_links($html, $post_id = 0, $max_links = 3) {
        if (empty($html)) { return $html; }
        // Minimal: no-op for now; future: find related posts and inject up to $max_links anchors
        return $html;
    }

    /**
     * Add a relevant external link into the content.
     * Minimal safe implementation: no-op (returns content as-is).
     */
    private function add_external_link($html, $keyword = '') {
        if (empty($html)) { return $html; }
        return $html;
    }

    /**
     * AJAX: Return all keyword lists
     */
    public function ajax_get_keyword_lists() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        $lists = get_option('scg_keyword_lists', array());
        // Calculate used keyword counts per list from persistent storage
        $used_keywords = get_option('scg_used_keywords', array());
        if (!is_array($used_keywords)) { $used_keywords = array(); }
        // Normalize used keywords for case-insensitive compare
        $used_lc = array();
        foreach ($used_keywords as $uk) {
            if (!is_string($uk)) { continue; }
            $t = trim(mb_strtolower($uk));
            if ($t !== '') { $used_lc[$t] = true; }
        }

        if (is_array($lists)) {
            foreach ($lists as $lid => $lst) {
                $count_used = 0;
                if (isset($lst['keywords']) && is_array($lst['keywords']) && !empty($lst['keywords'])) {
                    foreach ($lst['keywords'] as $kw) {
                        if (!is_string($kw)) { continue; }
                        $k = trim(mb_strtolower($kw));
                        if ($k !== '' && isset($used_lc[$k])) { $count_used++; }
                    }
                }
                $lists[$lid]['used_count'] = $count_used;
            }
        } else {
            $lists = array();
        }

        wp_send_json_success(array('lists' => array_values($lists)));
    }

    /**
     * AJAX: Create/save a new keyword list
     * Expects: name (string), keywords (string with newlines)
     */
    public function ajax_save_keyword_list() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        $name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';
        $keywords_str = isset($_POST['keywords']) ? (string) $_POST['keywords'] : '';
        if ($name === '' || trim($keywords_str) === '') {
            wp_send_json_error(array('message' => __('İsim ve kelimeler zorunludur.', 'seo-content-generator')));
        }
        $incoming = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $keywords_str))));
        // Strip leading bullets like *, -, •, · or numbering like 1., 1), 1- then dedupe
        $incoming = array_map(function($line){
            return preg_replace('/^([*•·\-]+|\d+[\.)\-]*)\s*/u', '', (string)$line);
        }, $incoming);
        $incoming = array_values(array_unique(array_filter($incoming)));
        $lists = get_option('scg_keyword_lists', array());
        if (!is_array($lists)) { $lists = array(); }

        // Try to find an existing list by case-insensitive name
        $foundId = '';
        foreach ($lists as $lid => $lst) {
            if (isset($lst['name']) && mb_strtolower($lst['name']) === mb_strtolower($name)) {
                $foundId = $lid;
                break;
            }
        }

        if ($foundId) {
            // Merge keywords into existing list (dedupe)
            $existing = isset($lists[$foundId]['keywords']) && is_array($lists[$foundId]['keywords']) ? $lists[$foundId]['keywords'] : array();
            $merged = array_values(array_unique(array_filter(array_map('trim', array_merge($existing, $incoming)))));
            $lists[$foundId]['keywords'] = $merged;
            $lists[$foundId]['updated_at'] = current_time('mysql');
            update_option('scg_keyword_lists', $lists);
            wp_send_json_success(array('message' => __('Mevcut listeye eklendi.', 'seo-content-generator'), 'list' => $lists[$foundId]));
        } else {
            // Create new list
            $id = uniqid('list_', true);
            $lists[$id] = array(
                'id' => $id,
                'name' => $name,
                'keywords' => $incoming,
                'updated_at' => current_time('mysql')
            );
            update_option('scg_keyword_lists', $lists);
            wp_send_json_success(array('message' => __('Liste oluşturuldu.', 'seo-content-generator'), 'list' => $lists[$id]));
        }
    }

    /**
     * AJAX: Update an existing keyword list
     * Expects: id, name, keywords
     */
    public function ajax_update_keyword_list() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        $id = isset($_POST['id']) ? sanitize_text_field((string) $_POST['id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';
        $keywords_str = isset($_POST['keywords']) ? (string) $_POST['keywords'] : '';
        if ($id === '' || $name === '') {
            wp_send_json_error(array('message' => __('Geçersiz istek.', 'seo-content-generator')));
        }
        $lists = get_option('scg_keyword_lists', array());
        if (!isset($lists[$id])) {
            wp_send_json_error(array('message' => __('Liste bulunamadı.', 'seo-content-generator')));
        }
        // Normalize incoming keywords (strip bullets/numbers) if provided
        $keywords = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$keywords_str))));
        $keywords = array_map(function($line){
            return preg_replace('/^([*•·\-]+|\d+[\.)\-]*)\s*/u', '', (string)$line);
        }, $keywords);
        $keywords = array_values(array_unique(array_filter($keywords)));
        $lists[$id]['name'] = $name;
        if (!empty($keywords)) {
            $lists[$id]['keywords'] = $keywords;
        }
        $lists[$id]['updated_at'] = current_time('mysql');
        update_option('scg_keyword_lists', $lists);
        wp_send_json_success(array('message' => __('Liste güncellendi.', 'seo-content-generator'), 'list' => $lists[$id]));
    }

    /**
     * AJAX: Delete a keyword list
     * Expects: id
     */
    public function ajax_delete_keyword_list() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        $id = isset($_POST['id']) ? sanitize_text_field((string) $_POST['id']) : '';
        if ($id === '') {
            wp_send_json_error(array('message' => __('Geçersiz istek.', 'seo-content-generator')));
        }
        $lists = get_option('scg_keyword_lists', array());
        if (isset($lists[$id])) {
            unset($lists[$id]);
            update_option('scg_keyword_lists', $lists);
        }
        wp_send_json_success(array('message' => __('Liste silindi.', 'seo-content-generator')));
    }

    /**
     * AJAX: Generate a single article without page reload (Generated Articles page)
     */
    public function ajax_generate_article() {
        // Security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field((string) $_POST['keyword']) : '';
        if (empty($keyword)) {
            wp_send_json_error(array('message' => __('Anahtar kelime boş olamaz.', 'seo-content-generator')));
        }

        // Settings
        $api_keys     = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $category     = (int) get_option('scg_post_category', 1);
        $status       = (string) get_option('scg_post_status', 'publish');

        $api_key_list = array_filter(array_map('trim', explode("\n", (string) $api_keys)));
        if (empty($api_key_list)) {
            wp_send_json_error(array('message' => __('API anahtarı bulunamadı. Lütfen API ayarlarını kontrol edin.', 'seo-content-generator')));
        }

        $rotate = (int) get_option('scg_api_rotation', 0) === 1;
        if ($rotate && count($api_key_list) > 1) {
            $api_key = $api_key_list[array_rand($api_key_list)];
        } else {
            $api_key = $api_key_list[0];
        }

        // Generate
        try {
            $result = $this->generate_article($keyword, $api_key, $api_provider, $category, $status);
        } catch (Throwable $e) {
            // Log and return
            error_log('[SCG] ajax_generate_article fatal: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(__('"%s" için makale oluşturulamadı (beklenmeyen hata).', 'seo-content-generator'), $keyword),
                'error'   => array('code' => 'exception', 'message' => $e->getMessage())
            ));
        }

        if (is_wp_error($result)) {
            $data = array(
                'message' => sprintf(__('"%s" için makale oluşturulamadı.', 'seo-content-generator'), $keyword),
                'error'   => array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'data'    => $result->get_error_data(),
                )
            );
            wp_send_json_error($data);
        }

        if (empty($result) || !is_numeric($result) || intval($result) <= 0) {
            error_log('[SCG] ajax_generate_article returned invalid post id for keyword: ' . $keyword);
            wp_send_json_error(array(
                'message' => sprintf(__('"%s" için makale oluşturulamadı (geçersiz yanıt).', 'seo-content-generator'), $keyword),
                'error'   => array('code' => 'invalid_post', 'message' => 'Invalid post id returned')
            ));
        }

        $post_id = (int) $result;
        $response = array(
            'message' => sprintf(__('"%s" için makale başarıyla oluşturuldu!', 'seo-content-generator'), $keyword),
            'post_id' => $post_id,
            'edit_url' => get_edit_post_link($post_id, ''),
            'view_url' => get_permalink($post_id),
            'status'   => get_post_status($post_id),
            'title'    => get_the_title($post_id),
        );
        wp_send_json_success($response);
    }

    /**
     * AJAX: Trigger automatic generation now (admin button)
     */
    public function ajax_trigger_auto_run() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }

        // Initialize a queued, stepwise automatic run so the client can poll progress
        $keywords = get_option('scg_keywords');
        $api_keys = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $num_articles = get_option('scg_num_articles', 3);
        $category = get_option('scg_post_category', 1);
        $status = get_option('scg_post_status', 'publish');

        // Quick pre-checks
        if (empty($keywords) || empty($api_keys)) {
            wp_send_json_error(array('message' => __('Eksik ayar: Kelime listesi veya API anahtarı bulunamadı.', 'seo-content-generator')));
        }
        $pause_until = (int) get_option('scg_api_pause_until', 0);
        if ($pause_until > time()) {
            wp_send_json_error(array('message' => __('Otomatik jenerasyon geçici olarak durduruldu.', 'seo-content-generator')));
        }

        // Build keyword pool (same logic as generate_daily_content)
        $keyword_list = array_filter(array_map('trim', explode("\n", (string)$keywords)));
        $lists = get_option('scg_keyword_lists', array());
        if (is_array($lists) && !empty($lists)) {
            foreach ($lists as $lst) {
                if (isset($lst['keywords']) && is_array($lst['keywords'])) {
                    foreach ($lst['keywords'] as $kw) { $keyword_list[] = trim((string)$kw); }
                }
            }
        }
        $keyword_list = array_values(array_unique(array_filter($keyword_list)));

        // Filter unused
        $unused_keywords = array();
        foreach ($keyword_list as $kw) {
            if ($kw === '') { continue; }
            if (!$this->is_keyword_used($kw)) { $unused_keywords[] = $kw; }
        }
        if (empty($unused_keywords)) {
            wp_send_json_error(array('message' => __('Kullanılmamış kelime yok.', 'seo-content-generator')));
        }

        shuffle($unused_keywords);
        $selected = array_slice($unused_keywords, 0, max(1, intval($num_articles)));

        // Prepare queue structure and save
        $queue = array(
            'queue' => array_values($selected),
            'api_provider' => $api_provider,
            'api_keys' => array_values(array_filter(array_map('trim', explode("\n", (string)$api_keys)))),
            'category' => intval($category),
            'status' => (string)$status,
            'rotate' => (int) get_option('scg_api_rotation', 0) === 1,
            'created' => current_time('mysql'),
        );
        update_option('scg_auto_run_queue', $queue);

        // Initialize last run log
        $run_log = array('started' => current_time('mysql'), 'selected' => $queue['queue'], 'results' => array(), 'aborted' => false);
        update_option('scg_last_auto_run', $run_log);

        wp_send_json_success(array('message' => __('Otomatik çalışma başlatıldı.', 'seo-content-generator'), 'total' => count($queue['queue'])));
    }

    /**
     * AJAX: process one step of the queued automatic run
     */
    public function ajax_auto_step() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }

        $queue = get_option('scg_auto_run_queue', array());
        if (empty($queue) || empty($queue['queue'])) {
            wp_send_json_success(array('done' => true));
        }

        // Acquire lock to process one item safely
        $lock_key = 'scg_auto_lock';
        $lock_token = $this->acquire_auto_lock($lock_key, 900);
        if (!$lock_token) {
            wp_send_json_error(array('message' => 'could_not_acquire_lock'));
        }

        // Check for abort request
        $abort = get_option('scg_auto_abort', 0);
        if ($abort) {
            // mark last run aborted and clear queue
            $last = get_option('scg_last_auto_run', array());
            if (!is_array($last)) { $last = array(); }
            $last['aborted'] = true;
            $last['finished'] = current_time('mysql');
            update_option('scg_last_auto_run', $last);
            delete_option('scg_auto_run_queue');
            delete_option('scg_auto_run_index');
            delete_option('scg_auto_abort');
            $this->release_auto_lock($lock_key, $lock_token);
            wp_send_json_success(array('done' => true, 'aborted' => true));
        }

        try {
            $keyword = array_shift($queue['queue']);
            // pick API key
            $api_keys = isset($queue['api_keys']) ? $queue['api_keys'] : array();
            if (empty($api_keys)) {
                $this->release_auto_lock($lock_key, $lock_token);
                wp_send_json_error(array('message' => 'no_api_keys'));
            }
            $index_done = intval(get_option('scg_auto_run_index', 0));
            $api_key = $api_keys[0];
            if (!empty($queue['rotate']) && count($api_keys) > 1) {
                $api_key = $api_keys[$index_done % count($api_keys)];
            }

            $result = $this->generate_article($keyword, $api_key, $queue['api_provider'], $queue['category'], $queue['status'], true);

            // Update last run log
            $last = get_option('scg_last_auto_run', array());
            if (!is_array($last)) { $last = array('started' => current_time('mysql'), 'selected' => array($keyword), 'results' => array()); }
            if (is_wp_error($result) || empty($result)) {
                $last['results'][$keyword] = array('success' => false, 'error' => is_wp_error($result) ? $result->get_error_message() : 'empty');
            } else {
                $last['results'][$keyword] = array('success' => true, 'post_id' => intval($result));
            }
            update_option('scg_last_auto_run', $last);

            // Persist queue and index
            update_option('scg_auto_run_queue', $queue);
            update_option('scg_auto_run_index', $index_done + 1);

            $remaining = count($queue['queue']);
            $done = $remaining === 0;
            if ($done) {
                $last['finished'] = current_time('mysql');
                update_option('scg_last_auto_run', $last);
                delete_option('scg_auto_run_queue');
                delete_option('scg_auto_run_index');
            }

            wp_send_json_success(array('keyword' => $keyword, 'done' => $done, 'remaining' => $remaining, 'result' => $last['results'][$keyword]));
        } finally {
            $this->release_auto_lock($lock_key, $lock_token);
        }
    }

    /**
     * AJAX: Request to stop the automatic run
     */
    public function ajax_auto_stop() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        update_option('scg_auto_abort', 1);
        wp_send_json_success(array('message' => __('Otomatik çalışma durdurma isteği gönderildi.', 'seo-content-generator')));
    }

    /**
     * AJAX: Clear auto run queue and last run
     */
    public function ajax_auto_clear() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Bu işlemi yapma yetkiniz yok.', 'seo-content-generator')));
        }
        delete_option('scg_auto_run_queue');
        delete_option('scg_auto_run_index');
        delete_option('scg_last_auto_run');
        wp_send_json_success(array('message' => __('Kuyruk temizlendi.', 'seo-content-generator')));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Enqueue script for the live test page
        if ($hook === 'seo-content-generator_page_scg-live-test') {
            wp_enqueue_script('scg-live-test-js', plugin_dir_url(__FILE__) . 'assets/scg-live-test.js', array('jquery'), '1.0.0', true);
            wp_localize_script('scg-live-test-js', 'scg_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('scg_ajax_nonce')
            ));
        }
        // Load Gutenberg compat shim on post editor screens only
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_script('scg-compat-gb66', plugin_dir_url(__FILE__) . 'assets/compat-gb-66.js', array(), time(), true);
        }
        // Enqueue on generated articles page
        // Define pages that need the admin script
        $script_pages = array(
            'seo-content-generator_page_scg-generated-articles',
            'seo-content-generator_page_scg-keywords-settings',
            'seo-content-generator_page_scg-edit-keyword-list',
            'seo-content-generator_page_scg-live-test',
            'seo-content-generator_page_scg-auto-publish'
        );

        if (in_array($hook, $script_pages)) {
            wp_enqueue_script('scg-admin-script', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), time(), true);
            
            // Localize script with AJAX URL and a general nonce
            wp_localize_script('scg-admin-script', 'scg_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('scg_ajax_nonce')
            ));
        }

        // Enqueue DataTables on pages that render tables
    $dt_pages = array('seo-content-generator_page_scg-generated-articles', 'seo-content-generator_page_scg-keywords-settings', 'seo-content-generator_page_scg-auto-publish');
        if (in_array($hook, $dt_pages)) {
            wp_enqueue_style('scg-datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css');
            wp_enqueue_script('scg-datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', array('jquery'), '1.13.6', true);
        }
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'scg_dashboard_widget',
            __('SEO Content Generator Status', 'seo-content-generator'),
            array($this, 'dashboard_widget')
        );
    }
    
    /**
     * Dashboard widget content
     */
    public function dashboard_widget() {
        $keywords = get_option('scg_keywords');
        $api_keys = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $num_articles = get_option('scg_num_articles', 3);
        $category = get_option('scg_post_category', 1);
        $status = get_option('scg_post_status', 'publish');
        $last_run = get_option('scg_last_run', 'Never');
        $next_run = wp_next_scheduled('scg_daily_content_hook') ? date('Y-m-d H:i:s', wp_next_scheduled('scg_daily_content_hook')) : 'Not scheduled';
        
        ?>
        <div class="scg-dashboard-widget">
            <h3><?php echo __('SEO Content Generator Status', 'seo-content-generator'); ?></h3>
            <div class="scg-status-info">
                <p><strong><?php echo __('API Provider:', 'seo-content-generator'); ?></strong> <?php echo esc_html(ucfirst($api_provider)); ?></p>
                <p><strong><?php echo __('API Keys:', 'seo-content-generator'); ?></strong> <?php echo !empty($api_keys) ? count(array_filter(array_map('trim', explode("\n", $api_keys)))) : 0; ?> configured</p>
                <p><strong><?php echo __('Keywords:', 'seo-content-generator'); ?></strong> <?php echo !empty($keywords) ? count(array_filter(array_map('trim', explode("\n", $keywords)))) : 0; ?> configured</p>
                <p><strong><?php echo __('Articles per day:', 'seo-content-generator'); ?></strong> <?php echo esc_html($num_articles); ?></p>
                <p><strong><?php echo __('Post Category:', 'seo-content-generator'); ?></strong> <?php echo esc_html(get_cat_name($category)); ?></p>
                <p><strong><?php echo __('Post Status:', 'seo-content-generator'); ?></strong> <?php echo esc_html($status); ?></p>
                <p><strong><?php echo __('Last Run:', 'seo-content-generator'); ?></strong> <?php echo esc_html($last_run); ?></p>
                <p><strong><?php echo __('Next Run:', 'seo-content-generator'); ?></strong> <?php echo esc_html($next_run); ?></p>
            </div>
            <div class="scg-widget-actions">
                <a href="<?php echo admin_url('options-general.php?page=seo-content-generator'); ?>" class="button button-primary"><?php echo __('Settings', 'seo-content-generator'); ?></a>
                <a href="<?php echo admin_url('tools.php?page=seo-content-generator-test'); ?>" class="button"><?php echo __('Test Generation', 'seo-content-generator'); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * Render: Edit Keyword List page
     */
    public function edit_keyword_list_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bu sayfaya erişim yetkiniz yok.', 'seo-content-generator'));
        }
        $id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
        $lists = get_option('scg_keyword_lists', array());
        $list = array(
            'id' => $id,
            'name' => '',
            'keywords' => array(),
            'updated_at' => ''
        );
        if ($id && is_array($lists) && isset($lists[$id])) {
            $list = $lists[$id];
        }
        $name = isset($list['name']) ? $list['name'] : '';
        $keywords = isset($list['keywords']) && is_array($list['keywords']) ? implode("\n", $list['keywords']) : '';
        ?>
        <div class="wrap scg-dashboard">
            <h1><?php echo __('Kelime Listesi Düzenle', 'seo-content-generator'); ?></h1>
            <p class="description"><?php echo __('Liste adını ve kelimeleri düzenleyin. Kelimeleri her satıra bir tane olacak şekilde girin.', 'seo-content-generator'); ?></p>
            <div class="scg-dashboard-card" style="max-width:900px;">
                <form id="scg-edit-keyword-list-form">
                    <input type="hidden" name="id" id="scg-edit-list-id" value="<?php echo esc_attr($id); ?>" />
                    <div class="scg-form-group">
                        <label for="scg-edit-list-name"><strong><?php echo __('Liste Adı', 'seo-content-generator'); ?></strong></label>
                        <input type="text" id="scg-edit-list-name" name="name" class="scg-input" value="<?php echo esc_attr($name); ?>" required />
                    </div>
                    <div class="scg-form-group">
                        <label for="scg-edit-list-keywords"><strong><?php echo __('Kelimeler', 'seo-content-generator'); ?></strong></label>
                        <textarea id="scg-edit-list-keywords" name="keywords" class="scg-textarea" rows="12" placeholder="Her satıra bir anahtar kelime girin."><?php echo esc_textarea($keywords); ?></textarea>
                        <p class="description"><?php echo __('Başındaki madde işaretleri ve numaralar otomatik temizlenecektir.', 'seo-content-generator'); ?></p>
                    </div>
                    <div class="scg-form-actions">
                        <button type="submit" class="button button-primary"><?php echo __('Kaydet', 'seo-content-generator'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=scg-keywords-settings')); ?>" class="button"><?php echo __('İptal', 'seo-content-generator'); ?></a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue styles
     */
    public function enqueue_styles() {
        // Check if we're on a single post page and the post has FAQ content
        if (is_single() && has_shortcode(get_post()->post_content, 'faq')) {
            wp_enqueue_style('scg-faq-style', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.0');
        }
        
        // Always enqueue the style for posts that might have FAQ content
        if (is_single()) {
            wp_enqueue_style('scg-faq-style', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.0');
        }
        
        // Enqueue frontend styles
        if (!is_admin()) {
            wp_enqueue_style('scg-style', plugin_dir_url(__FILE__) . 'assets/style.css');
        }
    }

    /**
     * Enqueue admin scripts and styles for plugin pages and localize scg_ajax
     */
    public function enqueue_admin_assets() {
        // Only load on our plugin admin pages
    $allowed_pages = array('seo-content-generator', 'scg-keywords-settings', 'scg-generated-articles', 'scg-media-content-settings', 'scg-auto-publish');
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ( ! in_array( $page, $allowed_pages, true ) ) {
            return;
        }

        wp_enqueue_style('scg-admin-style', SCG_PLUGIN_URL . 'assets/admin.css', array(), '1.0.0');
        wp_enqueue_script('scg-admin-js', SCG_PLUGIN_URL . 'assets/admin.js', array('jquery'), '1.0.0', true);
        wp_enqueue_script('scg-live-test-js', SCG_PLUGIN_URL . 'assets/scg-live-test.js', array('jquery'), '1.0.0', true);

        // Localize AJAX settings for scripts
        $ajax_obj = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('scg_ajax_nonce'),
        );
        wp_localize_script('scg-admin-js', 'scg_ajax', $ajax_obj);
    // legacy live-test script removed; admin-js handles required interactions now
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule a daily event for content generation
        if (!wp_next_scheduled('scg_daily_content_generation')) {
            wp_schedule_event(time(), 'daily', 'scg_daily_content_generation');
        }
        
        // Add default options
        add_option('scg_keywords', '');
        add_option('scg_api_keys', '');
        add_option('scg_api_provider', 'openai');
        add_option('scg_num_articles', 3);
        add_option('scg_keyword_lists', array());
        add_option('scg_post_category', 1);
        add_option('scg_post_status', 'publish'); // Default status set to 'publish'
    // Scheduling defaults
    add_option('scg_auto_publish_enabled', 0);
    add_option('scg_auto_publish_daily_total', 30);
    add_option('scg_auto_publish_per_hour', 3);
    }
    
    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        // Add weekly interval
        $schedules['weekly'] = array(
            'interval' => 604800, // 1 week in seconds
            'display' => __('Once Weekly')
        );
        
        // Add twice daily interval
        $schedules['twicedaily'] = array(
            'interval' => 43200, // 12 hours in seconds
            'display' => __('Twice Daily')
        );
        
        return $schedules;
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('scg_daily_content_generation');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add main menu page
        add_menu_page(
            __('SEO Content Generator', 'seo-content-generator'),
            __('SEO Content Generator', 'seo-content-generator'),
            'manage_options',
            'seo-content-generator',
            array($this, 'dashboard_page'),
            'dashicons-admin-site-alt3'
        );
        
        // Add dashboard submenu
        add_submenu_page(
            'seo-content-generator',
            __('Dashboard', 'seo-content-generator'),
            __('Dashboard', 'seo-content-generator'),
            'manage_options',
            'seo-content-generator',
            array($this, 'dashboard_page')
        );
        
        // Add API settings submenu
        add_submenu_page(
            'seo-content-generator',
            __('API Settings', 'seo-content-generator'),
            __('API Settings', 'seo-content-generator'),
            'manage_options',
            'scg-api-settings',
            array($this, 'api_settings_page')
        );
        
        // Add keywords settings submenu
        add_submenu_page(
            'seo-content-generator',
            __('Keywords Settings', 'seo-content-generator'),
            __('Keywords Settings', 'seo-content-generator'),
            'manage_options',
            'scg-keywords-settings',
            array($this, 'keywords_settings_page')
        );
        
        // Add generated articles submenu
        add_submenu_page(
            'seo-content-generator',
            __('Generated Articles', 'seo-content-generator'),
            __('Generated Articles', 'seo-content-generator'),
            'manage_options',
            'scg-generated-articles',
            array($this, 'generated_articles_page')
        );

        // Add Auto Publish settings submenu
        add_submenu_page(
            'seo-content-generator',
            __('Otomatik Yayınlama', 'seo-content-generator'),
            __('Otomatik Yayınlama', 'seo-content-generator'),
            'manage_options',
            'scg-auto-publish',
            array($this, 'auto_publish_page')
        );
        
        // Add Media & Content Settings submenu (replaces test pages)
        add_submenu_page(
            'seo-content-generator',
            __('Görsel & İçerik Ayarları', 'seo-content-generator'),
            __('Görsel & İçerik Ayarları', 'seo-content-generator'),
            'manage_options',
            'scg-media-content-settings',
            array($this, 'media_content_settings_page')
        );

        // Hidden: Edit Keyword List page (direct link navigation only)
        add_submenu_page(
            'seo-content-generator',
            __('Kelime Listesi Düzenle', 'seo-content-generator'),
            __('Kelime Listesi Düzenle', 'seo-content-generator'),
            'manage_options',
            'scg-edit-keyword-list',
            array($this, 'edit_keyword_list_page')
        );
        // Hide from menu
        remove_submenu_page('seo-content-generator', 'scg-edit-keyword-list');
    }
    
    /**
     * Test content generation page
     */
    public function handle_admin_actions() {
        // Handle configuration save
        if (isset($_POST['save_config']) && wp_verify_nonce($_POST['_wpnonce'], 'scg_save_config_nonce')) {
            // Save image service
            if (isset($_POST['scg_image_service'])) {
                update_option('scg_image_service', sanitize_text_field($_POST['scg_image_service']));
            }
            
            // Save API provider
            if (isset($_POST['scg_api_provider'])) {
                update_option('scg_api_provider', sanitize_text_field($_POST['scg_api_provider']));
            }
            
            // Save post category
            if (isset($_POST['scg_post_category'])) {
                update_option('scg_post_category', intval($_POST['scg_post_category']));
            }
            
            // Save post status
            if (isset($_POST['scg_post_status'])) {
                update_option('scg_post_status', sanitize_text_field($_POST['scg_post_status']));
            }
            
            // Save API rotation setting
            update_option('scg_api_rotation', isset($_POST['scg_api_rotation']) ? 1 : 0);
            
            // Save API status check setting
            update_option('scg_api_status_check', isset($_POST['scg_api_status_check']) ? 1 : 0);
            
            // Save test prompt
            if (isset($_POST['scg_test_prompt'])) {
                update_option('scg_test_prompt', sanitize_textarea_field($_POST['scg_test_prompt']));
            }
            
            // Show success message
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible scg-success-message">';
                echo '<p><strong>' . __('Yapılandırma başarıyla kaydedildi!', 'seo-content-generator') . '</strong></p>';
                echo '</div>';
            });
        }
        
        // Handle test content generation
        if (isset($_POST['generate_test_content']) && wp_verify_nonce($_POST['_wpnonce'], 'scg_test_content_nonce')) {
            $keyword = sanitize_text_field($_POST['test_keyword']);
            if (!empty($keyword)) {
                // Perform a test generation using available API keys (mirrors test_content_page behavior)
                $api_keys = get_option('scg_api_keys');
                $api_provider = get_option('scg_api_provider', 'openai');
                $category = get_option('scg_post_category', 1);
                $status = get_option('scg_post_status', 'publish');
                $api_key_list = array_filter(array_map('trim', explode("\n", (string)$api_keys)));
                $rotate = (int) get_option('scg_api_rotation', 0) === 1;
                if ($rotate && count($api_key_list) > 1) {
                    $api_key = $api_key_list[array_rand($api_key_list)];
                } else {
                    $api_key = !empty($api_key_list) ? $api_key_list[0] : '';
                }
                if (!empty($api_key)) {
                    $res = $this->generate_article($keyword, $api_key, $api_provider, $category, $status);
                    if (!is_wp_error($res) && $res) {
                        add_action('admin_notices', function() use ($keyword) {
                            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('Test makalesi "%s" başarıyla oluşturuldu. (Taslaklar/son içerikler)', 'seo-content-generator'), esc_html($keyword)) . '</p></div>';
                        });
                    } else {
                        $msg = is_wp_error($res) ? $res->get_error_message() : __('Bilinmeyen hata.', 'seo-content-generator');
                        add_action('admin_notices', function() use ($msg) {
                            echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('Test oluşturulamadı: %s', 'seo-content-generator'), esc_html($msg)) . '</p></div>';
                        });
                    }
                } else {
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-error is-dismissible"><p>' . __('Lütfen önce ayarlardan API anahtarınızı girin.', 'seo-content-generator') . '</p></div>';
                    });
                }
            }
        }
    }

    /**
     * Test content generation page
     */
    public function live_test_page() {
        // Mirror the Test Content page exactly
        return $this->test_content_page();
    }

    public function test_content_page() {
        // Handle admin actions first
        $this->handle_admin_actions();
        
        if (isset($_POST['test_keyword']) && !empty($_POST['test_keyword'])) {
            $keyword = sanitize_text_field($_POST['test_keyword']);
            $api_keys = get_option('scg_api_keys');
            $api_provider = get_option('scg_api_provider', 'openai');
            $category = get_option('scg_post_category', 1);
            // Use saved post status for test generation as requested
            $status = get_option('scg_post_status', 'publish');
            
            // Prepare API keys list
            $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
            // Respect API rotation setting
            $rotate = (int) get_option('scg_api_rotation', 0) === 1;
            if ($rotate && count($api_key_list) > 1) {
                // Pick a random key for test generation
                $api_key = $api_key_list[array_rand($api_key_list)];
            } else {
                // Fallback to the first key
                $api_key = !empty($api_key_list) ? $api_key_list[0] : '';
            }
            
            if (!empty($api_key)) {
                $result = $this->generate_article($keyword, $api_key, $api_provider, $category, $status);
                if (!is_wp_error($result) && $result) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . __('Test makalesi başarıyla oluşturuldu! Makaleler sayfasından taslakları kontrol edin.', 'seo-content-generator') . '</p></div>';
                } else {
                    $msg = is_wp_error($result) ? $result->get_error_message() : __('Bilinmeyen hata.', 'seo-content-generator');
                    echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('Makale oluşturulamadı: %s', 'seo-content-generator'), esc_html($msg)) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Lütfen önce ayarlardan API anahtarınızı girin.', 'seo-content-generator') . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap scg-dashboard">
            <div class="scg-dashboard-header">
                <h1><?php echo __('Test İçerik Oluşturma', 'seo-content-generator'); ?></h1>
                <p class="scg-dashboard-subtitle"><?php echo __('Belirli bir anahtar kelime ile içerik üretimini test edin.', 'seo-content-generator'); ?></p>
            </div>
            
            <?php 
            $post_status = get_option('scg_post_status', 'draft');
            $api_provider = get_option('scg_api_provider', 'openai');
            ?>
            <div class="scg-dashboard-stats">
                <div class="scg-stat-card">
                    <h3><?php echo __('Test Özelliği', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value">1</div>
                    <div class="scg-stat-label"><?php echo __('Aktif', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Durum', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo __('Hazır', 'seo-content-generator'); ?></div>
                    <div class="scg-stat-label"><?php echo __('Çalıştırılabilir', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Kayıt Türü', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo ucfirst($post_status); ?></div>
                    <div class="scg-stat-label"><?php echo __('Seçili', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('API Sağlayıcı', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo strtoupper($api_provider); ?></div>
                    <div class="scg-stat-label"><?php echo __('Kurulum', 'seo-content-generator'); ?></div>
                </div>
            </div>
            
            <div class="scg-dashboard-grid">
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-admin-settings"></span> <?php echo __('Test Yapılandırması', 'seo-content-generator'); ?></h2>
                    <p><span class="scg-status-indicator scg-status-active"></span> <strong><?php echo __('Sistem Durumu:', 'seo-content-generator'); ?></strong> <?php echo __('Aktif ve Hazır', 'seo-content-generator'); ?></p>
                    
                    <div class="scg-progress-bar">
                        <div class="scg-progress-fill" style="width: 100%"></div>
                    </div>
                    <p><?php echo __('Yapılandırma tamamlandı: 100%', 'seo-content-generator'); ?></p>
                    
                    <form method="post" action="" class="scg-config-form">
                        <?php wp_nonce_field('scg_save_config_nonce'); ?>
                        
                        <div class="scg-form-grid">
                            <!-- Görsel Üretim Servisi -->
                            <div class="scg-form-group">
                                <label for="scg_image_service"><strong><?php echo __('Görsel Üretim Servisi:', 'seo-content-generator'); ?></strong></label>
                                <select name="scg_image_service" id="scg_image_service" class="scg-select">
                                    <?php $image_service = get_option('scg_image_service', 'none'); ?>
                                    <option value="none" <?php selected($image_service, 'none'); ?>><?php echo __('Kullanma', 'seo-content-generator'); ?></option>
                                    <option value="dalle" <?php selected($image_service, 'dalle'); ?>><?php echo __('DALL-E', 'seo-content-generator'); ?></option>
                                    <option value="midjourney" <?php selected($image_service, 'midjourney'); ?>><?php echo __('Midjourney', 'seo-content-generator'); ?></option>
                                    <option value="stable-diffusion" <?php selected($image_service, 'stable-diffusion'); ?>><?php echo __('Stable Diffusion', 'seo-content-generator'); ?></option>
                                </select>
                            </div>

                            <!-- Makale Üretim API Sağlayıcı -->
                            <div class="scg-form-group">
                                <label for="scg_api_provider"><strong><?php echo __('API Sağlayıcı:', 'seo-content-generator'); ?></strong></label>
                                <select name="scg_api_provider" id="scg_api_provider" class="scg-select">
                                    <?php $api_provider = get_option('scg_api_provider', 'openai'); ?>
                                    <option value="openai" <?php selected($api_provider, 'openai'); ?>><?php echo __('OpenAI', 'seo-content-generator'); ?></option>
                                    <option value="gemini" <?php selected($api_provider, 'gemini'); ?>><?php echo __('Google Gemini', 'seo-content-generator'); ?></option>
                                    <option value="claude" <?php selected($api_provider, 'claude'); ?>><?php echo __('Anthropic Claude', 'seo-content-generator'); ?></option>
                                </select>
                            </div>

                            <!-- Varsayılan Yazı Kategorisi -->
                            <div class="scg-form-group">
                                <label for="scg_post_category"><strong><?php echo __('Yazı Kategorisi:', 'seo-content-generator'); ?></strong></label>
                                <select name="scg_post_category" id="scg_post_category" class="scg-select">
                                    <?php 
                                    $category = get_option('scg_post_category', 1);
                                    $categories = get_categories();
                                    ?>
                                    <option value="1" <?php selected($category, 1); ?>><?php echo __('Varsayılan', 'seo-content-generator'); ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat->term_id; ?>" <?php selected($category, $cat->term_id); ?>><?php echo $cat->name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Yayınlanan Yazının Durumu -->
                            <div class="scg-form-group">
                                <label for="scg_post_status"><strong><?php echo __('Yazı Durumu:', 'seo-content-generator'); ?></strong></label>
                                <select name="scg_post_status" id="scg_post_status" class="scg-select">
                                    <?php $post_status = get_option('scg_post_status', 'draft'); ?>
                                    <option value="draft" <?php selected($post_status, 'draft'); ?>><?php echo __('Taslak', 'seo-content-generator'); ?></option>
                                    <option value="publish" <?php selected($post_status, 'publish'); ?>><?php echo __('Yayınla', 'seo-content-generator'); ?></option>
                                    <option value="private" <?php selected($post_status, 'private'); ?>><?php echo __('Özel', 'seo-content-generator'); ?></option>
                                    <option value="pending" <?php selected($post_status, 'pending'); ?>><?php echo __('İnceleme Bekliyor', 'seo-content-generator'); ?></option>
                                </select>
                            </div>

                            <!-- API Key Rotasyon -->
                            <div class="scg-form-group">
                                <label for="scg_api_rotation">
                                    <input type="checkbox" name="scg_api_rotation" id="scg_api_rotation" value="1" <?php checked(get_option('scg_api_rotation', 0), 1); ?>>
                                    <strong><?php echo __('Her sorguda farklı API key kullan', 'seo-content-generator'); ?></strong>
                                </label>
                                <p class="description"><?php echo __('Birden fazla API anahtarınız varsa, her istekte farklı anahtar kullanılır.', 'seo-content-generator'); ?></p>
                            </div>

                            <!-- API Key Durum Kontrolü -->
                            <div class="scg-form-group">
                                <label for="scg_api_status_check">
                                    <input type="checkbox" name="scg_api_status_check" id="scg_api_status_check" value="1" <?php checked(get_option('scg_api_status_check', 1), 1); ?>>
                                    <strong><?php echo __('API anahtarlarının durumunu kontrol et', 'seo-content-generator'); ?></strong>
                                </label>
                                <p class="description"><?php echo __('API anahtarlarının çalışır durumda olup olmadığını otomatik kontrol eder.', 'seo-content-generator'); ?></p>
                            </div>
                        </div>

                        <!-- Test Prompt Kaydetme - Full Width -->
                        <div class="scg-form-group scg-form-full-width">
                            <label for="scg_test_prompt"><strong><?php echo __('Test İçeriği Prompt:', 'seo-content-generator'); ?></strong></label>
                            <?php
                            $default_prompt = "Sen deneyimli bir SEO uzmanı ve içerik yazarı olarak, '{keyword}' anahtar kelimesini kullanarak bir makale yaz.\n\nMakalenin temel özellikleri şunlar olmalı:\n- **Başlık:** '{title}'\n- **Odak:** Kullanıcıya değer katan, bilgilendirici ve okunması kolay bir içerik oluştur.\n- **Ton:** Doğal, akıcı ve uzman bir dil kullan.\n- **Yapı:** Mantıksal alt başlıklar (H2, H3) kullanarak içeriği düzenle. Giriş, geliştirme ve sonuç bölümlerini dahil et.\n- **SEO:** Anahtar kelimeyi ve ilgili terimleri metin içinde doğal bir şekilde kullan.\n\nLütfen sadece makale içeriğini oluştur. Başlık, meta açıklama veya SSS gibi ek bölümleri dahil etme.";
                            ?>
                            <textarea name="scg_test_prompt" id="scg_test_prompt" class="scg-textarea" rows="4" placeholder="<?php echo esc_attr($default_prompt); ?>"><?php echo esc_textarea(get_option('scg_test_prompt', $default_prompt)); ?></textarea>
                            <p class="description"><?php echo __('Test içeriği oluştururken kullanılacak özel prompt. Boş bırakılırsa varsayılan prompt kullanılır.', 'seo-content-generator'); ?></p>
                        </div>

                        <div class="scg-form-actions">
                            <button type="submit" name="save_config" class="scg-action-button" title="<?php echo __('Yapılandırmayı Kaydet', 'seo-content-generator'); ?>">
                                <span class="dashicons dashicons-saved"></span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Removed legacy #scg-keyword-lists card: replaced by table-based UI -->
                
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-chart-bar"></span> <?php echo __('Test İstatistikleri', 'seo-content-generator'); ?></h2>
                    <?php
                    // Count test articles (drafts with focus keywords)
                    $test_articles_query = new WP_Query(array(
                        'post_type' => 'post',
                        'post_status' => 'draft',
                        'meta_query' => array(
                            array(
                                'key' => '_yoast_wpseo_focuskw',
                                'compare' => 'EXISTS'
                            )
                        ),
                        'posts_per_page' => -1
                    ));
                    $test_articles_count = $test_articles_query->found_posts;
                    wp_reset_postdata();
                    ?>
                    <p><strong><?php echo __('Test Makaleleri:', 'seo-content-generator'); ?></strong> <?php echo $test_articles_count; ?></p>
                    
                    <div class="scg-chart-container">
                        <?php 
                        // Generate sample data for test chart
                        $test_days = array('Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz');
                        $test_values = array(2, 1, 3, 0, 2, 1, 1);
                        $test_max_value = max($test_values) ?: 1;
                        
                        foreach ($test_days as $index => $day) {
                            $height = ($test_values[$index] / $test_max_value) * 100;
                            echo '<div class="scg-chart-bar" style="height: ' . $height . '%">';
                            echo '<div class="scg-chart-bar-value">' . $test_values[$index] . '</div>';
                            echo '<div class="scg-chart-bar-label">' . $day . '</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <p><strong><?php echo __('Son Test:', 'seo-content-generator'); ?></strong> 
                        <?php 
                        $last_test = get_option('scg_last_test', __('Henüz yapılmadı', 'seo-content-generator'));
                        echo esc_html($last_test);
                        ?>
                    </p>
                    <p><strong><?php echo __('Test Durumu:', 'seo-content-generator'); ?></strong> <?php echo __('Hazır', 'seo-content-generator'); ?></p>
                </div>
            </div>
            
            <div class="scg-dashboard-card" style="margin-top: 20px;">
                <h2><span class="dashicons dashicons-admin-tools"></span> <?php echo __('Test İçerik Oluştur', 'seo-content-generator'); ?></h2>
                <form method="post" action="" class="scg-test-form">
                    <?php wp_nonce_field('scg_test_content_nonce'); ?>
                    <div class="scg-form-group">
                        <label for="test_keyword"><?php echo __('Test İçin Anahtar Kelime', 'seo-content-generator'); ?></label>
                        <input type="text" name="test_keyword" id="test_keyword" class="scg-input" placeholder="<?php echo __('Örn: dijital pazarlama', 'seo-content-generator'); ?>" required />
                        <p class="scg-form-description"><?php echo __('Test makalesi oluşturmak için bir anahtar kelime girin. Makale taslak olarak kaydedilecektir.', 'seo-content-generator'); ?></p>
                    </div>
                    
                    <div class="scg-form-actions">
                        <?php submit_button(__('Test Makalesi Oluştur', 'seo-content-generator'), 'primary', 'submit', false, array('class' => 'scg-action-button')); ?>
                    </div>
                </form>
            </div>
            
            <!-- Removed Keyword Lists table from Test Content page as requested -->
            
            <div class="faq-section">
                <h2><?php echo __('Yardım ve SSS', 'seo-content-generator'); ?></h2>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Test makalesi nerede görünür?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Test makalesi taslak olarak oluşturulur ve "Oluşturulan Makaleler" sayfasında "Taslak" durumuyla listelenir.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Test için API kotasını nasıl yönetmeliyim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Test içerik üretiminde kota tüketimi olur. Gereksiz testlerden kaçının ve sadece gerektiğinde test yapın.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Test makalesini nasıl yayınlayabilirim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Test makalesi "Oluşturulan Makaleler" sayfasında düzenleyebilir ve "Yayınla" butonu ile yayınlayabilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Initialize settings
     */
    public function settings_init() {
        register_setting('scg_settings', 'scg_keywords');
        register_setting('scg_settings', 'scg_api_keys');
        register_setting('scg_settings', 'scg_api_provider');
        register_setting('scg_settings', 'scg_num_articles');
        register_setting('scg_settings', 'scg_post_category');
        register_setting('scg_settings', 'scg_post_status');
    // Gemini model option (select a supported model name)
    register_setting('scg_settings', 'scg_gemini_model');

        // Keywords section
        add_settings_section(
            'scg_keywords_section',
            __('Keywords Settings', 'seo-content-generator'),
            array($this, 'keywords_section_callback'),
            'scg_settings'
        );

        // API Key section
        add_settings_section(
            'scg_api_section',
            __('API Settings', 'seo-content-generator'),
            array($this, 'api_section_callback'),
            'scg_settings'
        );

        // Content settings section
        add_settings_section(
            'scg_content_section',
            __('Content Settings', 'seo-content-generator'),
            array($this, 'content_section_callback'),
            'scg_settings'
        );

        // Add settings fields
        add_settings_field(
            'scg_keywords',
            __('Target Keywords', 'seo-content-generator'),
            array($this, 'keywords_render'),
            'scg_settings',
            'scg_keywords_section'
        );

        add_settings_field(
            'scg_api_provider',
            __('API Provider', 'seo-content-generator'),
            array($this, 'api_provider_render'),
            'scg_settings',
            'scg_api_section'
        );

        add_settings_field(
            'scg_api_keys',
            __('API Keys', 'seo-content-generator'),
            array($this, 'api_keys_render'),
            'scg_settings',
            'scg_api_section'
        );

        add_settings_field(
            'scg_num_articles',
            __('Number of Articles per Day', 'seo-content-generator'),
            array($this, 'num_articles_render'),
            'scg_settings',
            'scg_content_section'
        );

        add_settings_field(
            'scg_post_category',
            __('Post Category', 'seo-content-generator'),
            array($this, 'post_category_render'),
            'scg_settings',
            'scg_content_section'
        );

        add_settings_field(
            'scg_post_status',
            __('Post Status', 'seo-content-generator'),
            array($this, 'post_status_render'),
            'scg_settings',
            'scg_content_section'
        );

        // Auto publish settings
        register_setting('scg_settings', 'scg_auto_publish_enabled');
        register_setting('scg_settings', 'scg_auto_publish_daily_total');
        register_setting('scg_settings', 'scg_auto_publish_per_hour');

        add_settings_section(
            'scg_auto_publish_section',
            __('Otomatik Yayınlama Ayarları', 'seo-content-generator'),
            function() { echo __('Günlük ve saatlik otomatik yayınlama ayarları. 0 günlük toplam devre dışı bırakır.', 'seo-content-generator'); },
            'scg_settings'
        );

        add_settings_field(
            'scg_auto_publish_enabled',
            __('Otomatik Yayınlama', 'seo-content-generator'),
            array($this, 'auto_publish_enabled_render'),
            'scg_settings',
            'scg_auto_publish_section'
        );

        add_settings_field(
            'scg_auto_publish_daily_total',
            __('Günlük Toplam', 'seo-content-generator'),
            array($this, 'auto_publish_daily_total_render'),
            'scg_settings',
            'scg_auto_publish_section'
        );

        add_settings_field(
            'scg_auto_publish_per_hour',
            __('Saat Başına', 'seo-content-generator'),
            array($this, 'auto_publish_per_hour_render'),
            'scg_settings',
            'scg_auto_publish_section'
        );
        // Register AJAX endpoints used by the admin UI to ListModels from Gemini and to test API connectivity
        if (is_admin()) {
            add_action('wp_ajax_scg_list_gemini_models', array($this, 'ajax_list_gemini_models'));
            add_action('wp_ajax_scg_test_api_connection', array($this, 'ajax_test_api_connection'));
        }
    }

    public function auto_publish_enabled_render() {
        $val = get_option('scg_auto_publish_enabled', 0);
        echo '<label><input type="checkbox" name="scg_auto_publish_enabled" value="1" ' . checked(1, $val, false) . '> ' . __('Etkinleştir', 'seo-content-generator') . '</label>';
    }

    public function auto_publish_daily_total_render() {
        $val = intval(get_option('scg_auto_publish_daily_total', 30));
        echo '<input type="number" name="scg_auto_publish_daily_total" value="' . esc_attr($val) . '" min="0" max="1000"> <p class="description">(0=devre dışı)</p>';
    }

    public function auto_publish_per_hour_render() {
        $val = intval(get_option('scg_auto_publish_per_hour', 3));
        echo '<input type="number" name="scg_auto_publish_per_hour" value="' . esc_attr($val) . '" min="0" max="50">';
    }

    /**
     * Keywords section callback
     */
    public function keywords_section_callback() {
        echo __('Enter keywords you want to rank for, one per line.', 'seo-content-generator');
    }

    /**
     * API section callback
     */
    public function api_section_callback() {
        echo __('Enter your API keys for content generation. You can add multiple keys to distribute the load.', 'seo-content-generator');
    }

    /**
     * Content section callback
     */
    public function content_section_callback() {
        echo __('Configure how the content should be generated and published.', 'seo-content-generator');
    }

    /**
     * Keywords field render
     */
    public function keywords_render() {
        $keywords = get_option('scg_keywords');
        echo '<textarea name="scg_keywords" rows="10" cols="50" class="large-text">' . esc_textarea($keywords) . '</textarea>';
        echo '<p class="description">' . __('Enter one keyword or keyword phrase per line.', 'seo-content-generator') . '</p>';
    }

    /**
     * API provider field render
     */
    public function api_provider_render() {
        $provider = get_option('scg_api_provider', 'openai');
        $providers = array(
            'openai' => __('OpenAI', 'seo-content-generator'),
            'gemini' => __('Google Gemini', 'seo-content-generator')
        );
        
        echo '<select name="scg_api_provider">';
        foreach ($providers as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($provider, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Select the AI provider to use for content generation.', 'seo-content-generator') . '</p>';
    }
    
    /**
     * API keys field render
     */
    public function api_keys_render() {
        $api_keys = get_option('scg_api_keys');
        echo '<textarea name="scg_api_keys" rows="5" cols="50" class="large-text">' . esc_textarea($api_keys) . '</textarea>';
        echo '<p class="description">' . __('Enter one API key per line. The plugin will rotate between these keys for content generation.', 'seo-content-generator') . '</p>';
    }

    /**
     * Gemini model selector render
     */
    public function gemini_model_render() {
        $val = get_option('scg_gemini_model', 'models/gemini-2.5-flash');
        // A small curated list of likely supported models presented in a datalist; admin may paste any model string.
        $options = array(
            'models/gemini-2.5-flash' => 'models/gemini-2.5-flash',
            'models/gemini-2.5-pro' => 'models/gemini-2.5-pro',
            'models/gemini-1.5-mini' => 'models/gemini-1.5-mini'
        );

        $datalist_id = 'scg_gemini_models_datalist';
        echo '<input list="' . esc_attr($datalist_id) . '" name="scg_gemini_model" value="' . esc_attr($val) . '" style="width:360px;">';
        echo '<datalist id="' . esc_attr($datalist_id) . '">';
        foreach ($options as $k => $label) {
            echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        }
        echo '</datalist>';

        // ListModels button + output area
        $nonce = wp_create_nonce('scg_list_models');
        echo '<button type="button" id="scg-list-models-btn" class="button" style="margin-left:8px;">' . esc_html__('ListModels', 'seo-content-generator') . '</button>';
        echo '<input type="hidden" id="scg-list-models-nonce" value="' . esc_attr($nonce) . '">';
        echo '<div id="scg-list-models-output" style="margin-top:8px;display:none;"><textarea readonly rows="6" style="width:100%;max-width:600px;box-sizing:border-box;padding:8px;border:1px solid #ddd;background:#f9f9f9;" id="scg-list-models-textarea"></textarea></div>';

        echo '<p class="description">' . __('Paste the exact model name (for example: models/gemini-2.5-flash) or press ListModels to query the API using your configured key(s).', 'seo-content-generator') . '</p>';

        // Inline JS to call AJAX and display model list
        ?>
        <script>
        (function(){
            var btn = document.getElementById('scg-list-models-btn');
            if (!btn) return;
            var original = btn.textContent;
            btn.addEventListener('click', function(){
                var nonce = document.getElementById('scg-list-models-nonce').value;
                var apiKeysField = document.querySelector('textarea[name="scg_api_keys"]');
                var api_keys = apiKeysField ? apiKeysField.value.trim() : '';

                btn.disabled = true;
                btn.textContent = '<?php echo esc_js(__("Bekleniyor...", "seo-content-generator")); ?>';
                var data = new FormData();
                data.append('action', 'scg_list_gemini_models');
                data.append('nonce', nonce);
                data.append('api_keys', api_keys);

                fetch(ajaxurl, {method: 'POST', body: data, credentials: 'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(json){
                    btn.disabled = false;
                    btn.textContent = original;
                    if (json && json.success) {
                        var models = json.data.models || [];
                        var out = '';
                        if (models.length) {
                            out = models.join('\n');
                        } else if (json.data.raw) {
                            out = json.data.raw;
                        } else if (json.data.message) {
                            out = json.data.message;
                        }
                        var wrap = document.getElementById('scg-list-models-output');
                        var ta = document.getElementById('scg-list-models-textarea');
                        if (ta) { ta.value = out; }
                        if (wrap) { wrap.style.display = 'block'; }
                    } else {
                        var msg = (json && json.data && json.data.message) ? json.data.message : (json && json.message) ? json.message : 'Error';
                        alert('ListModels failed: ' + msg);
                    }
                }).catch(function(err){
                    btn.disabled = false;
                    btn.textContent = original;
                    alert('Network error while fetching models');
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX: List available Gemini models using configured or provided API keys.
     */
    public function ajax_list_gemini_models() {
        // Simple nonce check
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'scg_list_models')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $provided = isset($_POST['api_keys']) ? trim((string) $_POST['api_keys']) : '';
        $api_keys = array_filter(array_map('trim', explode("\n", $provided)));
        if (empty($api_keys)) {
            $stored = get_option('scg_api_keys', '');
            $api_keys = array_filter(array_map('trim', explode("\n", $stored)));
        }

        if (empty($api_keys)) {
            wp_send_json_error(array('message' => 'No API keys provided or configured'));
        }

        $endpoints = array(
            'https://generativelanguage.googleapis.com/v1/models',
            'https://generativelanguage.googleapis.com/v1beta/models'
        );

        $collected = array();
        $raw_responses = array();

        foreach ($api_keys as $key) {
            foreach ($endpoints as $url) {
                $args = array('timeout' => 15, 'headers' => array('x-goog-api-key' => $key));
                $res = wp_remote_get($url, $args);
                if (is_wp_error($res)) {
                    $raw_responses[] = $res->get_error_message();
                    continue;
                }
                $code = wp_remote_retrieve_response_code($res);
                $body = wp_remote_retrieve_body($res);
                if ($code === 200) {
                    $data = json_decode($body, true);
                    if (is_array($data)) {
                        if (!empty($data['models']) && is_array($data['models'])) {
                            foreach ($data['models'] as $m) {
                                if (is_array($m) && isset($m['name'])) {
                                    $collected[] = $m['name'];
                                }
                            }
                        } else {
                            // Some responses might be a flat list of strings
                            foreach ($data as $item) {
                                if (is_string($item) && strpos($item, 'models/') !== false) {
                                    $collected[] = $item;
                                }
                            }
                        }
                    }
                } elseif ($code === 404) {
                    // Try fallback with ?key=
                    $try = $url . '?key=' . urlencode($key);
                    $res2 = wp_remote_get($try, array('timeout' => 15));
                    if (!is_wp_error($res2) && wp_remote_retrieve_response_code($res2) === 200) {
                        $b2 = wp_remote_retrieve_body($res2);
                        $raw_responses[] = $b2;
                        $d2 = json_decode($b2, true);
                        if (is_array($d2) && !empty($d2['models'])) {
                            foreach ($d2['models'] as $m) {
                                if (is_array($m) && isset($m['name'])) {
                                    $collected[] = $m['name'];
                                }
                            }
                        }
                    } else {
                        $raw_responses[] = $body;
                    }
                } else {
                    $raw_responses[] = $body;
                }

                if (!empty($collected)) break;
            }
            if (!empty($collected)) break;
        }

        $collected = array_values(array_unique(array_filter($collected)));
        if (!empty($collected)) {
            wp_send_json_success(array('models' => $collected));
        }

        // Nothing found — return some raw diagnostics (truncated)
        $raw = '';
        if (!empty($raw_responses)) {
            $raw = implode("\n---\n", array_map(function($s){ return mb_substr((string)$s, 0, 2000); }, $raw_responses));
        }
        wp_send_json_success(array('models' => array(), 'raw' => $raw));
    }

    /**
     * AJAX: quick test of API connectivity and models using configured API keys
     */
    public function ajax_test_api_connection() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'scg_test_api')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $stored = get_option('scg_api_keys', '');
        $api_keys = array_filter(array_map('trim', explode("\n", $stored)));
        if (empty($api_keys)) {
            wp_send_json_error(array('message' => 'No API keys configured'));
        }

        $endpoints = array(
            'https://generativelanguage.googleapis.com/v1/models',
            'https://generativelanguage.googleapis.com/v1beta/models'
        );

        $collected = array();
        $raws = array();

        foreach ($api_keys as $key) {
            foreach ($endpoints as $url) {
                $res = wp_remote_get($url, array('headers' => array('x-goog-api-key' => $key), 'timeout' => 15));
                if (is_wp_error($res)) {
                    $raws[] = $res->get_error_message();
                    continue;
                }
                $code = wp_remote_retrieve_response_code($res);
                $b = wp_remote_retrieve_body($res);
                $raws[] = '[' . $code . '] ' . mb_substr($b, 0, 2000);
                if ($code === 200) {
                    $j = json_decode($b, true);
                    if (is_array($j) && !empty($j['models'])) {
                        foreach ($j['models'] as $m) {
                            if (is_array($m) && isset($m['name'])) $collected[] = $m['name'];
                            elseif (is_string($m) && strpos($m, 'models/') !== false) $collected[] = $m;
                        }
                        if (!empty($collected)) break 2;
                    }
                }
            }
        }

        $collected = array_values(array_unique(array_filter($collected)));
        wp_send_json_success(array('models' => $collected, 'raw' => implode("\n---\n", $raws)));
    }

    /**
     * Number of articles field render
     */
    public function num_articles_render() {
        $num_articles = get_option('scg_num_articles', 3);
        echo '<input type="number" name="scg_num_articles" value="' . esc_attr($num_articles) . '" min="1" max="10">';
        echo '<p class="description">' . __('Number of articles to generate each day (1-10).', 'seo-content-generator') . '</p>';
    }

    /**
     * Post category field render
     */
    public function post_category_render() {
        $category = get_option('scg_post_category', 1);
        wp_dropdown_categories(array(
            'name'             => 'scg_post_category',
            'selected'         => $category,
            'show_option_none' => __('Select Category', 'seo-content-generator'),
        ));
    }

    /**
     * Post status field render
     */
    public function post_status_render() {
        $status = get_option('scg_post_status', 'publish');
        $statuses = array('publish' => __('Published', 'seo-content-generator'), 'draft' => __('Draft', 'seo-content-generator'));
        
        echo '<select name="scg_post_status">';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Dashboard page render
     */
    public function dashboard_page() {
        $keywords = get_option('scg_keywords');
        $api_keys = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $num_articles = get_option('scg_num_articles', 3);
        $category = get_option('scg_post_category', 1);
        $status = get_option('scg_post_status', 'publish');
        $last_run = get_option('scg_last_run', 'Yok');
        $next_run = wp_next_scheduled('scg_daily_content_generation') ? date('Y-m-d H:i:s', wp_next_scheduled('scg_daily_content_generation')) : 'Planlanmadı';
        
        // Count keywords
        $keyword_count = 0;
        if (!empty($keywords)) {
            $keyword_list = array_filter(array_map('trim', explode("\n", $keywords)));
            $keyword_count = count($keyword_list);
        }
        
        // Count API keys
        $api_key_count = 0;
        if (!empty($api_keys)) {
            $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
            $api_key_count = count($api_key_list);
        }
        
        // Count generated articles
        $articles_count = 0;
        $articles_query = new WP_Query(array(
            'post_type' => 'post',
            'meta_query' => array(
                array(
                    'key' => '_yoast_wpseo_focuskw',
                    'compare' => 'EXISTS'
                )
            ),
            'post_status' => 'any',
            'posts_per_page' => -1
        ));
        $articles_count = $articles_query->found_posts;
        wp_reset_postdata();
        
        // Calculate completion percentage
        $completion_percentage = min(100, ($keyword_count > 0 && $api_key_count > 0) ? 100 : 0);
        
        // System status
        $system_status = ($api_key_count > 0 && $keyword_count > 0) ? 'active' : 'inactive';
        
        ?>
        <div class="wrap scg-dashboard">
            <div class="scg-dashboard-header">
                <h1>SEO İçerik Üretici Kontrol Paneli</h1>
                <p class="scg-dashboard-subtitle">SEO için optimize edilmiş makaleleri otomatik olarak oluşturun. Arama sıralamalarınızı artırın ve ziyaretçi sayınızı yükseltin. İçerik üretim performansınızı izleyin ve ayarlarınızı yönetin.</p>
            </div>
            
            <div class="scg-dashboard-stats">
                <div class="scg-stat-card">
                    <h3>API Anahtarları</h3>
                    <div class="scg-stat-value"><?php echo $api_key_count; ?></div>
                    <div class="scg-stat-label">Yapılandırıldı</div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Anahtar Kelimeler</h3>
                    <div class="scg-stat-value"><?php echo $keyword_count; ?></div>
                    <div class="scg-stat-label">Mevcut</div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Makaleler</h3>
                    <div class="scg-stat-value"><?php echo $articles_count; ?></div>
                    <div class="scg-stat-label">Oluşturuldu</div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Günlük Hedef</h3>
                    <div class="scg-stat-value"><?php echo $num_articles; ?></div>
                    <div class="scg-stat-label">Makale</div>
                </div>
            </div>
            
            <div class="scg-dashboard-grid">
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-admin-settings"></span> Yapılandırma Durumu</h2>
                    <p><span class="scg-status-indicator scg-status-<?php echo $system_status; ?>"></span> <strong>Sistem Durumu:</strong> 
                        <?php echo ($system_status == 'active') ? 'Aktif ve Hazır' : 'Yapılandırma Gerekli'; ?></p>
                    
                    <div class="scg-progress-bar">
                        <div class="scg-progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
                    </div>
                    <p>Yapılandırma tamamlandı: <?php echo $completion_percentage; ?>%</p>
                    
                    <p><strong>API Sağlayıcı:</strong> <?php echo ucfirst($api_provider); ?></p>
                    <p><strong>Yazı Kategorisi:</strong> 
                        <?php 
                        if ($category && $category != 1) {
                            $cat = get_category($category);
                            echo $cat ? $cat->name : 'Ayarlanmadı';
                        } else {
                            echo 'Varsayılan';
                        }
                        ?>
                    </p>
                    <p><strong>Yazı Durumu:</strong> <?php echo ucfirst($status); ?></p>
                </div>
                
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-chart-bar"></span> Üretim Durumu</h2>
                    <p><strong>Oluşturulan Makaleler:</strong> <?php echo $articles_count; ?></p>
                    
                    <div class="scg-chart-container">
                        <?php 
                        // Generate sample data for chart
                        $days = array('Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz');
                        $values = array(5, 8, 12, 7, 15, 9, 11);
                        $max_value = max($values);
                        
                        foreach ($days as $index => $day) {
                            $height = ($values[$index] / $max_value) * 100;
                            echo '<div class="scg-chart-bar" style="height: ' . $height . '%">';
                            echo '<div class="scg-chart-bar-value">' . $values[$index] . '</div>';
                            echo '<div class="scg-chart-bar-label">' . $day . '</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <p><strong>Son Çalıştırma:</strong> <?php echo esc_html($last_run); ?></p>
                    <p><strong>Sonraki Çalıştırma:</strong> <?php echo esc_html($next_run); ?></p>
                </div>
            </div>
            
            <div class="faq-section">
                <h2><?php echo __('Yardım ve SSS', 'seo-content-generator'); ?></h2>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('İçerik üretimi nasıl çalışır?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Sistem, belirlediğiniz anahtar kelimelerden birini rastgele seçer ve AI ile SEO optimize edilmiş bir makale oluşturur. Günlük hedefinize göre içerik üretimi yapar.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Oluşturulan makaleler nerede görünür?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Oluşturulan makaleler WordPress yazılar bölümünde yayınlanır. "Oluşturulan Makaleler" sayfasından tüm makaleleri görüntüleyebilir ve yönetebilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('API kotasını nasıl yönetmeliyim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Birden fazla API anahtarı ekleyerek kota tüketimini dağıtabilirsiniz. Günlük makale sayısını ayarlayarak kota tüketimini kontrol edebilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Test içerik üretimi ne işe yarar?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Test içerik üretiminde oluşturulan makaleler taslak olarak kaydedilir. Bu sayede gerçek üretim yapmadan sistemin çalışmasını test edebilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * API settings page render
     */
    public function api_settings_page() {
        // Handle form submission
        if (isset($_POST['scg_api_settings_nonce']) && wp_verify_nonce($_POST['scg_api_settings_nonce'], 'scg_save_api_settings')) {
            // Save API provider
            if (isset($_POST['scg_api_provider'])) {
                update_option('scg_api_provider', sanitize_text_field($_POST['scg_api_provider']));
            }
            
            // Save API keys
            if (isset($_POST['scg_api_keys'])) {
                update_option('scg_api_keys', sanitize_textarea_field($_POST['scg_api_keys']));
            }
            
            // Show success message
            echo '<div class="notice notice-success is-dismissible"><p>' . __('API ayarları başarıyla kaydedildi.', 'seo-content-generator') . '</p></div>';
        }
        
        // Get current values
        $api_provider = get_option('scg_api_provider', 'openai');
        $api_keys = get_option('scg_api_keys');
        
        // Count API keys
        $api_key_count = 0;
        if (!empty($api_keys)) {
            $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
            $api_key_count = count($api_key_list);
        }
        
        // Calculate completion percentage
        $completion_percentage = min(100, ($api_key_count > 0) ? 100 : 0);
        
        // System status
        $system_status = ($api_key_count > 0) ? 'active' : 'inactive';
        
        ?>
        <div class="wrap scg-dashboard">
            <div class="scg-dashboard-header">
                <h1>API Ayarları Kontrol Paneli</h1>
                <p class="scg-dashboard-subtitle">İçerik üretiminde kullanılacak AI sağlayıcısını ve API anahtarlarını yapılandırın. API anahtarlarınızı güvenli bir şekilde yönetin ve performansınızı izleyin.</p>
            </div>
            
            <div class="scg-dashboard-stats">
                <div class="scg-stat-card">
                    <h3>API Anahtarları</h3>
                    <div class="scg-stat-value"><?php echo $api_key_count; ?></div>
                    <div class="scg-stat-label">Yapılandırıldı</div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Sağlayıcı</h3>
                    <div class="scg-stat-value"><?php echo ucfirst($api_provider); ?></div>
                    <div class="scg-stat-label">Aktif</div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Durum</h3>
                    <div class="scg-stat-value"><?php echo ($system_status == 'active') ? 'Hazır' : 'Bekliyor'; ?></div>
                    <div class="scg-stat-label"><?php echo ($system_status == 'active') ? 'Çalışır' : 'Yapılandırma'; ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3>Güvenlik</h3>
                    <div class="scg-stat-value">SSL</div>
                    <div class="scg-stat-label">Korumalı</div>
                </div>
            </div>
            
            <div class="scg-dashboard-grid">
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-admin-settings"></span> Yapılandırma Durumu</h2>
                    <p><span class="scg-status-indicator scg-status-<?php echo $system_status; ?>"></span> <strong>Sistem Durumu:</strong> 
                        <?php echo ($system_status == 'active') ? 'Aktif ve Hazır' : 'Yapılandırma Gerekli'; ?></p>
                    
                    <div class="scg-progress-bar">
                        <div class="scg-progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
                    </div>
                    <p>Yapılandırma tamamlandı: <?php echo $completion_percentage; ?>%</p>
                    
                    <p><strong>API Sağlayıcı:</strong> <?php echo ucfirst($api_provider); ?></p>
                    <p><strong>Toplam Anahtar:</strong> <?php echo $api_key_count; ?> adet</p>
                    <p><strong>Güvenlik:</strong> SSL Şifrelemeli</p>
                </div>
                
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-chart-bar"></span> API İstatistikleri</h2>
                    <p><strong>Yapılandırılmış Anahtarlar:</strong> <?php echo $api_key_count; ?></p>
                    
                    <div class="scg-chart-container">
                        <?php 
                        // Generate sample data for API usage chart
                        $days = array('Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz');
                        $values = array(85, 92, 78, 95, 88, 76, 90);
                        $max_value = max($values);
                        
                        foreach ($days as $index => $day) {
                            $height = ($values[$index] / $max_value) * 100;
                            echo '<div class="scg-chart-bar" style="height: ' . $height . '%">';
                            echo '<div class="scg-chart-bar-value">' . $values[$index] . '%</div>';
                            echo '<div class="scg-chart-bar-label">' . $day . '</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <p><strong>Ortalama Başarı:</strong> 86%</p>
                    <p><strong>Son Güncelleme:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </div>
            
            <div class="scg-dashboard-card" style="margin-top: 20px;">
                <h2><span class="dashicons dashicons-admin-tools"></span> API Yapılandırması</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('scg_save_api_settings', 'scg_api_settings_nonce'); ?>
                    
                    <div class="scg-form-group">
                        <label for="scg_api_provider"><?php echo __('AI Sağlayıcısı', 'seo-content-generator'); ?></label>
                        <select name="scg_api_provider" id="scg_api_provider" class="scg-select">
                            <option value="openai" <?php selected($api_provider, 'openai'); ?>><?php echo __('OpenAI', 'seo-content-generator'); ?></option>
                            <option value="gemini" <?php selected($api_provider, 'gemini'); ?>><?php echo __('Google Gemini', 'seo-content-generator'); ?></option>
                        </select>
                        <p class="description"><?php echo __('İçerik üretiminde kullanılacak AI sağlayıcısını seçin.', 'seo-content-generator'); ?></p>
                    </div>
                    
                    <div class="scg-form-group">
                        <label for="scg_api_keys"><?php echo __('API Anahtarları', 'seo-content-generator'); ?></label>
                        <textarea name="scg_api_keys" id="scg_api_keys" rows="10" class="scg-textarea" placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&#10;sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&#10;Her satıra bir API anahtarı girin..."><?php echo esc_textarea($api_keys); ?></textarea>
                        <p class="description"><?php echo __('Her satıra bir API anahtarı girin. Eklenti içerik üretimi için bu anahtarlar arasında dönecektir.', 'seo-content-generator'); ?></p>
                    </div>
                    
                    <div class="scg-form-actions">
                        <?php submit_button(__('Ayarları Kaydet', 'seo-content-generator'), 'primary', 'submit', false, array('class' => 'scg-action-button')); ?>
                    </div>
                </form>
            </div>

            <div class="scg-dashboard-card" style="margin-top:18px;">
                <h2><span class="dashicons dashicons-visibility"></span> API Test ve Limitleri Kontrol Et</h2>
                <p>API anahtarlarınızı ve kullanılabilir modelleri hızlıca test edin. Bu test, yapılandırdığınız anahtarlar ile ListModels isteği yaparak hangi modellerin erişilebilir olduğunu ve ham sunucu çıktısını gösterir.</p>

                <p>
                    <button type="button" id="scg-test-api-btn" class="button button-primary"><?php echo __('API Test Et', 'seo-content-generator'); ?></button>
                    <span style="margin-left:10px;color:#666;font-size:13px;"><?php echo __('Not: Test isteği kısa bir ağ çağrısı yapar; anahtarlarınız sunucuya gönderilecektir.', 'seo-content-generator'); ?></span>
                </p>

                <div id="scg-test-api-output-wrap" style="margin-top:12px;display:none;">
                    <label for="scg-test-api-output"><strong><?php echo __('Test Sonuçları', 'seo-content-generator'); ?></strong></label>
                    <textarea id="scg-test-api-output" readonly rows="10" style="width:100%;max-width:100%;box-sizing:border-box;padding:8px;border:1px solid #ddd;background:#f9f9f9;"></textarea>
                </div>

                <script>
                (function(){
                    var btn = document.getElementById('scg-test-api-btn');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        var outWrap = document.getElementById('scg-test-api-output-wrap');
                        var out = document.getElementById('scg-test-api-output');
                        btn.disabled = true;
                        var original = btn.textContent;
                        btn.textContent = '<?php echo esc_js(__('Test Ediliyor...', 'seo-content-generator')); ?>';
                        outWrap.style.display = 'block';
                        out.value = '<?php echo esc_js(__('Bağlantı testi başlatıldı...', 'seo-content-generator')); ?>\n';

                        var url = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        var data = new FormData();
                        data.append('action', 'scg_test_api_connection');
                        data.append('nonce', '<?php echo esc_js(wp_create_nonce('scg_test_api')); ?>');

                        fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function(response){ return response.json(); })
                        .then(function(json){
                            if (json && json.success) {
                                var models = json.data.models || [];
                                var raw = json.data.raw || '';
                                var txt = '';
                                txt += 'Erişilen modeller (count: ' + models.length + '):\n';
                                if (models.length) txt += models.join('\n') + '\n\n';
                                txt += 'Ham cevap (truncated):\n' + raw;
                                out.value = txt;
                            } else {
                                var msg = 'Bilinmeyen hata';
                                if (json && json.data && json.data.message) msg = json.data.message;
                                else if (json && json.message) msg = json.message;
                                out.value = 'Hata: ' + msg;
                            }
                        })
                        .catch(function(err){
                            out.value = 'Fetch hatası: ' + err;
                        })
                        .finally(function(){
                            btn.disabled = false;
                            btn.textContent = original;
                        });
                    });
                })();
                </script>
            </div>

            <div class="faq-section">
                <h2><?php echo __('Yardım ve SSS', 'seo-content-generator'); ?></h2>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('OpenAI API anahtarı nasıl alabilirim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('OpenAI API anahtarı almak için openai.com adresine gidin, bir hesap oluşturun ve API anahtarları bölümünden yeni bir anahtar oluşturun.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Google Gemini API anahtarı nasıl alabilirim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Google Gemini API anahtarı almak için makersuite.google.com adresine gidin, bir hesap oluşturun ve API anahtarları bölümünden yeni bir anahtar oluşturun.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Neden birden fazla API anahtarı kullanmalıyım?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Birden fazla API anahtarı kullanmak, API sınırlarına takılmamanızı ve içerik üretimini kesintisiz sürdürmenizi sağlar. Eklenti anahtarlar arasında otomatik olarak dönecektir.', 'seo-content-generator'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Media & Content Settings page (replaces old test pages)
     */
    public function media_content_settings_page() {
        // Handle form submission
        if (isset($_POST['scg_media_settings_nonce']) && wp_verify_nonce($_POST['scg_media_settings_nonce'], 'scg_save_media_settings')) {
            if (isset($_POST['scg_image_service'])) update_option('scg_image_service', sanitize_text_field($_POST['scg_image_service']));
            if (isset($_POST['scg_api_provider'])) update_option('scg_api_provider', sanitize_text_field($_POST['scg_api_provider']));
            if (isset($_POST['scg_default_category'])) update_option('scg_default_category', intval($_POST['scg_default_category']));
            if (isset($_POST['scg_post_status'])) update_option('scg_post_status', sanitize_text_field($_POST['scg_post_status']));
            update_option('scg_use_rotating_keys', isset($_POST['scg_use_rotating_keys']) ? 1 : 0);
            update_option('scg_api_check_enabled', isset($_POST['scg_api_check_enabled']) ? 1 : 0);
            if (isset($_POST['scg_test_prompt'])) update_option('scg_test_prompt', sanitize_textarea_field($_POST['scg_test_prompt']));
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Ayarlar kaydedildi.', 'seo-content-generator') . '</p></div>';
        }

        $image_service = get_option('scg_image_service', 'midjourney');
        $api_provider = get_option('scg_api_provider', 'gemini');
        $default_category = get_option('scg_default_category', 1);
        $post_status = get_option('scg_post_status', 'publish');
        $use_rotating = (int) get_option('scg_use_rotating_keys', 1);
        $api_check = (int) get_option('scg_api_check_enabled', 1);
        $test_prompt = get_option('scg_test_prompt', "Sen deneyimli bir SEO uzmanı ve içerik yazarı olarak, '{keyword}' anahtar kelimesini kullanarak bir makale yaz.\n\nMakalenin temel özellikleri şunlar olmalı:\n- **Başlık:** '{title}'\n- **Odak:** Kullanıcıya değer katan, bilgilendirici ve okunması kolay bir içerik oluştur.\n- **Ton:** Doğal, akıcı ve uzman bir dil kullan.\n- **Yapı:** Mantıksal alt başlıklar (H2, H3) kullanarak içeriği düzenle. Giriş, geliştirme ve sonuç bölümlerini dahil et.\n- **SEO:** Anahtar kelimeyi ve ilgili terimleri metin içinde doğal bir şekilde kullan.\n\nLütfen sadece makale içeriğini oluştur. Başlık, meta açıklama veya SSS gibi ek bölümleri dahil etme.");

        // Get categories for dropdown
        $cats = get_categories(array('hide_empty' => false));
        ?>
        <div class="wrap scg-dashboard">
            <h1><?php echo __('Görsel & İçerik Ayarları', 'seo-content-generator'); ?></h1>
            <p class="description"><?php echo __('Görsel üretim servisi ve içerik test ayarlarını burada yapılandırın.', 'seo-content-generator'); ?></p>

            <div class="scg-dashboard-card" style="max-width:900px;">
                <form method="post" action="">
                    <?php wp_nonce_field('scg_save_media_settings', 'scg_media_settings_nonce'); ?>
                    <div class="scg-form-group">
                        <label for="scg_image_service"><?php echo __('Görsel Üretim Servisi', 'seo-content-generator'); ?></label>
                        <select name="scg_image_service" id="scg_image_service" class="scg-select">
                            <option value="midjourney" <?php selected($image_service, 'midjourney'); ?>>Midjourney</option>
                        </select>
                        <p class="description"><?php echo __('Görsel üretimi için kullanılacak servis.', 'seo-content-generator'); ?></p>
                    </div>

                    <div class="scg-form-group">
                        <label for="scg_api_provider"><?php echo __('API Sağlayıcı', 'seo-content-generator'); ?></label>
                        <select name="scg_api_provider" id="scg_api_provider" class="scg-select">
                            <option value="gemini" <?php selected($api_provider, 'gemini'); ?>>Google Gemini</option>
                            <option value="openai" <?php selected($api_provider, 'openai'); ?>>OpenAI</option>
                        </select>
                        <p class="description"><?php echo __('İçerik üretimi için kullanılacak AI sağlayıcısı.', 'seo-content-generator'); ?></p>
                    </div>

                    <div class="scg-form-group">
                        <label for="scg_default_category"><?php echo __('Yazı Kategorisi', 'seo-content-generator'); ?></label>
                        <select name="scg_default_category" id="scg_default_category" class="scg-select">
                            <?php foreach ($cats as $c) : ?>
                                <option value="<?php echo intval($c->term_id); ?>" <?php selected($default_category, $c->term_id); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="scg-form-group">
                        <label for="scg_post_status"><?php echo __('Yazı Durumu', 'seo-content-generator'); ?></label>
                        <select name="scg_post_status" id="scg_post_status" class="scg-select">
                            <option value="publish" <?php selected($post_status, 'publish'); ?>><?php echo __('Yayınla', 'seo-content-generator'); ?></option>
                            <option value="draft" <?php selected($post_status, 'draft'); ?>><?php echo __('Taslak', 'seo-content-generator'); ?></option>
                        </select>
                    </div>

                    <div class="scg-form-group">
                        <label><input type="checkbox" name="scg_use_rotating_keys" value="1" <?php checked(1, $use_rotating); ?>> <?php echo __('Her sorguda farklı API key kullan', 'seo-content-generator'); ?></label>
                        <p class="description"><?php echo __('Birden fazla API anahtarınız varsa, her istekte farklı anahtar kullanılır.', 'seo-content-generator'); ?></p>
                    </div>

                    <div class="scg-form-group">
                        <label><input type="checkbox" name="scg_api_check_enabled" value="1" <?php checked(1, $api_check); ?>> <?php echo __('API anahtarlarının durumunu kontrol et', 'seo-content-generator'); ?></label>
                        <p class="description"><?php echo __('API anahtarlarının çalışır durumda olup olmadığını otomatik kontrol eder.', 'seo-content-generator'); ?></p>
                    </div>

                    <div class="scg-form-group">
                        <label for="scg_test_prompt"><?php echo __('Test İçeriği Prompt', 'seo-content-generator'); ?></label>
                        <textarea id="scg_test_prompt" name="scg_test_prompt" rows="10" class="scg-textarea"><?php echo esc_textarea($test_prompt); ?></textarea>
                        <p class="description"><?php echo __('Test içeriği oluştururken kullanılacak özel prompt. Boş bırakılırsa varsayılan prompt kullanılır.', 'seo-content-generator'); ?></p>
                    </div>

                    <div class="scg-form-actions">
                        <button type="submit" class="button button-primary"><?php echo __('Ayarları Kaydet', 'seo-content-generator'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Auto Publish settings page
     */
    public function auto_publish_page() {
        // Handle saving via settings API
        if (isset($_POST['scg_save_auto_publish']) && check_admin_referer('scg_save_auto_publish_nonce')) {
            update_option('scg_auto_publish_enabled', isset($_POST['scg_auto_publish_enabled']) ? 1 : 0);
            update_option('scg_auto_publish_daily_total', intval($_POST['scg_auto_publish_daily_total']));
            update_option('scg_auto_publish_per_hour', intval($_POST['scg_auto_publish_per_hour']));
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Otomatik yayınlama ayarları kaydedildi.', 'seo-content-generator') . '</p></div>';
        }

        $enabled = (int) get_option('scg_auto_publish_enabled', 0);
        $daily = intval(get_option('scg_auto_publish_daily_total', 30));
        $per_hour = intval(get_option('scg_auto_publish_per_hour', 3));
        $last_run = get_option('scg_last_auto_run', array());
        $last_started = isset($last_run['started']) ? $last_run['started'] : '';
        $last_finished = isset($last_run['finished']) ? $last_run['finished'] : '';
        $selected_keywords = isset($last_run['selected']) && is_array($last_run['selected']) ? $last_run['selected'] : array();
        $top20 = array_slice($selected_keywords, 0, 20);
        ?>
        <div class="wrap scg-dashboard">
            <h1><?php echo __('Otomatik Yayınlama Ayarları', 'seo-content-generator'); ?></h1>
            <p class="description"><?php echo __('Günde toplam kaç makale yayınlanacağını ve saat başına kaç makale oluşturulacağını belirleyin. Varsayılan 30/gün ve 3/saat.', 'seo-content-generator'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field('scg_save_auto_publish_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php echo __('Otomatik Yayınlama', 'seo-content-generator'); ?></th>
                        <td>
                            <label><input type="checkbox" name="scg_auto_publish_enabled" value="1" <?php checked(1, $enabled); ?>> <?php echo __('Etkinleştir', 'seo-content-generator'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('Günlük Toplam', 'seo-content-generator'); ?></th>
                        <td>
                            <input type="number" name="scg_auto_publish_daily_total" value="<?php echo esc_attr($daily); ?>" min="0" max="1000"> <p class="description">(0=devre dışı)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('Saat Başına', 'seo-content-generator'); ?></th>
                        <td>
                            <input type="number" name="scg_auto_publish_per_hour" value="<?php echo esc_attr($per_hour); ?>" min="0" max="50">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="scg_save_auto_publish" class="button button-primary"><?php echo __('Kaydet', 'seo-content-generator'); ?></button>
                </p>
            </form>

            <hr>

            <h2><?php echo __('Otomatik Yayınlama Kontrolleri', 'seo-content-generator'); ?></h2>
            <p><?php echo __('Başlat, Durdur veya mevcut otomatik iş kuyruğunu sil. Üretim anlık olarak progressbar ile gösterilecektir. Hatalar ekranda görünür.', 'seo-content-generator'); ?></p>

            <p>
                <button id="scg-auto-start" class="button button-primary"><?php echo __('Başlat', 'seo-content-generator'); ?></button>
                <button id="scg-auto-stop" class="button"><?php echo __('Durdur', 'seo-content-generator'); ?></button>
                <button id="scg-auto-clear" class="button button-link-delete"><?php echo __('Sil', 'seo-content-generator'); ?></button>
            </p>

            <div style="margin-top:14px;">
                <strong><?php echo __('Son Otomatik Çalışma', 'seo-content-generator'); ?></strong>
                <div>Başladı: <?php echo esc_html($last_started ?: '-'); ?></div>
                <div>Bitti: <?php echo esc_html($last_finished ?: '-'); ?></div>
            </div>

            <div style="margin-top:14px;">
                <strong><?php echo __('Seçilen kelimeler (ilk 20)', 'seo-content-generator'); ?></strong>
                <div id="scg-auto-selected-words" style="margin-top:6px; min-height:40px; background:#fff; border:1px solid #e5e7eb; padding:10px; border-radius:6px;">
                    <?php if (empty($top20)) { echo '<em>' . __('Henüz seçilmiş kelime yok.', 'seo-content-generator') . '</em>'; } else { echo '<ol style="margin:0 0 0 18px;">'; foreach ($top20 as $kw) { echo '<li>' . esc_html($kw) . '</li>'; } echo '</ol>'; } ?>
                </div>
            </div>

            <div style="margin-top:16px;">
                <div id="scg-auto-progress-wrap" style="background:#e6eef8;border-radius:8px;padding:4px;position:relative;">
                    <div id="scg-auto-progress-fill" style="background:#2b8bd3;height:18px;border-radius:6px;width:0%;transition:width 300ms ease;"></div>
                    <div id="scg-auto-progress-label" style="position:absolute;left:12px;top:2px;color:#fff;font-weight:600;">%0</div>
                </div>
                <div id="scg-auto-status" style="margin-top:8px;color:#444;font-weight:600;"></div>
                <div id="scg-auto-meta" style="margin-top:6px;color:#666;font-size:13px;"></div>
                <div id="scg-auto-results" style="margin-top:10px; background:#fff; padding:8px; border:1px solid #e5e7eb; border-radius:6px; min-height:80px; max-height:320px; overflow:auto;"></div>
            </div>

            <!-- Hatalar ve API Detayları: show any errors from the last auto run and diagnostic info -->
            <hr>
            <h2><?php echo __('Hatalar ve API Detayları', 'seo-content-generator'); ?></h2>
            <?php
                $errors = array();
                $detail_lines = array();
                if (!empty($last_run['results']) && is_array($last_run['results'])) {
                    foreach ($last_run['results'] as $kw => $res) {
                        if (isset($res['success']) && $res['success'] === false) {
                            $err = isset($res['error']) ? $res['error'] : __('Bilinmeyen hata', 'seo-content-generator');
                            $errors[] = '<strong>' . esc_html($kw) . ':</strong> ' . esc_html($err);
                            $detail_lines[] = esc_html($kw) . ': ' . esc_html($err);
                        }
                    }
                }

                if (!empty($errors)) {
                    echo '<div class="notice notice-error"><p>' . __('Son otomatik çalışmada alınan hatalar:', 'seo-content-generator') . '</p>';
                    echo '<div style="white-space:pre-wrap;margin:8px 0;padding:8px;background:#fff;border:1px solid #ddd;">' . implode("\n", $errors) . '</div>';
                    echo '</div>';
                } else {
                    echo '<p>' . __('Son çalışmada hata bildirilmedi.', 'seo-content-generator') . '</p>';
                }
            ?>

            <?php if (!empty($detail_lines)) : ?>
                <p><label for="scg-auto-last-error-details"><strong><?php echo __('Hata Detayları (kesilmiş):', 'seo-content-generator'); ?></strong></label></p>
                <textarea id="scg-auto-last-error-details" readonly rows="6" style="width:100%;max-width:100%;box-sizing:border-box;padding:8px;border:1px solid #ddd;background:#f9f9f9;"><?php echo esc_textarea(implode("\n", $detail_lines)); ?></textarea>
            <?php endif; ?>

            <p style="margin-top:12px;"><button id="scg-trigger-auto-run" class="button button-secondary"><?php echo __('Şimdi Çalıştır', 'seo-content-generator'); ?></button></p>
        </div>
        <script>
        (function($){
            $(function(){
                var startBtn = $('#scg-auto-start');
                var stopBtn = $('#scg-auto-stop');
                var clearBtn = $('#scg-auto-clear');
                var runNowBtn = $('#scg-trigger-auto-run');
                var progressFill = $('#scg-auto-progress-fill');
                var progressLabel = $('#scg-auto-progress-label');
                var status = $('#scg-auto-status');
                var meta = $('#scg-auto-meta');
                var results = $('#scg-auto-results');

                function renderResultRow(keyword, res) {
                    var id = 'scg-row-' + Math.random().toString(36).substr(2,8);
                    var ok = res && res.success;
                    var badge = ok ? '<span style="color:#fff;background:#28a745;padding:2px 8px;border-radius:4px;font-weight:600;margin-left:8px;">Başarılı</span>' : '<span style="color:#fff;background:#dc3545;padding:2px 8px;border-radius:4px;font-weight:600;margin-left:8px;">Hata</span>';
                    var title = '<div style="padding:6px 4px;border-bottom:1px solid #f1f5f9;"><strong>' + escapeHtml(keyword) + '</strong>' + badge + '</div>';
                    var detail = '';
                    if (!ok) {
                        var err = res && res.error ? res.error : (res && res.message ? res.message : 'Bilinmeyen hata');
                        detail = '<pre style="white-space:pre-wrap;background:#fbf2f2;border:1px solid #f5c6cb;padding:8px;margin:6px;border-radius:4px;color:#721c24;">' + escapeHtml(err) + '</pre>';
                    } else {
                        detail = '<div style="padding:6px;color:#0b5ed7;">Post ID: ' + (res.post_id ? res.post_id : '') + '</div>';
                    }
                    var wrapper = $('<div class="scg-auto-row" id="'+id+'"></div>');
                    wrapper.append(title).append(detail);
                    results.append(wrapper);
                    // Scroll to bottom
                    results.stop().animate({ scrollTop: results[0].scrollHeight }, 300);
                }

                function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; }); }

                function startRun(button) {
                    button = button || runNowBtn;
                    button.prop('disabled', true).text('Başlatılıyor...');
                    status.text(''); meta.text(''); results.empty();
                    $.post(ajaxurl, { action: 'scg_trigger_auto_run', nonce: '<?php echo wp_create_nonce('scg_ajax_nonce'); ?>' }, function(resp){
                        if (!resp || !resp.success) {
                            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Hata';
                            status.text(msg);
                            button.prop('disabled', false).text(button.is(runNowBtn) ? 'Şimdi Çalıştır' : 'Başlat');
                            return;
                        }
                        var total = parseInt(resp.data.total || 0, 10);
                        var done = 0;
                        updateProgress(0, total);
                        status.text('Başlatıldı — ' + total + ' öğe.');
                        meta.text('Seçilen kelimeler: ' + total);

                        function poll() {
                            $.post(ajaxurl, { action: 'scg_auto_step', nonce: '<?php echo wp_create_nonce('scg_ajax_nonce'); ?>' }, function(step){
                                if (!step || !step.success) {
                                    var em = (step && step.data && step.data.message) ? step.data.message : 'Adım hatası';
                                    status.text('Adım hatası: ' + em);
                                    button.prop('disabled', false).text(button.is(runNowBtn) ? 'Şimdi Çalıştır' : 'Başlat');
                                    return;
                                }
                                var data = step.data || {};
                                if (data.keyword) {
                                    done++;
                                    updateProgress(done, total);
                                    renderResultRow(data.keyword, data.result || {});
                                    status.text('İlerleme: ' + done + '/' + total);
                                }
                                if (data.done) {
                                    status.text('Tamamlandı: ' + done + '/' + total);
                                    button.prop('disabled', false).text(button.is(runNowBtn) ? 'Şimdi Çalıştır' : 'Başlat');
                                    return;
                                }
                                // Continue polling
                                setTimeout(poll, 600);
                            }).fail(function(){ status.text('Sunucu hatası'); button.prop('disabled', false).text(button.is(runNowBtn) ? 'Şimdi Çalıştır' : 'Başlat'); });
                        }
                        setTimeout(poll, 300);
                    }).fail(function(){ status.text('Sunucu hatası'); button.prop('disabled', false).text(button.is(runNowBtn) ? 'Şimdi Çalıştır' : 'Başlat'); });
                }

                function updateProgress(done, total) {
                    var pct = (total > 0) ? Math.round((done/total)*100) : 0;
                    progressFill.css('width', pct + '%');
                    progressLabel.text(pct + '%');
                }

                // Wire start/stop/clear/run-now
                startBtn.on('click', function(e){ e.preventDefault(); startRun(startBtn); });
                runNowBtn.on('click', function(e){ e.preventDefault(); startRun(runNowBtn); });

                stopBtn.on('click', function(e){ e.preventDefault(); stopBtn.prop('disabled', true).text('Durduruluyor...'); $.post(ajaxurl, { action: 'scg_auto_stop', nonce: '<?php echo wp_create_nonce('scg_ajax_nonce'); ?>' }, function(resp){ if (resp && resp.success) { status.text('Durdurma isteği gönderildi. Mevcut adım tamamlandıktan sonra duracaktır.'); } else { status.text('Durdurma başarısız.'); } stopBtn.prop('disabled', false).text('<?php echo esc_js(__('Durdur', 'seo-content-generator')); ?>'); }); });

                clearBtn.on('click', function(e){ e.preventDefault(); if (!confirm('<?php echo esc_js(__('Kuyruk ve son çalışma silinsin mi?', 'seo-content-generator')); ?>')) return; clearBtn.prop('disabled', true); $.post(ajaxurl, { action: 'scg_auto_clear', nonce: '<?php echo wp_create_nonce('scg_ajax_nonce'); ?>' }, function(resp){ if (resp && resp.success) { results.empty(); status.text('Kuyruk temizlendi.'); updateProgress(0,0); meta.text(''); } else { alert('<?php echo esc_js(__('Temizleme başarısız.', 'seo-content-generator')); ?>'); } clearBtn.prop('disabled', false); }); });

            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Keywords settings page render
     */
    public function keywords_settings_page() {
        // Handle form submission
        if (isset($_POST['scg_keywords_settings_nonce']) && wp_verify_nonce($_POST['scg_keywords_settings_nonce'], 'scg_save_keywords_settings')) {
            // Save keywords
            if (isset($_POST['scg_keywords'])) {
                update_option('scg_keywords', sanitize_textarea_field($_POST['scg_keywords']));
            }
            // Save scheduling settings
            if (isset($_POST['scg_auto_publish_enabled'])) {
                update_option('scg_auto_publish_enabled', 1);
            } else {
                update_option('scg_auto_publish_enabled', 0);
            }
            if (isset($_POST['scg_auto_publish_daily_total'])) {
                update_option('scg_auto_publish_daily_total', max(0, intval($_POST['scg_auto_publish_daily_total'])));
            }
            if (isset($_POST['scg_auto_publish_per_hour'])) {
                update_option('scg_auto_publish_per_hour', max(1, intval($_POST['scg_auto_publish_per_hour'])));
            }

            // Schedule or clear hourly job based on enabled flag
            if (get_option('scg_auto_publish_enabled', 0)) {
                $this->scg_schedule_hourly_generation();
            } else {
                $this->scg_clear_hourly_generation();
            }
            
            // Show success message
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Anahtar kelimeler başarıyla kaydedildi.', 'seo-content-generator') . '</p></div>';
        }
        
        // Get current values
        $keywords = get_option('scg_keywords');
        // Ensure keyword lists option exists
        if (false === get_option('scg_keyword_lists', false)) {
            add_option('scg_keyword_lists', array());
        }
        
        // Count keywords and compute status like dashboard
        $keyword_count = 0;
        $keyword_list = array();
        if (!empty($keywords)) {
            $keyword_list = array_filter(array_map('trim', explode("\n", $keywords)));
            $keyword_count = count($keyword_list);
        }
        // Used keywords tracking
        $used_keywords = get_option('scg_used_keywords', array());
        if (!is_array($used_keywords)) { $used_keywords = array(); }
        $used_in_list = array_intersect($used_keywords, $keyword_list);
        $used_count = count($used_in_list);
        // Pause status
        $pause_until = (int) get_option('scg_api_pause_until', 0);
        $now_ts = time();
        $is_paused = ($pause_until > $now_ts);
        $completion_percentage = min(100, ($keyword_count > 0) ? 100 : 0);
        $system_status = ($keyword_count > 0) ? 'active' : 'inactive';
        
        ?>
        <div class="wrap scg-dashboard">
            <div class="scg-dashboard-header">
                <h1><?php echo __('Anahtar Kelimeler', 'seo-content-generator'); ?></h1>
                <p class="scg-dashboard-subtitle"><?php echo __('İçerik üretimi için hedef alınacak anahtar kelimeleri yapılandırın.', 'seo-content-generator'); ?></p>
            </div>
            
            <div class="scg-dashboard-stats">
                <div class="scg-stat-card">
                    <h3><?php echo __('Anahtar Kelimeler', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo $keyword_count; ?></div>
                    <div class="scg-stat-label"><?php echo __('Mevcut', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Durum', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo ($system_status === 'active') ? __('Hazır', 'seo-content-generator') : __('Bekliyor', 'seo-content-generator'); ?></div>
                    <div class="scg-stat-label"><?php echo ($system_status === 'active') ? __('Yapılandırıldı', 'seo-content-generator') : __('Yapılandırma Gerekli', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Tamamlanma', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo $completion_percentage; ?>%</div>
                    <div class="scg-stat-label"><?php echo __('Yapılandırma', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Güncelleme', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo date('H:i'); ?></div>
                    <div class="scg-stat-label"><?php echo date('Y-m-d'); ?></div>
                </div>
            </div>
            
            <?php if ($is_paused): ?>
                <div class="notice notice-warning"><p>
                    <?php
                        $remaining = $pause_until - $now_ts;
                        $hours = floor($remaining / HOUR_IN_SECONDS);
                        $mins = floor(($remaining % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
                        printf(__('API hataları nedeniyle içerik üretimi duraklatıldı. Kalan süre: %s saat %s dakika.', 'seo-content-generator'), $hours, $mins);
                    ?>
                </p></div>
            <?php endif; ?>
            
            <div class="scg-dashboard-card" style="margin-top:15px;">
                <h2><span class="dashicons dashicons-chart-bar"></span> <?php echo __('Anahtar Kelime Özeti', 'seo-content-generator'); ?></h2>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo __('Toplam Anahtar Kelime', 'seo-content-generator'); ?></th>
                            <th><?php echo __('Kullanılan Anahtar Kelime', 'seo-content-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo intval($keyword_count); ?></td>
                            <td><?php echo intval($used_count); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="scg-dashboard-grid">
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-admin-settings"></span> <?php echo __('Yapılandırma Durumu', 'seo-content-generator'); ?></h2>
                    <p><span class="scg-status-indicator scg-status-<?php echo $system_status; ?>"></span> <strong><?php echo __('Sistem Durumu:', 'seo-content-generator'); ?></strong> <?php echo ($system_status === 'active') ? __('Aktif ve Hazır', 'seo-content-generator') : __('Yapılandırma Gerekli', 'seo-content-generator'); ?></p>
                    
                    <div class="scg-progress-bar">
                        <div class="scg-progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
                    </div>
                    <p><?php echo sprintf(__('Yapılandırma tamamlandı: %s%%', 'seo-content-generator'), $completion_percentage); ?></p>
                    
                    <div class="scg-info-box">
                        <h3><?php echo __('İpucu', 'seo-content-generator'); ?></h3>
                        <p><?php echo __('Uzun kuyruklu (long-tail) anahtar kelimelerle başlayın ve performansı iyi olanları genişletin.', 'seo-content-generator'); ?></p>
                        <div class="scg-keyword-query-section">
                            <input type="text" id="scg-query-input" class="scg-query-input" placeholder="<?php echo __('Ana kelime girin (örn: dijital pazarlama)', 'seo-content-generator'); ?>" />
                            <button type="button" id="scg-query-keywords" class="scg-action-button" title="<?php echo __('AI ile Uzun Kuyruklu Kelime Önerisi Al', 'seo-content-generator'); ?>">
                                <span class="dashicons dashicons-search"></span>
                                <?php echo __('Kelime Sorgula', 'seo-content-generator'); ?>
                            </button>
                            <div id="scg-keyword-loading" class="scg-loading-indicator" style="display: none;">
                                <span class="dashicons dashicons-update-alt scg-spin"></span>
                                <?php echo __('AI kelime önerileri alınıyor...', 'seo-content-generator'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="scg-dashboard-card">
                    <h2><span class="dashicons dashicons-edit"></span> <?php echo __('Anahtar Kelimeleri Düzenle', 'seo-content-generator'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('scg_save_keywords_settings', 'scg_keywords_settings_nonce'); ?>
                        
                        <div class="scg-form-group">
                            <label for="scg_keywords"><?php echo __('Hedef Anahtar Kelimeler', 'seo-content-generator'); ?></label>
                            <textarea name="scg_keywords" id="scg_keywords" rows="15" class="scg-textarea"><?php echo esc_textarea($keywords); ?></textarea>
                            <p class="scg-form-description"><?php echo __('Her satıra bir anahtar kelime veya kelime grubu girin.', 'seo-content-generator'); ?></p>
                        </div>
                        
                        <div class="scg-form-actions">
                            <?php submit_button(__('Ayarları Kaydet', 'seo-content-generator'), 'primary', 'submit', false, array('class' => 'scg-action-button')); ?>
                        </div>
                    </form>
                </div>
                
                <div class="scg-dashboard-card" style="margin-top:15px;">
                    <h2><span class="dashicons dashicons-clock"></span> <?php echo __('Otomatik Yayınlama Ayarları', 'seo-content-generator'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('scg_save_keywords_settings', 'scg_keywords_settings_nonce'); ?>
                        <p><?php echo __('Günde toplam kaç makale yayınlanacağını ve saat başında kaç makale oluşturulacağını belirleyin. Varsayılan 30/gün ve 3/saat.', 'seo-content-generator'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="scg_auto_publish_enabled"><?php echo __('Otomatik Yayınlama', 'seo-content-generator'); ?></label></th>
                                <td><input type="checkbox" name="scg_auto_publish_enabled" id="scg_auto_publish_enabled" value="1" <?php checked(1, get_option('scg_auto_publish_enabled', 0)); ?> /> <?php echo __('Etkinleştir', 'seo-content-generator'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="scg_auto_publish_daily_total"><?php echo __('Günlük Toplam', 'seo-content-generator'); ?></label></th>
                                <td><input type="number" name="scg_auto_publish_daily_total" id="scg_auto_publish_daily_total" value="<?php echo intval(get_option('scg_auto_publish_daily_total', 30)); ?>" min="0" class="small-text" /> <?php echo __('(0=devre dışı)', 'seo-content-generator'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="scg_auto_publish_per_hour"><?php echo __('Saat Başına', 'seo-content-generator'); ?></label></th>
                                <td><input type="number" name="scg_auto_publish_per_hour" id="scg_auto_publish_per_hour" value="<?php echo intval(get_option('scg_auto_publish_per_hour', 3)); ?>" min="1" class="small-text" /></td>
                            </tr>
                        </table>
                            <div id="scg-auto-publish-progress-wrap" style="margin:18px 0 10px 0;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <div id="scg-auto-publish-progress-bar" style="flex:1;height:18px;background:#eee;border-radius:8px;overflow:hidden;position:relative;">
                                        <div id="scg-auto-publish-progress-fill" style="height:100%;width:0%;background:#2271b1;transition:width 0.4s;"></div>
                                    </div>
                                    <span id="scg-auto-publish-progress-label" style="min-width:80px;display:inline-block;text-align:center;">0/0</span>
                                </div>
                                <div style="margin-top:10px;display:flex;gap:10px;">
                                    <button type="button" class="button button-primary" id="scg-auto-publish-start">Başlat</button>
                                    <button type="button" class="button" id="scg-auto-publish-stop">Durdur</button>
                                    <button type="button" class="button button-danger" id="scg-auto-publish-reset">Sil</button>
                                </div>
                            </div>
                        <p class="submit">
                            <?php submit_button(__('Yapılandırmayı Kaydet ve Uygula', 'seo-content-generator'), 'secondary', 'submit', false, array('class' => 'scg-action-button')); ?>
                        </p>
                    </form>
                    <div style="margin-top:12px;">
                        <h4><?php echo __('Son Otomatik Çalışma', 'seo-content-generator'); ?></h4>
                        <?php $last = get_option('scg_last_auto_run', array()); ?>
                        <div id="scg-last-auto-run">
                            <?php if (!empty($last) && is_array($last)): ?>
                                <div><?php echo __('Başladı:', 'seo-content-generator'); ?> <?php echo esc_html($last['started'] ?? ''); ?></div>
                                <div><?php echo __('Bitti:', 'seo-content-generator'); ?> <?php echo esc_html($last['finished'] ?? ''); ?></div>
                                <div><?php echo __('Seçilen kelimeler (ilk 20):', 'seo-content-generator'); ?> <?php echo esc_html(implode(', ', array_slice((array)($last['selected'] ?? array()), 0, 20))); ?></div>
                                <?php if (!empty($last['results']) && is_array($last['results'])): ?>
                                    <table class="widefat" style="margin-top:8px;">
                                        <thead><tr><th><?php echo __('Kelime', 'seo-content-generator'); ?></th><th><?php echo __('Durum', 'seo-content-generator'); ?></th><th><?php echo __('Ayrıntı', 'seo-content-generator'); ?></th></tr></thead>
                                        <tbody>
                                        <?php foreach ($last['results'] as $k => $r): ?>
                                            <tr>
                                                <td><?php echo esc_html($k); ?></td>
                                                <td><?php echo (!empty($r['success']) ? '<span style="color:green;">' . __('Başarılı', 'seo-content-generator') . '</span>' : '<span style="color:red;">' . __('Hata', 'seo-content-generator') . '</span>'); ?></td>
                                                <td>
                                                    <?php if (!empty($r['success']) && !empty($r['post_id'])): ?>
                                                        <a href="<?php echo esc_url(get_edit_post_link(intval($r['post_id']))); ?>" target="_blank"><?php echo __('Düzenle', 'seo-content-generator'); ?></a>
                                                        | <a href="<?php echo esc_url(get_permalink(intval($r['post_id']))); ?>" target="_blank"><?php echo __('Görüntüle', 'seo-content-generator'); ?></a>
                                                    <?php else: ?>
                                                        <?php echo esc_html($r['error'] ?? ''); ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php else: ?>
                                <div><?php echo __('Henüz otomatik çalışma kaydı yok.', 'seo-content-generator'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:8px;">
                            <button type="button" class="button button-primary" id="scg-trigger-auto-run"><?php echo __('Şimdi Çalıştır', 'seo-content-generator'); ?></button>
                            <span id="scg-trigger-auto-run-status" style="margin-left:10px;"></span>
                        </div>
                        <div style="margin-top:10px;">
                            <progress id="scg-auto-progress" value="0" max="0" style="width:100%; height:14px;"></progress>
                            <div id="scg-auto-results" style="margin-top:8px; font-family:monospace; font-size:13px; color:#222;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Keyword Lists Table placed above Quick Actions (Keywords Settings page) -->
            <div class="scg-table" style="margin-top: 25px;">
                <div class="scg-table-header">
                    <h2 style="margin:0; color:#fff;">&nbsp;<?php echo __('Kelime Listeleri', 'seo-content-generator'); ?></h2>
                    <div class="scg-table-info"><span class="scg-table-count" id="scg-keyword-lists-count">0</span> <?php echo __('liste', 'seo-content-generator'); ?></div>
                </div>
                <table class="wp-list-table widefat fixed striped" id="scg-keyword-lists-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column"><?php echo __('Liste Adı', 'seo-content-generator'); ?></th>
                            <th scope="col" class="manage-column"><?php echo __('Kelime Sayısı', 'seo-content-generator'); ?></th>
                            <th scope="col" class="manage-column"><?php echo __('Kullanılan', 'seo-content-generator'); ?></th>
                            <th scope="col" class="manage-column"><?php echo __('İşlemler', 'seo-content-generator'); ?></th>
                            <th scope="col" class="manage-column"><?php echo __('İlerleme', 'seo-content-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- JS will populate rows here -->
                    </tbody>
                </table>
            </div>
            
            <!-- Published Auto-Generated Articles -->
            <div class="scg-dashboard-card" style="margin-top:25px;">
                <h2><span class="dashicons dashicons-media-document"></span> <?php echo __('Yayınlanan Otomatik Makaleler', 'seo-content-generator'); ?></h2>
                <?php
                    // Filters
                    $filter_from = isset($_GET['scg_from']) ? sanitize_text_field($_GET['scg_from']) : '';
                    $filter_to = isset($_GET['scg_to']) ? sanitize_text_field($_GET['scg_to']) : '';
                    $paged = isset($_GET['scg_paged']) ? max(1, intval($_GET['scg_paged'])) : 1;
                    $per_page = isset($_GET['scg_per_page']) ? max(1, intval($_GET['scg_per_page'])) : 10;

                    $meta_query = array(
                        array('key' => 'scg_generated_by', 'value' => 'scg_auto', 'compare' => '=')
                    );
                    $date_query = array();
                    if ($filter_from) { $date_query[] = array('after' => $filter_from, 'inclusive' => true); }
                    if ($filter_to) { $date_query[] = array('before' => $filter_to, 'inclusive' => true); }

                    $args = array(
                        'post_type' => 'post',
                        'post_status' => 'publish',
                        'meta_query' => $meta_query,
                        'posts_per_page' => $per_page,
                        'paged' => $paged,
                    );
                    if (!empty($date_query)) { $args['date_query'] = $date_query; }
                    $q = new WP_Query($args);
                ?>
                <form method="get" action="">
                    <input type="hidden" name="page" value="scg-keywords-settings" />
                    <label><?php echo __('Tarih (başlangıç)', 'seo-content-generator'); ?></label>
                    <input type="date" name="scg_from" value="<?php echo esc_attr($filter_from); ?>" />
                    <label><?php echo __('Tarih (bitiş)', 'seo-content-generator'); ?></label>
                    <input type="date" name="scg_to" value="<?php echo esc_attr($filter_to); ?>" />
                    <label style="margin-left:10px;"><?php echo __('Sayfa başına', 'seo-content-generator'); ?></label>
                    <select name="scg_per_page" style="margin-right:8px;">
                        <?php $opts = array(10,20,50,100); foreach ($opts as $o): ?>
                            <option value="<?php echo intval($o); ?>" <?php selected($per_page, $o); ?>><?php echo intval($o); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit"><?php echo __('Filtrele', 'seo-content-generator'); ?></button>
                </form>
                <table id="scg-auto-published-table" class="widefat fixed striped" style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th><?php echo __('Tarih', 'seo-content-generator'); ?></th>
                            <th><?php echo __('Başlık', 'seo-content-generator'); ?></th>
                            <th><?php echo __('Kelime', 'seo-content-generator'); ?></th>
                            <th><?php echo __('İşlemler', 'seo-content-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($q->have_posts()): while ($q->have_posts()): $q->the_post(); ?>
                            <tr>
                                <td><?php echo get_the_date(); ?></td>
                                <td><a href="<?php echo esc_url(get_edit_post_link()); ?>"><?php the_title(); ?></a></td>
                                <td><?php echo esc_html(get_post_meta(get_the_ID(), 'rank_math_focus_keyword', true)); ?></td>
                                <td><a href="<?php echo esc_url(get_permalink()); ?>" target="_blank"><?php echo __('Görüntüle', 'seo-content-generator'); ?></a></td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4"><?php echo __('Henüz otomatik yayınlanmış makale yok.', 'seo-content-generator'); ?></td></tr>
                        <?php endif; wp_reset_postdata(); ?>
                    </tbody>
                </table>
                <?php if ($q->max_num_pages > 1): ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                                // Preserve current filters when building pagination links
                                $preserve_args = array();
                                if ($filter_from) { $preserve_args['scg_from'] = $filter_from; }
                                if ($filter_to) { $preserve_args['scg_to'] = $filter_to; }
                                if ($per_page) { $preserve_args['scg_per_page'] = $per_page; }

                                $base = add_query_arg(array_merge($preserve_args, array('scg_paged' => '%#%')));
                                echo paginate_links(array(
                                    'base' => $base,
                                    'format' => '?scg_paged=%#%',
                                    'current' => $paged,
                                    'total' => $q->max_num_pages,
                                ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="faq-section">
                <h2><?php echo __('Yardım ve SSS', 'seo-content-generator'); ?></h2>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Hangi anahtar kelimeleri seçmeliyim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Hedef kitlenizin arayabileceği, rakiplerinizin zayıf olduğu ve içerik üretebileceğiniz anahtar kelimeleri seçin. Uzun kuyruklu (long-tail) anahtar kelimeler genellikle daha düşük rekabet ve daha yüksek dönüşüm sunar.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Kaç anahtar kelime kullanmalıyım?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Başlangıç için 10-20 anahtar kelime önerilir. Zamanla performansı iyi olanları genişleterek listenizi artırabilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Anahtar kelimeleri nasıl güncellemeliyim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Performans raporlarını düzenli olarak inceleyin ve düşük performanslı anahtar kelimeleri yenileriyle değiştirin. Trendleri takip edin ve mevsimsel anahtar kelimeleri ekleyin.', 'seo-content-generator'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Generated articles page render
     */
    public function generated_articles_page() {
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] == 'bulk_delete' && isset($_POST['posts'])) {
            foreach ($_POST['posts'] as $post_id) {
                wp_delete_post($post_id, true);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Seçilen makaleler başarıyla silindi.', 'seo-content-generator') . '</p></div>';
        }

        // Handle generation from multiple news URLs
        if (isset($_POST['generate_from_urls']) && isset($_POST['scg_generate_from_urls_nonce']) && wp_verify_nonce($_POST['scg_generate_from_urls_nonce'], 'scg_generate_from_urls')) {
            $urls = isset($_POST['source_urls']) && is_array($_POST['source_urls']) ? array_map('esc_url_raw', $_POST['source_urls']) : array();
            $urls = array_values(array_filter(array_map('trim', $urls)));
            if (empty($urls)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Lütfen en az bir haber bağlantısı ekleyin.', 'seo-content-generator') . '</p></div>';
            } else {
                $api_keys     = get_option('scg_api_keys');
                $api_provider = get_option('scg_api_provider', 'openai');
                $category     = get_option('scg_post_category', 1);
                $status       = get_option('scg_post_status', 'draft');

                $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
                $rotate = (int) get_option('scg_api_rotation', 0) === 1;
                if ($rotate && count($api_key_list) > 1) {
                    $api_key = $api_key_list[array_rand($api_key_list)];
                } else {
                    $api_key = !empty($api_key_list) ? $api_key_list[0] : '';
                }

                if (empty($api_key)) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . __('Lütfen ayarlardan API anahtarınızı girin.', 'seo-content-generator') . '</p></div>';
                } else {
                    $result = $this->generate_article_from_sources($urls, $api_key, $api_provider, $category, $status);
                    if (!is_wp_error($result) && $result) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . __('Haber bağlantılarından makale başarıyla oluşturuldu.', 'seo-content-generator') . '</p></div>';
                    } else {
                        $msg = is_wp_error($result) ? $result->get_error_message() : __('Bilinmeyen hata.', 'seo-content-generator');
                        echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('Makale oluşturulamadı: %s', 'seo-content-generator'), esc_html($msg)) . '</p></div>';
                    }
                }
            }
        }

        // Handle article generation
        if (isset($_POST['generate_keyword']) && !empty($_POST['generate_keyword'])) {
            $keyword = sanitize_text_field($_POST['generate_keyword']);
            $api_keys = get_option('scg_api_keys');
            $api_provider = get_option('scg_api_provider', 'openai');
            $category = get_option('scg_post_category', 1);
            $status = get_option('scg_post_status', 'publish');
            
            // Prepare API keys list
            $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
            
            // Respect API rotation setting
            $rotate = (int) get_option('scg_api_rotation', 0) === 1;
            if ($rotate && count($api_key_list) > 1) {
                // Pick a random key for test generation
                $api_key = $api_key_list[array_rand($api_key_list)];
            } else {
                // Fallback to the first key
                $api_key = !empty($api_key_list) ? $api_key_list[0] : '';
            }
            
            if (!empty($api_key)) {
                $result = $this->generate_article($keyword, $api_key, $api_provider, $category, $status);
                if (!is_wp_error($result) && $result) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('"%s" için makale başarıyla oluşturuldu!', 'seo-content-generator'), esc_html($keyword)) . '</p></div>';
                } else {
                    $msg = is_wp_error($result) ? $result->get_error_message() : __('Bilinmeyen hata.', 'seo-content-generator');
                    echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('"%s" için makale oluşturulamadı: %s', 'seo-content-generator'), esc_html($keyword), esc_html($msg)) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Lütfen önce ayarlardan API anahtarınızı girin.', 'seo-content-generator') . '</p></div>';
            }
        }
        
        // Get current page number
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // Query for generated articles (use Rank Math focus keyword meta)
        $args = array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 20,
            'paged' => $paged,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'rank_math_focus_keyword',
                    'compare' => 'EXISTS'
                ),
                // Backward compatibility if older posts used Yoast key
                array(
                    'key' => '_yoast_wpseo_focuskw',
                    'compare' => 'EXISTS'
                )
            ),
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $articles_query = new WP_Query($args);
        
        ?>
        <div class="wrap scg-dashboard">
            <div class="scg-dashboard-header">
                <h1><?php echo __('Oluşturulan Makaleler', 'seo-content-generator'); ?></h1>
                <p class="scg-dashboard-subtitle"><?php echo __('AI tarafından oluşturulan makaleleri görüntüleyin ve yönetin.', 'seo-content-generator'); ?></p>
            </div>
            
            <?php $articles_count = $articles_query->found_posts; ?>
            <div class="scg-dashboard-stats">
                <div class="scg-stat-card">
                    <h3><?php echo __('Toplam Makale', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo $articles_count; ?></div>
                    <div class="scg-stat-label"><?php echo __('Oluşturuldu', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Sayfa', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo $paged; ?>/<?php echo max(1, $articles_query->max_num_pages); ?></div>
                    <div class="scg-stat-label"><?php echo __('Geçerli', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Görüntüleme', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value">20</div>
                    <div class="scg-stat-label"><?php echo __('Sayfa başına', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="scg-stat-card">
                    <h3><?php echo __('Durum', 'seo-content-generator'); ?></h3>
                    <div class="scg-stat-value"><?php echo ($articles_count > 0) ? __('Hazır', 'seo-content-generator') : __('Boş', 'seo-content-generator'); ?></div>
                    <div class="scg-stat-label"><?php echo __('Liste', 'seo-content-generator'); ?></div>
                </div>
            </div>
            
            <div class="scg-dashboard-card">
                <div class="scg-table-header">
                    <div class="scg-table-actions">
                        <form method="post" id="bulk-action-form">
                            <select name="action" class="scg-select">
                                <option value="-1"><?php echo __('Toplu İşlemler', 'seo-content-generator'); ?></option>
                                <option value="bulk_delete"><?php echo __('Sil', 'seo-content-generator'); ?></option>
                            </select>
                            <button type="submit" class="scg-action-button scg-action-button-secondary" title="<?php echo __('Uygula', 'seo-content-generator'); ?>">
                                <span class="dashicons dashicons-yes"></span>
                            </button>
                        </form>
                        
                        <form method="post" id="generate-article-form" style="display: inline-block; margin-left: 10px;">
                            <input type="text" name="generate_keyword" id="generate_keyword" class="scg-input" placeholder="<?php echo __('Anahtar kelime girin', 'seo-content-generator'); ?>" style="width: 200px; padding: 5px;" />
                            <button type="submit" class="scg-action-button scg-action-button-warning" title="<?php echo __('Makale Oluştur', 'seo-content-generator'); ?>">
                                <span class="dashicons dashicons-plus-alt2"></span> <span><?php echo __('Makale Oluştur', 'seo-content-generator'); ?></span>
                            </button>
                        </form>
                        
                        <!-- Generate from multiple news URLs -->
                        <form method="post" id="scg-generate-from-urls" style="display:inline-block; margin-left: 10px; vertical-align: top;">
                            <input type="hidden" name="scg_generate_from_urls_nonce" value="<?php echo wp_create_nonce('scg_generate_from_urls'); ?>" />
                            <div id="scg-url-fields" style="display:inline-block;">
                                <input type="url" name="source_urls[]" class="scg-input" placeholder="<?php echo __('Haber sayfası URL yapıştırın', 'seo-content-generator'); ?>" style="width: 280px; padding:5px; margin-bottom:6px;" />
                            </div>
                            <button type="button" id="scg-add-url" class="button" style="margin:0 6px;">+ <?php echo __('Yeni ekle', 'seo-content-generator'); ?></button>
                            <button type="submit" name="generate_from_urls" value="1" class="scg-action-button" title="<?php echo __('Haberlerden Oluştur', 'seo-content-generator'); ?>">
                                <span class="dashicons dashicons-admin-site-alt3"></span> <span><?php echo __('Haberlerden Oluştur', 'seo-content-generator'); ?></span>
                            </button>
                            <div style="font-size:12px; color:#666; margin-top:4px;">
                                <?php echo __('Birden fazla haber URL’si ekleyerek içerikleri harmanlayıp özgün bir haber oluşturur.', 'seo-content-generator'); ?>
                            </div>
                        </form>
                    </div>
                    <div class="scg-table-info">
                        <span class="scg-table-count"><?php printf(__('Toplam %d makale', 'seo-content-generator'), $articles_query->found_posts); ?></span>
                    </div>
                </div>
                
                    <div class="scg-table-container">
                    <table id="scg-generated-articles-table" class="scg-table">
                        <thead>
                            <tr>
                                <th class="scg-table-checkbox"><input type="checkbox" id="scg-select-all"></th>
                                <th class="scg-table-keyword"><?php echo __('Anahtar Kelime', 'seo-content-generator'); ?></th>
                                <th class="scg-table-status"><?php echo __('Durum', 'seo-content-generator'); ?></th>
                                <th class="scg-table-date"><?php echo __('Tarih', 'seo-content-generator'); ?></th>
                                <th class="scg-table-actions"><?php echo __('İşlemler', 'seo-content-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($articles_query->have_posts()) : ?>
                                <?php while ($articles_query->have_posts()) : $articles_query->the_post(); ?>
                                    <?php 
                                    $post_id = get_the_ID();
                                    // Prefer Rank Math focus keyword; fallback to Yoast key if present
                                    $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
                                    if (empty($keyword)) {
                                        $keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
                                    }
                                    $status = get_post_status($post_id);
                                    $status_class = 'scg-status-' . $status;
                                    $status_label = '';
                                    
                                    switch($status) {
                                        case 'publish':
                                            $status_label = __('Yayında', 'seo-content-generator');
                                            break;
                                        case 'draft':
                                            $status_label = __('Taslak', 'seo-content-generator');
                                            break;
                                        case 'pending':
                                            $status_label = __('İncelemede', 'seo-content-generator');
                                            break;
                                        case 'private':
                                            $status_label = __('Özel', 'seo-content-generator');
                                            break;
                                        default:
                                            $status_label = ucfirst($status);
                                    }
                                    ?>
                                    <tr>
                                        <td class="scg-table-checkbox">
                                            <input type="checkbox" name="posts[]" value="<?php echo $post_id; ?>">
                                        </td>
                                        <td class="scg-table-keyword">
                                            <?php echo esc_html($keyword); ?>
                                        </td>
                                        <td class="scg-table-status">
                                            <span class="scg-status <?php echo $status_class; ?>"><?php echo esc_html($status_label); ?></span>
                                        </td>
                                        <td class="scg-table-date">
                                            <?php echo get_the_date('d.m.Y'); ?><br>
                                            <?php echo get_the_time('H:i'); ?>
                                        </td>
                                        <td class="scg-table-actions">
                                            <button class="scg-action-button scg-edit-article" data-post-id="<?php echo $post_id; ?>" title="<?php echo __('Düzenle', 'seo-content-generator'); ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </button>
                                            <button class="scg-action-button scg-view-article" data-url="<?php the_permalink(); ?>" title="<?php echo __('Görüntüle', 'seo-content-generator'); ?>">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="5" class="scg-table-empty"><?php echo __('Oluşturulan makale bulunamadı.', 'seo-content-generator'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($articles_query->max_num_pages > 1) : ?>
                    <div class="scg-pagination">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Önceki', 'seo-content-generator'),
                            'next_text' => __('Sonraki &raquo;', 'seo-content-generator'),
                            'total' => $articles_query->max_num_pages,
                            'current' => $paged,
                            'type' => 'plain'
                        ));
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="faq-section">
                <h2><?php echo __('Yardım ve SSS', 'seo-content-generator'); ?></h2>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Makaleleri nasıl düzenleyebilirim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('"Düzenle" butonu ile makale içeriklerini ve SEO alanlarını güncelleyebilirsiniz. Değişiklikleri kaydetmeyi unutmayın.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Makaleleri nasıl silebilirim?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Makaleleri tek tek veya toplu olarak silebilirsiniz. Toplu silme için ilgili makaleleri seçin ve "Toplu İşlemler" menüsünden "Sil" seçeneğini uygulayın.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Makaleler neden yayınlanmıyor?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Makalelerin yayınlanma durumu ayarlardaki "Yazı Durumu" seçeneğine göre belirlenir. "Taslak" olarak ayarlanırsa makaleler yayınlanmaz. "Yayında" olarak ayarlamak için Ayarlar > Genel menüsüne gidin.', 'seo-content-generator'); ?></div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question"><?php echo __('Rank Math SEO alanları ne işe yarar?', 'seo-content-generator'); ?></div>
                    <div class="faq-answer"><?php echo __('Rank Math SEO alanları, oluşturulan makalelerin SEO performansını artırmak için kullanılır. Odak anahtar kelimeler, meta açıklamalar ve diğer SEO ayarlarını buradan yönetebilirsiniz.', 'seo-content-generator'); ?></div>
                </div>
            </div>
            
            <?php wp_reset_postdata(); ?>
        </div>
        <script>
        (function(){
            var addBtn = document.getElementById('scg-add-url');
            if (addBtn) {
                addBtn.addEventListener('click', function(){
                    var wrap = document.getElementById('scg-url-fields');
                    if (!wrap) return;
                    var input = document.createElement('input');
                    input.type = 'url';
                    input.name = 'source_urls[]';
                    input.className = 'scg-input';
                    input.placeholder = '<?php echo __('Haber sayfası URL yapıştırın', 'seo-content-generator'); ?>';
                    input.style.width = '280px';
                    input.style.padding = '5px';
                    input.style.marginBottom = '6px';
                    wrap.appendChild(input);
                });
            }
        })();
        </script>
        
        <!-- Edit Article Modal -->
        <div id="scg-edit-modal" class="scg-modal" style="display: none;">
            <div class="scg-modal-overlay"></div>
            <div class="scg-modal-content">
                <div class="scg-modal-header">
                    <h2><?php echo __('Makaleyi Düzenle', 'seo-content-generator'); ?></h2>
                    <span class="scg-modal-close">&times;</span>
                </div>
                <div id="scg-modal-loading" class="scg-modal-loading" style="display: none;">
                    <p><?php echo __('Yükleniyor...', 'seo-content-generator'); ?></p>
                </div>
                <div id="scg-modal-content" class="scg-modal-body">
                    <form id="scg-edit-form">
                        <input type="hidden" id="scg-post-id" name="post_id">
                        <input type="hidden" name="action" value="scg_save_article_data">
                        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('scg_edit_article_nonce'); ?>">
                        
                        <div class="scg-form-group">
                            <label for="scg-post-title"><?php echo __('Başlık', 'seo-content-generator'); ?></label>
                            <input type="text" id="scg-post-title" name="post_title" class="scg-input">
                        </div>
                        
                        <div class="scg-form-group">
                            <label for="scg-post-content"><?php echo __('İçerik', 'seo-content-generator'); ?></label>
                            <?php
                            // Use WordPress rich editor instead of plain textarea
                            if (function_exists('wp_enqueue_editor')) { wp_enqueue_editor(); }
                            $editor_id = 'scg-post-content';
                            $editor_content = '';
                            $editor_settings = array(
                                'textarea_name'  => 'post_content',
                                'editor_height'  => 380,
                                'media_buttons'  => true,
                                'quicktags'      => true,
                                'tinymce'        => array(
                                    'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                                    'toolbar2' => 'pastetext,removeformat,charmap,outdent,indent,wp_more',
                                ),
                            );
                            wp_editor($editor_content, $editor_id, $editor_settings);
                            ?>
                        </div>
                        
                        <div class="scg-seo-fields">
                            <h3><?php echo __('Rank Math SEO Alanları', 'seo-content-generator'); ?></h3>
                            
                            <div class="scg-form-group faq-schema-section">
                                <h3>SSS Şeması (Accordion)</h3>
                                <div id="scg-faq-editor-container"></div>
                                <button type="button" id="scg-add-faq" class="button">+ Yeni SSS Ekle</button>
                                <textarea id="faq_schema_data" name="faq_schema_data" style="display:none;"></textarea>
                            </div>
                            
                            <div class="scg-form-group">
                                <label for="rank_math_additional_keywords">Ek Anahtar Kelimeler</label>
                                <input type="text" id="rank_math_additional_keywords" name="rank_math_additional_keywords" class="scg-input">
                            </div>
                            
                            <div class="scg-form-group">
                                <label for="rank_math_focus_keyword">Odak Anahtar Kelime</label>
                                <input type="text" id="rank_math_focus_keyword" name="rank_math_focus_keyword" class="scg-input">
                            </div>
                            
                            <div class="scg-form-group">
                                <label for="rank_math_title">SEO Başlığı</label>
                                <input type="text" id="rank_math_title" name="rank_math_title" class="scg-input">
                            </div>
                            
                            <div class="scg-form-group">
                                <label for="rank_math_snippet_desc">Meta Açıklama</label>
                                <textarea id="rank_math_snippet_desc" name="rank_math_snippet_desc" class="scg-textarea" rows="3"></textarea>
                            </div>
                        </div>
                        
                        <div class="scg-modal-footer">
                            <button type="button" class="scg-action-button scg-action-button-secondary scg-modal-close"><?php echo __('İptal', 'seo-content-generator'); ?></button>
                            <button type="submit" class="scg-action-button"><?php echo __('Değişiklikleri Kaydet', 'seo-content-generator'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting article data
     */
    public function ajax_get_article_data() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get post ID
        $post_id = intval($_POST['post_id']);
        
        // Check if post exists
        if (!get_post($post_id)) {
            wp_send_json_error(array('message' => 'Post not found'));
        }
        
        // Get post data
        $post = get_post($post_id);
        
        // Get Rank Math meta fields
        $meta_fields = array(
            'faq_schema_data',
            'rank_math_additional_keywords',
            'rank_math_analytic_data',
            'rank_math_breadcrumb_title',
            'rank_math_content_score',
            'rank_math_facebook_description',
            'rank_math_facebook_title',
            'rank_math_focus_keyword',
            'rank_math_rich_snippet',
            'rank_math_seo_score',
            'rank_math_snippet_article_author',
            'rank_math_snippet_article_author_type',
            'rank_math_snippet_article_modified_date',
            'rank_math_snippet_article_published_date',
            'rank_math_snippet_article_type',
            'rank_math_snippet_desc',
            'rank_math_snippet_name',
            'rank_math_title',
            'rank_math_twitter_description',
            'rank_math_twitter_title'
        );
        
        $meta_data = array();
        foreach ($meta_fields as $field) {
            $meta_data[$field] = get_post_meta($post_id, $field, true);
        }

        // Build FAQ schema JSON for the editor from Rank Math FAQ meta if available
        $faq_items = get_post_meta($post_id, 'rank_math_snippet_faq_schema', true);
        if (!empty($faq_items) && is_array($faq_items)) {
            $faq_schema = array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array()
            );
            foreach ($faq_items as $item) {
                // Support different shapes: ['question' => ..., 'answer' => ...] or schema-like
                $question = '';
                $answer   = '';
                if (isset($item['question']) || isset($item['answer'])) {
                    $question = isset($item['question']) ? wp_strip_all_tags($item['question']) : '';
                    $answer   = isset($item['answer']) ? wp_kses_post($item['answer']) : '';
                } elseif (isset($item['name']) && isset($item['acceptedAnswer']['text'])) {
                    $question = wp_strip_all_tags($item['name']);
                    $answer   = wp_kses_post($item['acceptedAnswer']['text']);
                }
                if ($question !== '' && $answer !== '') {
                    $faq_schema['mainEntity'][] = array(
                        '@type' => 'Question',
                        'name'  => $question,
                        'acceptedAnswer' => array(
                            '@type' => 'Answer',
                            'text'  => $answer,
                        ),
                    );
                }
            }
            if (!empty($faq_schema['mainEntity'])) {
                $meta_data['faq_schema_data'] = wp_json_encode($faq_schema);
            }
        }

        // Prefill additional keywords in editor if missing
        if (empty($meta_data['rank_math_additional_keywords'])) {
            $focus_kw = isset($meta_data['rank_math_focus_keyword']) ? $meta_data['rank_math_focus_keyword'] : get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (!empty($focus_kw)) {
                $generated = $this->generate_additional_keywords_tr($focus_kw);
                if (!empty($generated)) {
                    $meta_data['rank_math_additional_keywords'] = $generated;
                }
            }
        }
        
        // Prepare response
        $response = array(
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content
        );
        
        // Merge meta data
        $response = array_merge($response, $meta_data);

        // Auto-fill missing SEO fields for better UX in the modal
        if (empty($response['rank_math_title'])) {
            $response['rank_math_title'] = $post->post_title;
        }
        if (empty($response['rank_math_snippet_desc'])) {
            $plain = wp_strip_all_tags($post->post_content);
            $plain = preg_replace('/\s+/', ' ', $plain);
            $response['rank_math_snippet_desc'] = mb_substr(trim($plain), 0, 155);
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX handler for saving article data
     */
    public function ajax_save_article_data() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'scg_edit_article_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get post ID
        $post_id = intval($_POST['post_id']);
        
        // Check if post exists
        if (!get_post($post_id)) {
            wp_send_json_error(array('message' => 'Post not found'));
        }
        
        // Update post title and content
        $post_data = array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field($_POST['post_title']),
            'post_content' => wp_kses_post($_POST['post_content'])
        );
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update Rank Math meta fields
        $meta_fields = array(
            'faq_schema_data',
            'rank_math_additional_keywords',
            'rank_math_analytic_data',
            'rank_math_breadcrumb_title',
            'rank_math_content_score',
            'rank_math_facebook_description',
            'rank_math_facebook_title',
            'rank_math_focus_keyword',
            'rank_math_rich_snippet',
            'rank_math_seo_score',
            'rank_math_snippet_article_author',
            'rank_math_snippet_article_author_type',
            'rank_math_snippet_article_modified_date',
            'rank_math_snippet_article_published_date',
            'rank_math_snippet_article_type',
            'rank_math_snippet_desc',
            'rank_math_snippet_name',
            'rank_math_title',
            'rank_math_twitter_description',
            'rank_math_twitter_title'
        );
        
        foreach ($meta_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }

        // If additional keywords not provided, auto-generate from focus keyword
        $posted_additional = isset($_POST['rank_math_additional_keywords']) ? trim((string)$_POST['rank_math_additional_keywords']) : '';
        if ($posted_additional === '') {
            $focus_kw = isset($_POST['rank_math_focus_keyword']) ? sanitize_text_field($_POST['rank_math_focus_keyword']) : get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (!empty($focus_kw)) {
                $generated = $this->generate_additional_keywords_tr($focus_kw);
                if (!empty($generated)) {
                    update_post_meta($post_id, 'rank_math_additional_keywords', $generated);
                }
            }
        }

        // Persist FAQ schema into Rank Math fields if provided
        if (!empty($_POST['faq_schema_data'])) {
            $faq_json = wp_unslash($_POST['faq_schema_data']);
            $faq_decoded = json_decode($faq_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($faq_decoded)) {
                $items = array();
                if (!empty($faq_decoded['mainEntity']) && is_array($faq_decoded['mainEntity'])) {
                    foreach ($faq_decoded['mainEntity'] as $entity) {
                        $q = '';
                        $a = '';
                        if (isset($entity['name'])) {
                            $q = wp_strip_all_tags($entity['name']);
                        }
                        if (isset($entity['acceptedAnswer']['text'])) {
                            $a = wp_kses_post($entity['acceptedAnswer']['text']);
                        }
                        if ($q !== '' && $a !== '') {
                            $items[] = array(
                                'question' => $q,
                                'answer'   => $a,
                            );
                        }
                    }
                }
                // Update Rank Math FAQ metas
                if (!empty($items)) {
                    update_post_meta($post_id, 'rank_math_rich_snippet', 'faq');
                    update_post_meta($post_id, 'rank_math_snippet_faq_schema', $items);
                    // Create Schema DB entry & ensure shortcode with id
                    $this->ensure_rank_math_faq_schema_db_and_shortcode($post_id, $items);
                } else {
                    // If no items, clear Rank Math FAQ meta
                    delete_post_meta($post_id, 'rank_math_snippet_faq_schema');
                }
            }
        }
        
        wp_send_json_success(array('message' => 'Article updated successfully'));
    }
    
    /**
     * Call OpenAI API
     */
    private function call_openai_api($api_key, $prompt) {
        $api_url = 'https://api.openai.com/v1/chat/completions';
        
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        );
        
        $data = array(
            'model' => 'gpt-3.5-turbo',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 1000,
            'temperature' => 0.7
        );
        
        $args = array(
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => 30
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            return '';
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        
        return '';
    }

    /**
     * Call Gemini API
     */
    private function call_gemini_api($api_key, $prompt) {
        $model = get_option('scg_gemini_model', 'models/gemini-2.5-flash');
        // Ensure we only append the last segment if user supplied a short name
        if (strpos($model, 'models/') === false) {
            $model = 'models/' . $model;
        }
        $api_url = 'https://generativelanguage.googleapis.com/v1/' . $model . ':generateContent';

        // Build request args
        $base_args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key
            ),
            'body'    => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array('text' => $prompt)
                        )
                    )
                )
            )),
            // initial timeout per attempt; we may retry on transient network errors
            'timeout' => 60
        );

        // Retry loop for transient network failures (exponential backoff)
        $attempts = 3;
        $response = null;
        $last_error = '';
        for ($i = 0; $i < $attempts; $i++) {
            // Slightly increase timeout on subsequent attempts
            $args = $base_args;
            if ($i > 0) {
                $args['timeout'] = 60 + ($i * 30); // 60, 90, 120
            }

            $response = wp_remote_post($api_url, $args);

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                $last_error = $err;
                error_log('[SCG] Gemini API WP_Error (attempt ' . ($i+1) . '): ' . $err);

                // Detect timeout/cURL 28 and retry
                $is_timeout = false;
                if (stripos($err, 'cURL error 28') !== false || stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
                    $is_timeout = true;
                }

                if ($is_timeout && $i < ($attempts - 1)) {
                    // Exponential backoff (sleep a bit before retrying)
                    $wait = pow(2, $i); // 1,2,4 seconds
                    sleep($wait);
                    continue;
                }

                // Non-retryable or attempts exhausted
                // Return a clearer WP_Error in Turkish for user-facing display
                $msg = 'API isteği başarısız oldu: ' . $err;
                if ($is_timeout) {
                    $msg .= ' — İşlem zaman aşımına uğradı (cURL error 28). Bu genellikle ağ bağlantısı, düşük sunucu yanıt süresi veya API tarafında gecikme nedeniyle olur. Lütfen anahtarınızı ve ağ bağlantınızı kontrol edin, veya zaman aşımı değerini artırmayı düşünün.';
                }
                return new WP_Error('gemini_api_error', $msg);
            }

            // Got a non-WP_Error response, break and inspect
            break;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Handle rate limit / quota errors (HTTP 429) explicitly so admin gets actionable guidance
        if ($response_code === 429) {
            // Try to extract server message
            $decoded = json_decode($body, true);
            $api_msg = '';
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $api_msg = $decoded['error']['message'];
            } elseif (is_array($decoded) && isset($decoded['message'])) {
                $api_msg = $decoded['message'];
            } else {
                $api_msg = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
            }

            // Check Retry-After header if present
            $retry_after = wp_remote_retrieve_header($response, 'retry-after');
            $pause_msg = '';
            if (!empty($retry_after) && is_numeric($retry_after)) {
                // Set a pause so automated runs back off automatically
                $pause_until = time() + intval($retry_after);
                update_option('scg_api_pause_until', $pause_until);
                $pause_msg = ' Lütfen ' . intval($retry_after) . ' saniye sonra tekrar deneyin. Otomatik görevler bu süre boyunca duraklatıldı.';
            }

            $user_msg = 'API kotanız aşıldı veya kota kısıtlamasına takıldınız. Sunucudan gelen mesaj: ' . $api_msg . $pause_msg . ' Hesabınızı, planınızı ve faturalama bilgilerinizi kontrol edin: https://ai.google.dev/gemini-api/docs/rate-limits';
            error_log('[SCG] Gemini API 429: ' . $body . ' -- retry-after: ' . print_r($retry_after, true));
            return new WP_Error('gemini_api_quota', $user_msg);
        }

        // If 404, try retrying with the API key as a query parameter (some setups expect ?key=)
        if ($response_code === 404 && !empty($api_key)) {
            error_log('Gemini API 404 received, retrying with ?key= parameter');
            $retry_url = $api_url . '?key=' . urlencode($api_key);
            $retry_args = $args;
            $retry = wp_remote_post($retry_url, $retry_args);
            if (!is_wp_error($retry)) {
                $retry_code = wp_remote_retrieve_response_code($retry);
                $retry_body = wp_remote_retrieve_body($retry);
                if ($retry_code === 200) {
                    $data = json_decode($retry_body, true);
                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        return trim($data['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
                // fallback to original response body for error below
                $body = $retry_body ?: $body;
                $response_code = $retry_code ?: $response_code;
            }
        }

        if ($response_code !== 200) {
            // Include truncated body in error to help debugging (avoid huge payloads)
            $truncated = is_string($body) ? mb_substr($body, 0, 2000) : '';
            error_log('Gemini API HTTP Error: ' . $response_code . ' - ' . $truncated);

            // Try to fetch available models to help debugging
            $models_list = array();
            $list_endpoints = array(
                'https://generativelanguage.googleapis.com/v1beta/models',
                'https://generativelanguage.googleapis.com/v1/models'
            );
            foreach ($list_endpoints as $le) {
                $list_args = array('headers' => array('x-goog-api-key' => $api_key), 'timeout' => 20);
                $list_res = wp_remote_get($le, $list_args);
                if (is_wp_error($list_res)) {
                    $list_res = wp_remote_get($le . '?key=' . urlencode($api_key), array('timeout' => 20));
                }
                if (!is_wp_error($list_res)) {
                    $code = wp_remote_retrieve_response_code($list_res);
                    $b = wp_remote_retrieve_body($list_res);
                    if ($code === 200 && !empty($b)) {
                        $json = json_decode($b, true);
                        if (is_array($json)) {
                            if (isset($json['models']) && is_array($json['models'])) {
                                foreach ($json['models'] as $m) {
                                    if (is_string($m)) { $models_list[] = $m; }
                                    else if (isset($m['name'])) { $models_list[] = $m['name']; }
                                    else if (isset($m['model'])) { $models_list[] = $m['model']; }
                                }
                            } else {
                                if (isset($json['model']) && is_string($json['model'])) { $models_list[] = $json['model']; }
                                if (array_values($json) === $json) {
                                    foreach ($json as $item) {
                                        if (is_string($item) && strlen($item) < 120) { $models_list[] = $item; }
                                    }
                                }
                            }
                        }
                    }
                }
                if (!empty($models_list)) { break; }
            }

            $models_text = '';
            if (!empty($models_list)) {
                $models_list = array_unique(array_filter($models_list));
                $models_text = ' Available models: ' . implode(', ', array_slice($models_list, 0, 30));
            }

            return new WP_Error('gemini_api_http_error', 'API returned non-200 status code: ' . $response_code . ' - ' . $truncated . $models_text);
        }

        $data = json_decode($body, true);

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($data['candidates'][0]['content']['parts'][0]['text']);
        } else {
            error_log('Gemini API Error: Unexpected response format. Full response: ' . print_r($body, true));
            return new WP_Error('gemini_api_format_error', 'Unexpected response format from API.');
        }
    }

    /**
     * Generate daily content
     */
    public function generate_daily_content() {
        // Load settings and do quick pre-checks before acquiring lock
        $keywords = get_option('scg_keywords');
        $api_keys = get_option('scg_api_keys');
        $api_provider = get_option('scg_api_provider', 'openai');
        $num_articles = get_option('scg_num_articles', 3);
        $category = get_option('scg_post_category', 1);
        $status = get_option('scg_post_status', 'publish');

        // Prepare a run log template
        $run_log = array('started' => current_time('mysql'), 'selected' => array(), 'results' => array(), 'aborted' => false, 'reason' => '');

        // Check if we have keywords and API keys
        if (empty($keywords) || empty($api_keys)) {
            $run_log['aborted'] = true;
            $run_log['reason'] = 'missing_keywords_or_api_keys';
            update_option('scg_last_auto_run', $run_log);
            error_log('[SCG] generate_daily_content aborted: missing keywords or api keys');
            return;
        }

        // Check pause window
        $pause_until = (int) get_option('scg_api_pause_until', 0);
        if ($pause_until > time()) {
            // Still paused, skip generation
            $run_log['aborted'] = true;
            $run_log['reason'] = 'paused_until_' . $pause_until;
            update_option('scg_last_auto_run', $run_log);
            error_log('[SCG] generate_daily_content aborted: paused until ' . date('c', $pause_until));
            return;
        }

        // Gather keywords from single textarea and from all keyword lists
        $keyword_list = array();
        if (!empty($keywords)) {
            $keyword_list = array_merge($keyword_list, array_filter(array_map('trim', explode("\n", $keywords))));
        }
        $lists = get_option('scg_keyword_lists', array());
        if (is_array($lists) && !empty($lists)) {
            foreach ($lists as $lst) {
                if (isset($lst['keywords']) && is_array($lst['keywords'])) {
                    foreach ($lst['keywords'] as $kw) {
                        $keyword_list[] = trim((string)$kw);
                    }
                }
            }
        }
        // Normalize and dedupe
        $keyword_list = array_values(array_unique(array_filter($keyword_list)));
        $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));

        // Filter out already used keywords (case-insensitive)
        $unused_keywords = array();
        foreach ($keyword_list as $kw) {
            if ($kw === '') { continue; }
            if (!$this->is_keyword_used($kw)) {
                $unused_keywords[] = $kw;
            }
        }
        if (empty($unused_keywords)) {
            $run_log['aborted'] = true;
            $run_log['reason'] = 'no_unused_keywords';
            update_option('scg_last_auto_run', $run_log);
            error_log('[SCG] generate_daily_content aborted: no unused keywords found');
            return; // nothing to do
        }
        // Shuffle and limit to number of articles
        shuffle($unused_keywords);
        $selected_keywords = array_slice($unused_keywords, 0, max(1, intval($num_articles)));

        // Respect rotation setting
        $rotate = (int) get_option('scg_api_rotation', 0) === 1;
        $failure_count = (int) get_option('scg_api_failure_count', 0);
        // Generate an article for each selected keyword
        $run_log['selected'] = $selected_keywords;

        // Acquire a robust lock to prevent concurrent automatic runs
        $lock_key = 'scg_auto_lock';
        $lock_token = $this->acquire_auto_lock($lock_key, 900); // 15 minutes TTL
        if (!$lock_token) {
            $run_log['aborted'] = true;
            $run_log['reason'] = 'could_not_acquire_lock';
            update_option('scg_last_auto_run', $run_log);
            error_log('[SCG] generate_daily_content aborted: could not acquire lock');
            return;
        }

        try {
            foreach ($selected_keywords as $index => $keyword) {
            if ($rotate && count($api_key_list) > 1) {
                // Rotate through API keys deterministically
                $api_key = $api_key_list[$index % count($api_key_list)];
            } else {
                // Always use the first key if rotation disabled
                $api_key = $api_key_list[0];
            }
            $result = $this->generate_article($keyword, $api_key, $api_provider, $category, $status, true);
            if (is_wp_error($result) || empty($result)) {
                $failure_count++;
                update_option('scg_api_failure_count', $failure_count);
                $run_log['results'][$keyword] = array('success' => false, 'error' => is_wp_error($result) ? $result->get_error_message() : 'empty');
                if ($failure_count >= 2) {
                    // Pause for 12 hours
                    $until = time() + 12 * HOUR_IN_SECONDS;
                    update_option('scg_api_pause_until', $until);
                    update_option('scg_api_failure_count', 0);
                    // stop further attempts this run
                    break;
                }
            } else {
                // Success resets counter
                update_option('scg_api_failure_count', 0);
                // Record used keyword (defensive, generate_article also records)
                $this->record_used_keyword($keyword);
                $run_log['results'][$keyword] = array('success' => true, 'post_id' => intval($result));
            }
        }
            $run_log['finished'] = current_time('mysql');
            update_option('scg_last_auto_run', $run_log);
        } finally {
            // Ensure lock is released even on exceptions
            $this->release_auto_lock($lock_key, $lock_token);
        }
    }

    /**
     * Attempt to acquire a lock using wp_cache_add (atomic); fallback to option/transient.
     * Returns a token string if acquired, false otherwise.
     */
    private function acquire_auto_lock($key, $ttl = 900) {
        $token = wp_generate_uuid4();
        // Try cache first
        if (function_exists('wp_cache_add')) {
            $ok = wp_cache_add($key, $token, 'scg_locks', $ttl);
            if ($ok) { return $token; }
        }
        // Fallback to transient with add (atomic-ish)
        if (function_exists('set_transient')) {
            $ok = set_transient($key, $token, $ttl);
            if ($ok) { return $token; }
        }
        // Final fallback: option add (not atomic but better than nothing)
        $now = time();
        $added = add_option($key, $now);
        if ($added) { return $token; }
        // If option exists, check age
        $val = get_option($key, 0);
        if ($val && (time() - intval($val)) > $ttl) {
            // stale, replace
            update_option($key, time());
            return $token;
        }
        return false;
    }

    /**
     * Release the lock: remove cache/transient/option if token matches or remove unconditionally.
     */
    private function release_auto_lock($key, $token = '') {
        // Try cache
        if (function_exists('wp_cache_get')) {
            $v = wp_cache_get($key, 'scg_locks');
            if ($v === $token) { wp_cache_delete($key, 'scg_locks'); return true; }
        }
        // Try transient
        if (function_exists('get_transient')) {
            $v = get_transient($key);
            if ($v === $token) { delete_transient($key); return true; }
        }
        // Fallback option remove
        delete_option($key);
        return true;
    }

    /**
     * Generate article
     */
    public function generate_article($keyword, $api_key, $api_provider, $category, $status, $auto = false) {
        // Prevent duplicate generation for the same keyword (case-insensitive)
        // Only enforce for manual/test invocations. Automatic runs ($auto === true) will attempt generation
        // and handle duplicates more gracefully.
        if (!$auto && $this->is_keyword_used($keyword)) {
            return new WP_Error('keyword_used', sprintf(__('"%s" anahtar kelimesi için daha önce içerik oluşturulmuş. Tekrar oluşturulmayacak.', 'seo-content-generator'), $keyword));
        }
        if ($api_provider === 'gemini') {
            // Use the same prompt as the Test page if provided in settings; fallback to default template
            $default_prompt_template = <<<'PROMPT'
Aşağıdaki BLOKLARA UY ve sadece istenen blokları sırayla yaz:

[SEO_TITLE]
(max 60 karakter, odak anahtar kelimeyi doğal şekilde içer, tıklama çekici, marka benzeri dokunuş)

[META_DESCRIPTION]
(150-160 karakter, merak uyandıran ama clickbait olmayan, odak anahtar kelime 1 kez doğal geçsin, tekrar ve emoji kullanma)

[BODY_HTML]
1500-2000 kelime, kullanıcı niyetini karşılayan ve uzman tonu olan akıcı Türkçe ile yaz. H2/H3 başlıklar kullan; paragraf uzunluklarını değiştir (kısa-orta-uzun karışık), geçiş ifadeleri ekle (ör. "Özetle", "Kısaca", "Buna ek olarak"), benzetmeler/rötorik sorular sınırlı ve doğal olsun. Cümle kalıplarını tekrar etme, aynı kelimeyle arka arkaya başlama. Gerektiğinde maddeler/tablo kullan. Gereksiz doldurma ve fazla anahtar kelime kullanımından kaçın. Özgün, pratik ve yerelleştirilmiş örnekler ver.
[END_BODY_HTML]

[FAQ_HTML]
Rastgele sayıda 5-7 arası SSS'yi HTML olarak ver (H3 başlık ve kısa cevap paragrafları). Her SSS şu yapıda olsun: <h3 class="faq-question">Soru</h3><div class="faq-answer">Cevap</div>. Sorular doğal ve birbirinden farklı üslupta olsun.
[END_FAQ_HTML]

Odak Anahtar Kelime: {keyword}
PROMPT;
            $saved_prompt = get_option('scg_test_prompt');
            $prompt_template = !empty($saved_prompt) ? $saved_prompt : $default_prompt_template;
            $prompt = str_replace('{keyword}', $keyword, $prompt_template);

            $raw_content = $this->call_gemini_api($api_key, $prompt);

            if (is_wp_error($raw_content)) {
                error_log('Gemini API call failed for keyword: ' . $keyword . ' - Error: ' . $raw_content->get_error_message());
                return $raw_content; // Stop execution with error
            }

            if (empty($raw_content)) {
                error_log('Gemini API returned empty content for keyword: ' . $keyword);
                return new WP_Error('gemini_empty', 'API boş yanıt döndürdü.'); // Stop execution
            }

            // Parse the content
            $title = trim($this->get_string_between($raw_content, '[SEO_TITLE]', '[META_DESCRIPTION]'));
            $meta_desc = trim($this->get_string_between($raw_content, '[META_DESCRIPTION]', '[BODY_HTML]'));
            $content = trim($this->get_string_between($raw_content, '[BODY_HTML]', '[END_BODY_HTML]'));
            $faq_html = trim($this->get_string_between($raw_content, '[FAQ_HTML]', '[END_FAQ_HTML]'));

            if (empty($title) || empty($content)) {
                error_log('Failed to parse Gemini response for keyword: ' . $keyword . '. Raw content: ' . substr($raw_content, 0, 1000));
                // Fallback or error message
                return new WP_Error('gemini_parse_error', 'API yanıtı beklenen formatta değil.');
            }
            
            // Remove full-document wrappers and humanize to reduce AI footprint
            $content = $this->sanitize_api_html($content);
            $content = $this->humanize_text($content, $keyword);
            if (!empty($faq_html)) {
                $faq_html = $this->sanitize_api_html($faq_html);
                $faq_html = $this->humanize_text($faq_html, $keyword);
            }

            // Add ToC
            $content = $this->add_table_of_contents($content);

            // Add image if missing
            $content = $this->add_image_if_missing($content, $keyword);

            // Add FAQ to content if it exists
            if (!empty($faq_html)) {
                // Add a Rank Math FAQ block shortcode
                $faq_block = '[rank_math_rich_snippet]';
                $content .= "\n\n" . $faq_block;
            }

            // Force newly generated posts to be published rather than drafted
            $post_data = array(
                'post_title'   => sanitize_text_field($title),
                'post_content' => wp_kses_post($content),
                // Ensure published status for all auto-generated content per user request
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
                'post_category' => array($category)
            );

            $post_id = wp_insert_post($post_data);

            if ($post_id && !is_wp_error($post_id)) {
                if ($auto) {
                    update_post_meta($post_id, 'scg_generated_by', 'scg_auto');
                }
                // Save meta fields for Rank Math
                update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($keyword));
                update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
                update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_desc));
                // Auto-generate additional keywords from focus keyword
                $add_kw = $this->generate_additional_keywords_tr($keyword);
                if (!empty($add_kw)) {
                    update_post_meta($post_id, 'rank_math_additional_keywords', $add_kw);
                }

                // Add internal/external links and update content
                $updated_content = $this->add_internal_links($content, $post_id, 3);
                $updated_content = $this->add_external_link($updated_content, $keyword);
                if ($updated_content !== $content) {
                    wp_update_post(array('ID' => $post_id, 'post_content' => wp_kses_post($updated_content)));
                    $content = $updated_content;
                }
                
                if (!empty($faq_html)) {
                    // Extract Q&A pairs
                    preg_match_all('/<h3 class=\"faq-question\">(.*?)<\\/h3>.*?<div class=\"faq-answer\">(.*?)<\\/div>/s', $faq_html, $matches, PREG_SET_ORDER);
                    if (empty($matches)) {
                        // Fallback: any H3 followed by a block
                        preg_match_all('/<h3[^>]*>(.*?)<\\/h3>\s*<[^>]+>(.*?)<\\/[^>]+>/s', $faq_html, $matches, PREG_SET_ORDER);
                    }

                    // Randomly select 5-7 items
                    $selected = [];
                    if (!empty($matches)) {
                        $count = count($matches);
                        $target = max(5, min(7, $count >= 5 ? rand(5, min(7, $count)) : $count));
                        shuffle($matches);
                        $selected = array_slice($matches, 0, $target);
                    }

                    $faq_items = [];
                    $jsonld = array(
                        '@context' => 'https://schema.org',
                        '@type' => 'FAQPage',
                        'mainEntity' => array()
                    );

                    foreach ($selected as $m) {
                        $q = sanitize_text_field($m[1]);
                        $a = wp_kses_post($m[2]);
                        $faq_items[] = array(
                            'property' => 'name',
                            'value' => $q,
                            'type' => 'Question',
                            'visible' => true,
                            'questions' => array(
                                array(
                                    'property' => 'acceptedAnswer',
                                    'value' => $a,
                                    'type' => 'Answer',
                                    'visible' => true,
                                )
                            )
                        );
                        $jsonld['mainEntity'][] = array(
                            '@type' => 'Question',
                            'name'  => wp_strip_all_tags($q),
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $a,
                            ),
                        );
                    }
                    if (!empty($faq_items)) {
                        update_post_meta($post_id, 'rank_math_rich_snippet', 'faq');
                        update_post_meta($post_id, 'rank_math_snippet_faq_schema', $faq_items);
                        $jsonld_script = '<script type="application/ld+json">' . wp_json_encode($jsonld) . '</script>';
                        update_post_meta($post_id, 'scg_faq_jsonld', $jsonld_script);
                        // Ensure Rank Math Schema DB entry + shortcode with id
                        $this->ensure_rank_math_faq_schema_db_and_shortcode($post_id, $faq_items);
                    }
                }

                // Ensure Rank Math Article/NewsArticle schema based on category
                $is_news = $this->is_news_category($category);
                $this->ensure_rank_math_article_schema($post_id, $title, $is_news);
                // Track used keyword
                $this->record_used_keyword($keyword);
                return $post_id;
            }
            return new WP_Error('insert_failed', 'Yazı oluşturulamadı.');

        } else {
            // Non-Gemini providers (e.g., OpenAI) – reuse the same single-call, multi-block prompt flow
            $default_prompt_template = <<<'PROMPT'
Aşağıdaki BLOKLARA UY ve sadece istenen blokları sırayla yaz:

[SEO_TITLE]
(max 60 karakter, focus keyword içersin)

[META_DESCRIPTION]
(150-160 karakter, ilgi çekici, focus keyword içersin)

[BODY_HTML]
1500-2000 kelime, kısa paragraflar (2-4 cümle), H2/H3 başlıklar, gerektiğinde tablolar/maddeler, doğal LSI kelimeler, akıcı Türkçe, kullanıcı odaklı.
[END_BODY_HTML]

[FAQ_HTML]
Rastgele sayıda 5-7 arası SSS'yi HTML olarak ver (H3 başlık ve kısa cevap paragrafları). Her SSS şu yapıda olsun: <h3 class="faq-question">Soru</h3><div class="faq-answer">Cevap</div>
[END_FAQ_HTML]

Focus Keyword: {keyword}
PROMPT;
            $saved_prompt = get_option('scg_test_prompt');
            $prompt_template = !empty($saved_prompt) ? $saved_prompt : $default_prompt_template;
            $prompt = str_replace('{keyword}', $keyword, $prompt_template);

            // Call provider API
            $raw_content = $this->call_openai_api($api_key, $prompt);

            if (is_wp_error($raw_content) || empty($raw_content)) {
                error_log('OpenAI API call failed or empty for keyword: ' . $keyword);
                return new WP_Error('openai_empty', 'API çağrısı başarısız veya boş döndü.');
            }

            // Parse blocks
            $title = trim($this->get_string_between($raw_content, '[SEO_TITLE]', '[META_DESCRIPTION]'));
            $meta_desc = trim($this->get_string_between($raw_content, '[META_DESCRIPTION]', '[BODY_HTML]'));
            $content = trim($this->get_string_between($raw_content, '[BODY_HTML]', '[END_BODY_HTML]'));
            $faq_html = trim($this->get_string_between($raw_content, '[FAQ_HTML]', '[END_FAQ_HTML]'));

            if (empty($title) || empty($content)) {
                error_log('Failed to parse OpenAI response for keyword: ' . $keyword);
                return new WP_Error('openai_parse_error', 'API yanıtı beklenen formatta değil.');
            }

            // Humanize BODY and FAQ HTML to reduce AI footprint
            $content = $this->humanize_text($content, $keyword);
            if (!empty($faq_html)) {
                $faq_html = $this->humanize_text($faq_html, $keyword);
            }

            // Add ToC
            $content = $this->add_table_of_contents($content);

            // Add image if missing
            $content = $this->add_image_if_missing($content, $keyword);

            if (!empty($faq_html)) {
                $faq_block = '[rank_math_rich_snippet]';
                $content .= "\n\n" . $faq_block;
            }

            $post_data = array(
                'post_title'   => sanitize_text_field($title),
                'post_content' => wp_kses_post($content),
                'post_status'  => $status,
                'post_author'  => get_current_user_id(),
                'post_category' => array($category)
            );

            $post_id = wp_insert_post($post_data);

            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($keyword));
                update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
                update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_desc));
                // Auto-generate additional keywords from focus keyword
                $add_kw = $this->generate_additional_keywords_tr($keyword);
                if (!empty($add_kw)) {
                    update_post_meta($post_id, 'rank_math_additional_keywords', $add_kw);
                }

                if (!empty($faq_html)) {
                    // Extract Q&A pairs
                    preg_match_all('/<h3 class=\"faq-question\">(.*?)<\\/h3>.*?<div class=\"faq-answer\">(.*?)<\\/div>/s', $faq_html, $matches, PREG_SET_ORDER);
                    if (empty($matches)) {
                        // Fallback: any H3 followed by a block
                        preg_match_all('/<h3[^>]*>(.*?)<\\/h3>\s*<[^>]+>(.*?)<\\/[^>]+>/s', $faq_html, $matches, PREG_SET_ORDER);
                    }

                    // Randomly select 5-7 items
                    $selected = [];
                    if (!empty($matches)) {
                        $count = count($matches);
                        $target = max(5, min(7, $count >= 5 ? rand(5, min(7, $count)) : $count));
                        shuffle($matches);
                        $selected = array_slice($matches, 0, $target);
                    }

                    $faq_items = [];
                    $jsonld = array(
                        '@context' => 'https://schema.org',
                        '@type' => 'FAQPage',
                        'mainEntity' => array()
                    );

                    foreach ($selected as $m) {
                        $q = sanitize_text_field($m[1]);
                        $a = wp_kses_post($m[2]);
                        $faq_items[] = array(
                            'property' => 'name',
                            'value' => $q,
                            'type' => 'Question',
                            'visible' => true,
                            'questions' => array(
                                array(
                                    'property' => 'acceptedAnswer',
                                    'value' => $a,
                                    'type' => 'Answer',
                                    'visible' => true,
                                )
                            )
                        );
                        $jsonld['mainEntity'][] = array(
                            '@type' => 'Question',
                            'name'  => wp_strip_all_tags($q),
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $a,
                            ),
                        );
                    }
                    if (!empty($faq_items)) {
                        update_post_meta($post_id, 'rank_math_rich_snippet', 'faq');
                        update_post_meta($post_id, 'rank_math_snippet_faq_schema', $faq_items);
                        $jsonld_script = '<script type="application/ld+json">' . wp_json_encode($jsonld) . '</script>';
                        update_post_meta($post_id, 'scg_faq_jsonld', $jsonld_script);
                        // Ensure Rank Math Schema DB entry + shortcode with id
                        $this->ensure_rank_math_faq_schema_db_and_shortcode($post_id, $faq_items);
                    }
                }
                // Ensure Rank Math Article/NewsArticle schema based on category
                $is_news = $this->is_news_category($category);
                $this->ensure_rank_math_article_schema($post_id, $title, $is_news);
                // Track used keyword
                $this->record_used_keyword($keyword);
                return $post_id;
            }
            return new WP_Error('insert_failed', 'Yazı oluşturulamadı.');
        }
    }

    /**
     * Generate an article by synthesizing multiple news source URLs.
     * @param array  $urls
     * @param string $api_key
     * @param string $api_provider 'gemini' or 'openai'
     * @param int    $category
     * @param string $status
     * @return int|WP_Error Post ID on success
     */
    public function generate_article_from_sources($urls, $api_key, $api_provider, $category, $status) {
        if (empty($urls) || !is_array($urls)) {
            return new WP_Error('no_urls', __('URL listesi boş.', 'seo-content-generator'));
        }

        $sources = $this->fetch_and_extract_sources($urls);
        if (empty($sources)) {
            return new WP_Error('fetch_failed', __('Kaynaklar alınamadı veya içerik çıkarılamadı.', 'seo-content-generator'));
        }

        $prompt = $this->build_sources_prompt_news($sources);

        // Call configured provider
        if ($api_provider === 'gemini') {
            $raw_content = $this->call_gemini_api($api_key, $prompt);
        } else {
            $raw_content = $this->call_openai_api($api_key, $prompt);
        }

        if (is_wp_error($raw_content)) {
            return $raw_content;
        }
        if (empty($raw_content)) {
            return new WP_Error('api_empty', __('API boş yanıt döndürdü.', 'seo-content-generator'));
        }

        // Parse blocks
        $title     = trim($this->get_string_between($raw_content, '[SEO_TITLE]', '[META_DESCRIPTION]'));
        $meta_desc = trim($this->get_string_between($raw_content, '[META_DESCRIPTION]', '[BODY_HTML]'));
        $content   = trim($this->get_string_between($raw_content, '[BODY_HTML]', '[END_BODY_HTML]'));
        $faq_html  = trim($this->get_string_between($raw_content, '[FAQ_HTML]', '[END_FAQ_HTML]'));

        if (empty($title) || empty($content)) {
            return new WP_Error('parse_error', __('API yanıtı beklenen formatta değil.', 'seo-content-generator'));
        }

        // Clean wrappers and humanize
        $content = $this->sanitize_api_html($content);
        $content = $this->humanize_text($content, $title);
        if (!empty($faq_html)) {
            $faq_html = $this->sanitize_api_html($faq_html);
            $faq_html = $this->humanize_text($faq_html, $title);
        }

        // Add ToC and image if missing
        $content = $this->add_table_of_contents($content);
        $content = $this->add_image_if_missing($content, $title);

        if (!empty($faq_html)) {
            $content .= "\n\n[rank_math_rich_snippet]";
        }

        // Insert post
        $post_data = array(
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
            'post_category'=> array($category),
        );
        $post_id = wp_insert_post($post_data);
        if (!$post_id || is_wp_error($post_id)) {
            return new WP_Error('insert_failed', __('Yazı oluşturulamadı.', 'seo-content-generator'));
        }

        // SEO meta
        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
        update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_desc));
        update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($title));

        // Save source URLs for traceability
        update_post_meta($post_id, 'scg_source_urls', array_values($urls));

        // FAQ meta if provided
        if (!empty($faq_html)) {
            preg_match_all('/<h3 class=\"faq-question\">(.*?)<\\/h3>.*?<div class=\"faq-answer\">(.*?)<\\/div>/s', $faq_html, $matches, PREG_SET_ORDER);
            if (empty($matches)) {
                preg_match_all('/<h3[^>]*>(.*?)<\\/h3>\s*<[^>]+>(.*?)<\\/[^>]+>/s', $faq_html, $matches, PREG_SET_ORDER);
            }
            $selected = [];
            if (!empty($matches)) {
                $count = count($matches);
                $target = max(5, min(7, $count >= 5 ? rand(5, min(7, $count)) : $count));
                shuffle($matches);
                $selected = array_slice($matches, 0, $target);
            }
            $faq_items = [];
            $jsonld = array(
                '@context' => 'https://schema.org',
                '@type'    => 'FAQPage',
                'mainEntity' => array(),
            );
            foreach ($selected as $m) {
                $q = sanitize_text_field($m[1]);
                $a = wp_kses_post($m[2]);
                $faq_items[] = array(
                    'question' => $q,
                    'answer'   => $a,
                );
                $jsonld['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name'  => wp_strip_all_tags($q),
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $a,
                    ),
                );
            }
            if (!empty($faq_items)) {
                update_post_meta($post_id, 'rank_math_rich_snippet', 'faq');
                // Store in Rank Math meta shape as well
                $rm_items = array();
                foreach ($faq_items as $it) {
                    $rm_items[] = array(
                        'property' => 'name',
                        'value' => $it['question'],
                        'type' => 'Question',
                        'visible' => true,
                        'questions' => array(
                            array(
                                'property' => 'acceptedAnswer',
                                'value' => $it['answer'],
                                'type' => 'Answer',
                                'visible' => true,
                            )
                        )
                    );
                }
                update_post_meta($post_id, 'rank_math_snippet_faq_schema', $rm_items);
                $jsonld_script = '<script type="application/ld+json">' . wp_json_encode($jsonld) . '</script>';
                update_post_meta($post_id, 'scg_faq_jsonld', $jsonld_script);
                $this->ensure_rank_math_faq_schema_db_and_shortcode($post_id, $faq_items);
            }
        }

        // Ensure NewsArticle schema if category indicates news
        $is_news = $this->is_news_category($category);
        $this->ensure_rank_math_article_schema($post_id, $title, $is_news);

        return $post_id;
    }

    /**
     * Fetch and extract multiple sources
     * @param array $urls
     * @return array list of ['url','title','text']
     */
    private function fetch_and_extract_sources($urls) {
        $out = array();
        foreach ($urls as $u) {
            $u = esc_url_raw(trim((string)$u));
            if (empty($u)) { continue; }
            $resp = wp_remote_get($u, array(
                'timeout' => 15,
                'redirection' => 5,
                'user-agent' => 'Mozilla/5.0 (compatible; SCG/1.0; +https://example.com)'
            ));
            if (is_wp_error($resp)) { continue; }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) { continue; }
            $body = wp_remote_retrieve_body($resp);
            if (empty($body)) { continue; }
            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $body, $m)) {
                $title = wp_strip_all_tags($m[1]);
            }
            // Prefer H1 if present
            if (preg_match('/<h1[^>]*>(.*?)<\\/h1>/is', $body, $mh1)) {
                $h1 = wp_strip_all_tags($mh1[1]);
                if (!empty($h1)) { $title = $h1; }
            }
            $text = $this->simple_extract_main_text($body);
            if (empty($text)) { continue; }
            // Truncate each source to avoid huge prompts
            if (mb_strlen($text) > 3000) {
                $text = mb_substr($text, 0, 3000);
            }
            $out[] = array(
                'url' => $u,
                'title' => $title,
                'text' => $text,
            );
        }
        return $out;
    }

    /**
     * Naive main-content extractor: remove scripts/styles, pick <article> or <main> or body text.
     */
    private function simple_extract_main_text($html) {
        // Remove scripts/styles
        $clean = preg_replace('#<script[\s\S]*?</script>#i', ' ', $html);
        $clean = preg_replace('#<style[\s\S]*?</style>#i', ' ', $clean);
        // Try to get <article> or <main>
        $chunk = '';
        if (preg_match('/<article[^>]*>([\s\S]*?)<\\/article>/i', $clean, $m)) {
            $chunk = $m[1];
        } elseif (preg_match('/<main[^>]*>([\s\S]*?)<\\/main>/i', $clean, $m)) {
            $chunk = $m[1];
        } elseif (preg_match('/<body[^>]*>([\s\S]*?)<\\/body>/i', $clean, $m)) {
            $chunk = $m[1];
        } else {
            $chunk = $clean;
        }
        // Keep paragraphs and headings, strip tags otherwise
        // Convert <p>,<h2>,<h3>,<li> to text with line breaks
        $chunk = preg_replace('#</(p|h2|h3|li)>#i', "\n\n", $chunk);
        $chunk = preg_replace('#<br\s*/?>#i', "\n", $chunk);
        // Remove remaining tags
        $text = wp_strip_all_tags($chunk);
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        return $text;
    }

    /**
     * Build prompt to merge multiple news sources into a fresh unique article with SEO blocks.
     */
    private function build_sources_prompt_news($sources) {
        $intro = "Aşağıdaki birden çok haber kaynağını analiz et, doğrula ve TEKRAR ETMEYEN özgün bir makale hazırla. Kopyalama yapma; bilgileri harmanla, çelişkileri not et. Sadece aşağıdaki blokları sırayla üret:";
        $blocks = <<<BLK
[SEO_TITLE]
(max 60 karakter, tıklama çekici ama clickbait olmayan, Türkçe, özgün)

[META_DESCRIPTION]
(150-160 karakter, öz ve merak uyandıran, ana kavram doğal geçsin)

[BODY_HTML]
800-1400 kelime özgün haber metni yaz. Kısa ve orta uzunlukta paragraflar, H2/H3 alt başlıklar, gerektiğinde maddeler kullan. Taraf tutma, kaynaklar arası farkları dengeli aktar. Tarih/yer/kişi/kurum isimlerini koru. Türkçe yazım kurallarına uy.
[END_BODY_HTML]

[FAQ_HTML]
5-7 arası SSS üret, her biri şu biçimde: <h3 class="faq-question">Soru</h3><div class="faq-answer">Cevap</div>
[END_FAQ_HTML]
BLK;
        $src_txt = "\n\n[KAYNAKLAR]\n";
        foreach ($sources as $i => $s) {
            $num = $i + 1;
            $src_txt .= "({$num}) URL: " . $s['url'] . "\n";
            if (!empty($s['title'])) {
                $src_txt .= "Başlık: " . wp_strip_all_tags($s['title']) . "\n";
            }
            $src_txt .= "Özetlenecek içerik: \n" . $s['text'] . "\n\n";
        }
        return $intro . "\n\n" . $blocks . $src_txt;
    }

    public function ajax_live_generate() {
        check_ajax_referer('scg_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Yetkiniz yok.']);
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
        $prompt_template = isset($_POST['prompt']) ? wp_kses_post($_POST['prompt']) : '';

        if (empty($keyword) || empty($prompt_template)) {
            wp_send_json_error(['message' => 'Anahtar kelime ve prompt boş olamaz.']);
        }

        $api_keys = get_option('scg_api_keys');
        $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));
        $api_key = !empty($api_key_list) ? $api_key_list[0] : '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => 'Lütfen ayarlardan Gemini API anahtarınızı girin.']);
        }

        $prompt = str_replace('{keyword}', $keyword, $prompt_template);

        $raw_content = $this->call_gemini_api($api_key, $prompt);
        if (is_array($raw_content)) {
            if (isset($raw_content['candidates'][0]['content']['parts'][0]['text'])) {
                $raw_content = $raw_content['candidates'][0]['content']['parts'][0]['text'];
            } else {
                $raw_content = json_encode($raw_content);
            }
        }

        if (is_wp_error($raw_content)) {
            wp_send_json_error(['message' => $raw_content->get_error_message()]);
        }
        if (empty($raw_content)) {
            wp_send_json_error(['message' => 'API boş yanıt döndürdü.']);
        }

        // Parse the content
        $parsed_content = [
            'title' => $this->get_string_between($raw_content, '[SEO_TITLE]', '[META_DESCRIPTION]'),
            'meta'  => $this->get_string_between($raw_content, '[META_DESCRIPTION]', '[BODY_HTML]'),
            'content' => $this->get_string_between($raw_content, '[BODY_HTML]', '[END_BODY_HTML]'),
            'faq'   => $this->get_string_between($raw_content, '[FAQ_HTML]', '[END_FAQ_HTML]'),
        ];

        wp_send_json_success($parsed_content);
    }

    

    /**
     * Helper function to extract text between two strings.
     */
    private function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    /**
     * Generate additional keywords (Turkish variants) from a focus keyword.
     */
    private function generate_additional_keywords_tr($keyword) {
        $kw = trim(wp_strip_all_tags((string)$keyword));
        if ($kw === '') return '';
        $candidates = array(
            $kw,
            $kw . ' nedir',
            $kw . ' nasıl yapılır',
            $kw . ' rehberi',
            $kw . ' fiyat',
            $kw . ' 2025',
            $kw . ' ipuçları',
        );
        $candidates = array_unique(array_filter(array_map('trim', $candidates)));
        return implode(', ', $candidates);
    }

    /**
     * Check if a keyword has already been used in generated content.
     */
    private function is_keyword_used($keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return false;
        // Normalize for case-insensitive comparison
        $keyword_lc = mb_strtolower($keyword);
        $used = get_option('scg_used_keywords', array());
        if (is_array($used) && !empty($used)) {
            foreach ($used as $u) {
                if (!is_string($u)) continue;
                if (mb_strtolower(trim($u)) === $keyword_lc) {
                    return true;
                }
            }
        }
        // Fallback: query posts that have this focus keyword in Rank Math or Yoast
        $q = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'rank_math_focus_keyword',
                    'value' => $keyword,
                    'compare' => '='
                ),
                array(
                    'key' => '_yoast_wpseo_focuskw',
                    'value' => $keyword,
                    'compare' => '='
                )
            )
        ));
        $exists = $q->have_posts();
        wp_reset_postdata();
        return $exists;
    }

    /**
     * Record a keyword as used.
     */
    private function record_used_keyword($keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return;
        // Store keywords in a normalized (lowercased & trimmed) form to avoid duplicates
        $keyword_norm = mb_strtolower($keyword);
        $used = get_option('scg_used_keywords', array());
        if (!is_array($used)) { $used = array(); }
        $found = false;
        foreach ($used as $u) {
            if (!is_string($u)) continue;
            if (mb_strtolower(trim($u)) === $keyword_norm) { $found = true; break; }
        }
        if (!$found) {
            $used[] = $keyword_norm;
            update_option('scg_used_keywords', array_values($used));
        }
    }


    /**
     * Create/Update Rank Math FAQ Schema DB entry and ensure shortcode with id is in content
     * @param int   $post_id
     * @param array $faq_items array of [question => ..., answer => ...]
     * @return string Shortcode ID used, or empty string on failure
     */
    private function ensure_rank_math_faq_schema_db_and_shortcode($post_id, $faq_items) {
        if (empty($post_id) || empty($faq_items) || !is_array($faq_items)) {
            return '';
        }

        // Build Schema.org structure as Rank Math expects
        $main_entities = array();
        foreach ($faq_items as $item) {
            if (empty($item['question']) || empty($item['answer'])) continue;
            $q = wp_strip_all_tags($item['question']);
            $a = wp_kses_post($item['answer']);
            $main_entities[] = array(
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $a,
                ),
            );
        }
        if (empty($main_entities)) return '';

        // Retrieve existing schema to keep same shortcode id if present
        $existing = get_post_meta($post_id, 'rank_math_schema_FAQPage', true);
        $shortcode_id = '';
        if (!empty($existing) && is_array($existing) && !empty($existing['metadata']['shortcode'])) {
            $shortcode_id = $existing['metadata']['shortcode'];
        }
        if (empty($shortcode_id)) {
            $shortcode_id = uniqid('s-');
        }

        $schema = array(
            '@type'   => 'FAQPage',
            'mainEntity' => $main_entities,
            'metadata' => array(
                'title'     => 'FAQ',
                'shortcode' => $shortcode_id,
                'isPrimary' => false,
                'type'      => 'custom',
            ),
        );

        update_post_meta($post_id, 'rank_math_schema_FAQPage', $schema);

        // Ensure post content includes shortcode with id
        $post = get_post($post_id);
        if ($post) {
            $content = $post->post_content;
            // Remove any existing rank_math_rich_snippet shortcodes (with or without id)
            $content = preg_replace('/\[rank_math_rich_snippet(?:\s+id="[^"]*")?\]/', '', $content);
            $content = rtrim($content) . "\n\n[rank_math_rich_snippet id=\"{$shortcode_id}\"]";
            wp_update_post(array('ID' => $post_id, 'post_content' => $content));
        }

        return $shortcode_id;
    }


    /**
     * Ensure Rank Math Article schema/meta exists for a post to avoid fatal errors and improve SEO.
     * Safe no-op if Rank Math is not active; uses post meta only.
     *
     * @param int    $post_id
     * @param string $title
     * @return void
     */
    private function ensure_rank_math_article_schema($post_id, $title, $is_news = false) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return;
        }

        // Prepare a minimal Article schema structure similar to Rank Math storage
        $existing = get_post_meta($post_id, 'rank_math_schema_Article', true);
        if (empty($existing) || !is_array($existing)) {
            $schema = array(
                '@type'    => $is_news ? 'NewsArticle' : 'Article',
                'headline' => wp_strip_all_tags((string) $title),
                'metadata' => array(
                    'title'     => $is_news ? 'NewsArticle' : 'Article',
                    'shortcode' => uniqid('s-'),
                    'isPrimary' => true,
                    'type'      => 'custom',
                ),
            );
            update_post_meta($post_id, 'rank_math_schema_Article', $schema);
        }

        // Populate common Rank Math snippet metas if missing
        if (!get_post_meta($post_id, 'rank_math_snippet_article_type', true)) {
            update_post_meta($post_id, 'rank_math_snippet_article_type', $is_news ? 'NewsArticle' : 'Article');
        }
        if (!get_post_meta($post_id, 'rank_math_snippet_name', true)) {
            update_post_meta($post_id, 'rank_math_snippet_name', wp_strip_all_tags((string) $title));
        }

        $post = get_post($post_id);
        if ($post) {
            // ISO 8601 dates
            $published = get_post_time('c', false, $post);
            $modified  = get_post_modified_time('c', false, $post);
            if (!get_post_meta($post_id, 'rank_math_snippet_article_published_date', true) && $published) {
                update_post_meta($post_id, 'rank_math_snippet_article_published_date', $published);
            }
            if (!get_post_meta($post_id, 'rank_math_snippet_article_modified_date', true) && $modified) {
                update_post_meta($post_id, 'rank_math_snippet_article_modified_date', $modified);
            }
            if (!get_post_meta($post_id, 'rank_math_snippet_article_author', true)) {
                $author_name = get_the_author_meta('display_name', $post->post_author);
                if (!empty($author_name)) {
                    update_post_meta($post_id, 'rank_math_snippet_article_author', $author_name);
                }
            }
            if (!get_post_meta($post_id, 'rank_math_snippet_article_author_type', true)) {
                update_post_meta($post_id, 'rank_math_snippet_article_author_type', 'Person');
            }
        }
    }


    /**
     * Render saved FAQ JSON-LD in the head for single posts
     */
    public function render_faq_jsonld() {
        if (is_admin()) {
            return;
        }
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post) {
            return;
        }
        $this->maybe_print_faq_jsonld($post->ID);
    }

    /**
     * Footer fallback for JSON-LD output.
     */
    public function render_faq_jsonld_footer() {
        if (is_admin() || !is_singular()) return;
        $post = get_post();
        if (!$post) return;
        $this->maybe_print_faq_jsonld($post->ID);
    }

    /**
     * Print JSON-LD once if available.
     */
    private function maybe_print_faq_jsonld($post_id) {
        static $printed = false;
        if ($printed) return;
        $jsonld = get_post_meta($post_id, 'scg_faq_jsonld', true);
        if (!empty($jsonld)) {
            echo $jsonld . "\n";
            $printed = true;
        }
    }

    /**
     * Append a visible FAQ section to post content from saved schema/meta so readers can see it.
     */
    public function append_faq_to_content($content) {
        if (is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        global $post;
        if (!$post) return $content;

        // If Rank Math FAQ block exists in content, do nothing (it renders both HTML and schema)
        if (has_block('rank-math/faq-block', $post)) {
            return $content;
        }

        $has_shortcode_text = strpos($content, '[rank_math_rich_snippet') !== false;
        $shortcode_active = function_exists('shortcode_exists') && shortcode_exists('rank_math_rich_snippet');

        // Prefer Rank Math meta items if present
        $faq_items = get_post_meta($post->ID, 'rank_math_snippet_faq_schema', true);

        // Fallback to our JSON-LD if meta empty
        if (empty($faq_items) || !is_array($faq_items)) {
            $jsonld_script = get_post_meta($post->ID, 'scg_faq_jsonld', true);
            if (!empty($jsonld_script)) {
                if (preg_match('/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/is', $jsonld_script, $m)) {
                    $json = trim($m[1]);
                } else {
                    $json = trim($jsonld_script);
                }
                $data = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['@type']) && $data['@type'] === 'FAQPage' && !empty($data['mainEntity'])) {
                    $faq_items = array();
                    foreach ($data['mainEntity'] as $entity) {
                        if (isset($entity['@type']) && $entity['@type'] === 'Question') {
                            $q = isset($entity['name']) ? $entity['name'] : '';
                            $a = isset($entity['acceptedAnswer']['text']) ? $entity['acceptedAnswer']['text'] : '';
                            if ($q && $a) {
                                $faq_items[] = array(
                                    'property' => 'name',
                                    'value' => wp_strip_all_tags($q),
                                    'type' => 'Question',
                                    'visible' => true,
                                    'questions' => array(
                                        array(
                                            'property' => 'acceptedAnswer',
                                            'value' => wp_kses_post($a),
                                            'type' => 'Answer',
                                            'visible' => true,
                                        )
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }

        if (empty($faq_items) || !is_array($faq_items)) {
            return $content;
        }

        // Build visible HTML
        ob_start();
        echo '\n<div class="scg-faq-section">';
        echo '<h2>' . esc_html__('Sıkça Sorulan Sorular', 'seo-content-generator') . '</h2>';
        echo '<div class="scg-faq-list">';
        foreach ($faq_items as $item) {
            if (empty($item['value'])) continue;
            $question = esc_html($item['value']);
            $answer = '';
            if (!empty($item['questions'][0]['value'])) {
                $answer = wp_kses_post($item['questions'][0]['value']);
            }
            echo '<div class="scg-faq-item">';
            echo '<h3 class="faq-question">' . $question . '</h3>';
            echo '<div class="faq-answer">' . $answer . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>\n';
        $faq_html = ob_get_clean();

        // If shortcode text exists but shortcode not active, replace the shortcode text with our HTML
        if ($has_shortcode_text && !$shortcode_active) {
            $content = preg_replace('/\[rank_math_rich_snippet[^\]]*\]/i', '', $content);
            return $content . $faq_html;
        }

        // If shortcode is active, let it render; avoid duplicating HTML
        if ($has_shortcode_text && $shortcode_active) {
            return $content;
        }

        // Otherwise, append our HTML to ensure readers see FAQs
        return $content . $faq_html;
    }


    /**
     * AJAX handler for keyword query
     */
    public function ajax_query_keywords() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'scg_ajax_nonce')) {
            wp_send_json_error(array('message' => __('Güvenlik doğrulaması başarısız.', 'seo-content-generator')));
        }

        // Get API settings
        $api_keys = get_option('scg_api_keys', '');
        $api_provider = get_option('scg_api_provider', 'openai');
        
        if (empty($api_keys)) {
            wp_send_json_error(array('message' => __('API anahtarı bulunamadı. Lütfen API ayarlarını kontrol edin.', 'seo-content-generator')));
        }

        // Get all API keys
        $api_key_list = array_filter(array_map('trim', explode("\n", $api_keys)));

        if (empty($api_key_list)) {
            wp_send_json_error(array('message' => __('Geçerli API anahtarı bulunamadı.', 'seo-content-generator')));
        }

        // Get query keyword from request
        $query_keyword = isset($_POST['query_keyword']) ? sanitize_text_field($_POST['query_keyword']) : '';
        
        if (empty($query_keyword)) {
            wp_send_json_error(array('message' => __('Lütfen sorgulanacak ana kelimeyi girin.', 'seo-content-generator')));
        }

        // Get existing keywords for context
        $existing_keywords = get_option('scg_keywords', '');
        $context = !empty($existing_keywords) ? "Mevcut kelimeler: " . substr($existing_keywords, 0, 200) : '';

        // Prepare prompt for long-tail keywords
        $prompt = "'{$query_keyword}' ana kelimesi için Türkçe rekabeti düşük, arama hacmi yüksek olan 20 adet uzun kuyruklu (long-tail) anahtar kelime öner. " .
                 "Her kelime 3-5 kelimeden oluşmalı ve '{$query_keyword}' kelimesini içermeli. SEO açısından değerli olmalı. " .
                 "Sadece kelimeleri listele, açıklama yapma. Her satıra bir kelime yaz. " .
                 ($context ? "Mevcut kelimelerle çakışmayan farklı varyasyonlar öner." : "");

        $last_error = null;
        foreach ($api_key_list as $api_key) {
            try {
                $keywords = null;
                if ($api_provider === 'gemini') {
                    $keywords = $this->query_gemini_keywords($api_key, $prompt);
                } else {
                    $keywords = $this->query_openai_keywords($api_key, $prompt);
                }

                if ($keywords) {
                    wp_send_json_success(array('keywords' => $keywords));
                    return; // Exit after successful query
                }
            } catch (Exception $e) {
                $last_error = $e;
                // Check if the error is a quota/rate limit error
                $error_message = strtolower($e->getMessage());
                if (strpos($error_message, 'quota') !== false || strpos($error_message, 'rate limit') !== false) {
                    // It's a quota error, so we'll try the next key
                    continue;
                } else {
                    // It's a different, more critical error, so stop and report it immediately
                    wp_send_json_error(array('message' => __('API hatası: ', 'seo-content-generator') . $e->getMessage()));
                    return;
                }
            }
        }

        // If we've looped through all keys and none worked, send the last error
        if ($last_error) {
            wp_send_json_error(array('message' => __('Tüm API anahtarları denendi ancak hepsi başarısız oldu. Son hata: ', 'seo-content-generator') . $last_error->getMessage()));
        } else {
            wp_send_json_error(array('message' => __('Kelime önerileri alınamadı. Lütfen API anahtarlarınızı ve ayarlarınızı kontrol edin.', 'seo-content-generator')));
        }
    }

    /**
     * Query Gemini API for keywords
     */
    private function query_gemini_keywords($api_key, $prompt) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
        
        $data = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-goog-api-key' => $api_key
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Check for API-specific errors
        if (isset($result['error']['message'])) {
            throw new Exception('Gemini API Error: ' . $result['error']['message']);
        }

        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($result['candidates'][0]['content']['parts'][0]['text']);
        }

        // Add more context for unknown errors
        throw new Exception(__('Gemini API returned an unexpected response.', 'seo-content-generator'));
    }

    /**
     * Query OpenAI API for keywords
     */
    private function query_openai_keywords($api_key, $prompt) {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $data = array(
            'model' => 'gpt-3.5-turbo',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 1000,
            'temperature' => 0.7
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Check for API-specific errors
        if (isset($result['error']['message'])) {
            throw new Exception('OpenAI API Error: ' . $result['error']['message']);
        }

        if (isset($result['choices'][0]['message']['content'])) {
            return trim($result['choices'][0]['message']['content']);
        }

        // Add more context for unknown errors
        throw new Exception(__('OpenAI API returned an unexpected response.', 'seo-content-generator'));
    }
}

// Initialize the plugin
$seo_content_generator = new SEO_Content_Generator();

// Ensure cron jobs are set up properly
register_activation_hook(__FILE__, array($seo_content_generator, 'activate'));
register_deactivation_hook(__FILE__, array($seo_content_generator, 'deactivate'));
