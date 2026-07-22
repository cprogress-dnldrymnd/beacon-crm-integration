<?php

/**
 * Plugin Name: Beacon CRM Integration for WooCommerce
 * Description: Handles synchronisation of Orders and Course Data to Beacon CRM. Settings managed via Settings > Beacon CRM. Product Fields managed via LearnDash Course Edit screens.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.8.0
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class Beacon_CRM_Integration
 * Core class responsible for handling the Beacon CRM Integration.
 * Utilises OOP principles to encapsulate API interactions, administrative interfaces,
 * and core business logic for order and course synchronisation.
 */
class Beacon_CRM_Integration
{

    /**
     * Settings Option Keys
     */
    const OPT_API_KEY    = 'beacon_crm_api_key';
    const OPT_ACCOUNT_ID = 'beacon_crm_account_id';
    const OPT_API_BASE   = 'beacon_crm_api_base';

    /**
     * Singleton instance.
     *
     * @var Beacon_CRM_Integration|null
     */
    private static $instance = null;

    /**
     * Retrieves the singleton instance of the class.
     *
     * @return Beacon_CRM_Integration
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor: Hooks into WordPress, WooCommerce, and LearnDash actions.
     * Registers settings, custom user columns, metaboxes, and logic hooks.
     */
    private function __construct()
    {
        // Admin Settings Menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX Endpoints
        add_action('wp_ajax_beacon_search_orders', [$this, 'ajax_search_orders']);
        add_action('wp_ajax_beacon_init_bulk_sync', [$this, 'ajax_init_bulk_sync']);
        add_action('wp_ajax_beacon_process_chunk', [$this, 'ajax_process_chunk']);
        add_action('wp_ajax_beacon_save_course_mapping', [$this, 'ajax_save_course_mapping']);
        add_action('wp_ajax_beacon_delete_live_course', [$this, 'ajax_delete_live_course']);

        // Handle Test Sync Submission
        add_action('admin_post_beacon_test_sync', [$this, 'handle_test_sync_submission']);

        // Order & Enrollment Hooks
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete']);
        add_action('woocommerce_payment_complete', [$this, 'sync_live_courses_from_order']); // NEW: Live Course Hook
        
        // Listen directly to LearnDash course access updates (Untouched as requested)
        add_action('learndash_update_course_access', [$this, 'handle_training_logic'], 10, 4);

        // WooCommerce Product Fields (For Live Courses)
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_wc_product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_wc_product_fields']);

        // WooCommerce Variation Fields (Per-Variation Live Course Mapping)
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_live_course_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_live_course_fields'], 10, 2);

        // User Admin Columns
        add_filter('manage_users_columns', [$this, 'add_beacon_id_user_column']);
        add_filter('manage_users_custom_column', [$this, 'fill_beacon_id_user_column'], 10, 3);
        add_filter('manage_sortable_columns', [$this, 'make_beacon_id_column_sortable']);

        // Meta Boxes (LearnDash Courses & Logs)
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post_sfwd-courses', [$this, 'save_learndash_course_fields']);

        // Log Admin Columns & Filters
        add_filter('manage_beaconcrmlogs_posts_columns', [$this, 'add_log_columns']);
        add_action('manage_beaconcrmlogs_posts_custom_column', [$this, 'fill_log_columns'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'add_log_filters']);
        add_action('pre_get_posts', [$this, 'filter_logs_by_meta']);
    }

    /* -------------------------------------------------------------------------- */
    /* WOOCOMMERCE PRODUCT FIELDS (LIVE COURSES)                                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Renders a multi-select field inside WooCommerce Product Data > General Tab
     * to allow assigning custom "Live Courses" to specific products.
     */
    public function render_wc_product_fields()
    {
        global $post;

        $live_courses = get_option('beacon_crm_live_courses', []);
        
        // If there are no live courses, do not clutter the UI
        if (empty($live_courses)) {
            return;
        }

        $current_selection = get_post_meta($post->ID, '_beacon_live_courses', true);
        if (!is_array($current_selection)) {
            $current_selection = [];
        }

        echo '<div class="options_group" id="beacon_crm_live_fields">';
        echo '<h3>Beacon CRM Integration (Live Courses)</h3>';
        echo '<p class="description" style="padding: 0 20px; margin-bottom: 10px;">Select any Live Courses associated with this product. When purchased, these will trigger a training record in Beacon CRM.</p>';

        ?>
        <p class="form-field _beacon_live_courses_field">
            <label for="_beacon_live_courses">Linked Live Courses</label>
            <select id="_beacon_live_courses" name="_beacon_live_courses[]" class="wc-enhanced-select" multiple="multiple" style="width: 50%;">
                <?php foreach ($live_courses as $lc_id => $lc_data): ?>
                    <option value="<?php echo esc_attr($lc_id); ?>" <?php echo in_array($lc_id, $current_selection) ? 'selected="selected"' : ''; ?>>
                        <?php echo esc_html($lc_data['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
        echo '</div>';
    }

    /**
     * Saves the Live Course selections when a WooCommerce product is updated.
     *
     * @param int $post_id Product Post ID.
     */
    public function save_wc_product_fields($post_id)
    {
        if (isset($_POST['_beacon_live_courses']) && is_array($_POST['_beacon_live_courses'])) {
            $sanitized = array_map('sanitize_text_field', wp_unslash($_POST['_beacon_live_courses']));
            update_post_meta($post_id, '_beacon_live_courses', $sanitized);
        } else {
            delete_post_meta($post_id, '_beacon_live_courses');
        }
    }

    /**
     * Renders a per-variation "Linked Live Courses" multiselect inside the
     * WooCommerce variation panel. A variation's own selection overrides the
     * parent product's; leaving it empty falls back to the parent's mapping
     * at sync time (see sync_live_courses_from_order()).
     *
     * @param int     $loop           Variation loop index (used for the field name).
     * @param array   $variation_data
     * @param WP_Post $variation      The variation post object.
     */
    public function render_variation_live_course_fields($loop, $variation_data, $variation)
    {
        $live_courses = get_option('beacon_crm_live_courses', []);

        // If there are no live courses, do not clutter the UI
        if (empty($live_courses)) {
            return;
        }

        $current_selection = get_post_meta($variation->ID, '_beacon_live_courses', true);
        if (!is_array($current_selection)) {
            $current_selection = [];
        }

        ?>
        <div class="options_group form-row form-row-full beacon_crm_variation_live_fields">
            <p class="form-field">
                <label for="_beacon_live_courses_variation_<?php echo esc_attr($loop); ?>">Linked Live Courses</label>
                <select id="_beacon_live_courses_variation_<?php echo esc_attr($loop); ?>" name="_beacon_live_courses_variation[<?php echo esc_attr($loop); ?>][]" class="wc-enhanced-select" multiple="multiple" style="width: 50%;">
                    <?php foreach ($live_courses as $lc_id => $lc_data): ?>
                        <option value="<?php echo esc_attr($lc_id); ?>" <?php echo in_array($lc_id, $current_selection) ? 'selected="selected"' : ''; ?>>
                            <?php echo esc_html($lc_data['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">Leave empty to inherit the parent product's linked Live Courses.</span>
            </p>
        </div>
        <?php
    }

    /**
     * Saves the per-variation Live Course selections when a WooCommerce
     * variation is updated. An empty selection clears the meta so the
     * variation inherits the parent product's mapping.
     *
     * @param int $variation_id Variation Post ID.
     * @param int $i            Variation loop index (matches the posted field name).
     */
    public function save_variation_live_course_fields($variation_id, $i)
    {
        if (isset($_POST['_beacon_live_courses_variation'][$i]) && is_array($_POST['_beacon_live_courses_variation'][$i])) {
            $sanitized = array_map('sanitize_text_field', wp_unslash($_POST['_beacon_live_courses_variation'][$i]));
            update_post_meta($variation_id, '_beacon_live_courses', $sanitized);
        } else {
            delete_post_meta($variation_id, '_beacon_live_courses');
        }
    }

    /**
     * Returns a simple map of [Product ID => "Product Name (#ID)"] for use in
     * the Live Course modal's multiselect.
     *
     * @return array
     */
    private function get_wc_products_for_select()
    {
        $options  = [];
        $products = wc_get_products([
            'limit'   => -1,
            'status'  => 'publish',
            'orderby' => 'title',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        foreach ($products as $product) {
            $options[$product->get_id()] = $product->get_name() . ' (#' . $product->get_id() . ')';

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if (! $variation) {
                        continue;
                    }
                    $attrs = wc_get_formatted_variation($variation, true);
                    $label = $product->get_name() . ($attrs ? ' – ' . $attrs : '') . ' (#' . $variation_id . ')';
                    $options[$variation_id] = $label;
                }
            }
        }

        return $options;
    }

    /**
     * Builds a reverse lookup of Live Course ID => array of linked Product IDs,
     * derived from the '_beacon_live_courses' meta stored on each product.
     *
     * @return array [ (string) course_id => [ (int) product_id, ... ] ]
     */
    private function get_live_course_product_map()
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_beacon_live_courses'
                AND p.post_type IN ('product','product_variation')"
        );

        $map = [];
        foreach ($rows as $row) {
            $value = maybe_unserialize($row->meta_value);
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $course_id) {
                $map[(string) $course_id][] = (int) $row->post_id;
            }
        }

        return $map;
    }

    /**
     * Syncs the product linkage for a given Live Course. Products in $selected_ids
     * gain the course in their '_beacon_live_courses' meta; previously-linked
     * products not in $selected_ids have it removed.
     *
     * @param int|string $course_id     The Live Course ID.
     * @param array      $selected_ids  Product IDs that should be linked.
     */
    private function sync_live_course_products($course_id, $selected_ids)
    {
        $course_key   = (string) $course_id;
        $selected_ids = array_map('intval', (array) $selected_ids);

        // Determine which products currently reference this Live Course.
        $product_map       = $this->get_live_course_product_map();
        $currently_linked  = isset($product_map[$course_key]) ? $product_map[$course_key] : [];

        // Add the course to newly selected products.
        foreach ($selected_ids as $product_id) {
            if (in_array($product_id, $currently_linked, true)) {
                continue;
            }
            $existing = get_post_meta($product_id, '_beacon_live_courses', true);
            if (! is_array($existing)) {
                $existing = [];
            }
            $existing[] = $course_key;
            update_post_meta($product_id, '_beacon_live_courses', array_values(array_unique($existing)));
        }

        // Remove the course from products that were deselected.
        foreach ($currently_linked as $product_id) {
            if (in_array($product_id, $selected_ids, true)) {
                continue;
            }
            $existing = get_post_meta($product_id, '_beacon_live_courses', true);
            if (! is_array($existing)) {
                continue;
            }
            $updated = array_values(array_filter($existing, function ($cid) use ($course_key) {
                return (string) $cid !== $course_key;
            }));
            update_post_meta($product_id, '_beacon_live_courses', $updated);
        }
    }

    /* -------------------------------------------------------------------------- */
    /* LEARNDASH COURSE ADMIN FIELDS                                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Registers meta boxes for LearnDash courses and the custom log post type.
     */
    public function register_meta_boxes()
    {
        add_meta_box(
            'beacon_crm_ld_course_data',
            'Beacon CRM Integration',
            [$this, 'render_learndash_course_fields'],
            'sfwd-courses',
            'normal',
            'high'
        );

        add_meta_box(
            'beacon_crm_log_details', 
            'CRM Log Information', 
            [$this, 'render_log_metabox'], 
            'beaconcrmlogs', 
            'normal', 
            'high'
        );
    }

    /**
     * Render single mapping fields directly inside the LearnDash Course Edit screen.
     */
    public function render_learndash_course_fields($post)
    {
        wp_nonce_field('save_beacon_crm_ld', 'beacon_crm_ld_nonce');

        $b_id   = get_post_meta($post->ID, '_beacon_course_id', true);
        $b_type = get_post_meta($post->ID, '_beacon_course_type', true);

        if (empty($b_id) && empty($b_type)) {
            $legacy_courses = get_post_meta($post->ID, '_beacon_courses_data', true);
            if (is_array($legacy_courses) && !empty($legacy_courses)) {
                $b_id   = $legacy_courses[0]['id'] ?? '';
                $b_type = $legacy_courses[0]['type'] ?? '';
            }
        }

        echo '<div id="beacon_crm_fields" style="padding: 10px 0;">';
        echo '<p class="description">Link this LearnDash Course to a specific Beacon CRM Training record. Triggered automatically upon user enrollment.</p>';

        ?>
        <div class="beacon_course_row" style="border:1px solid #c3c4c7; padding:15px; margin-bottom:15px; background:#f6f7f7;">
            <div class="beacon-row-content">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="_beacon_course_id">Beacon ID</label></th>
                        <td>
                            <input type="text" id="_beacon_course_id" class="regular-text" name="_beacon_course_id" value="<?php echo esc_attr($b_id); ?>" placeholder="Course ID">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="_beacon_course_type">Course Type</label></th>
                        <td>
                            <select id="_beacon_course_type" name="_beacon_course_type">
                                <option value="">Select Type...</option>
                                <option value="MMS" <?php selected($b_type, 'MMS'); ?>>MMS</option>
                                <option value="OceanWatchers" <?php selected($b_type, 'OceanWatchers'); ?>>OceanWatchers</option>
                                <option value="Introduction" <?php selected($b_type, 'Introduction'); ?>>Introduction</option>
                                <option value="Deep Dive" <?php selected($b_type, 'Deep Dive'); ?>>Deep Dive</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
        echo '</div>';
    }

    public function save_learndash_course_fields($post_id)
    {
        if (! isset($_POST['beacon_crm_ld_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['beacon_crm_ld_nonce']), 'save_beacon_crm_ld')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (! current_user_can('edit_post', $post_id)) return;

        $id_val   = isset($_POST['_beacon_course_id']) ? sanitize_text_field(wp_unslash($_POST['_beacon_course_id'])) : '';
        $type_val = isset($_POST['_beacon_course_type']) ? sanitize_text_field(wp_unslash($_POST['_beacon_course_type'])) : '';

        update_post_meta($post_id, '_beacon_course_id', $id_val);
        update_post_meta($post_id, '_beacon_course_type', $type_val);
        delete_post_meta($post_id, '_beacon_courses_data');
    }

    /* -------------------------------------------------------------------------- */
    /* ADMIN SETTINGS PAGE & UI                                                   */
    /* -------------------------------------------------------------------------- */

    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'beacon-crm-settings') !== false) {
            wp_enqueue_script('selectWoo');
            wp_enqueue_style('select2');
        }
    }

    public function add_admin_menu()
    {
        add_menu_page('Beacon CRM', 'Beacon CRM', 'manage_options', 'beacon-crm-settings', [$this, 'render_settings_page'], 'dashicons-networking', 58);
        add_submenu_page('beacon-crm-settings', 'Beacon CRM Settings', 'Settings', 'manage_options', 'beacon-crm-settings', [$this, 'render_settings_page']);
        add_submenu_page('beacon-crm-settings', 'Course Mapping', 'Course Mapping', 'manage_options', 'admin.php?page=beacon-crm-settings&tab=mapping');
    }

    public function register_settings()
    {
        register_setting('beacon_crm_options', self::OPT_API_KEY, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('beacon_crm_options', self::OPT_ACCOUNT_ID, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('beacon_crm_options', self::OPT_API_BASE, ['sanitize_callback' => 'esc_url_raw', 'default' => 'https://api.beaconcrm.org/v1/account/']);

        add_settings_section('beacon_crm_main_section', 'API Configuration', null, 'beacon-crm-settings');
        add_settings_field(self::OPT_API_KEY, 'API Key', [$this, 'render_field_api_key'], 'beacon-crm-settings', 'beacon_crm_main_section');
        add_settings_field(self::OPT_ACCOUNT_ID, 'Account ID', [$this, 'render_field_account_id'], 'beacon-crm-settings', 'beacon_crm_main_section');
        add_settings_field(self::OPT_API_BASE, 'API Base URL', [$this, 'render_field_api_base'], 'beacon-crm-settings', 'beacon_crm_main_section');
    }

    public function render_field_api_key()
    {
        $value = get_option(self::OPT_API_KEY);
        echo '<input type="password" name="' . esc_attr(self::OPT_API_KEY) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_field_account_id()
    {
        $value = get_option(self::OPT_ACCOUNT_ID);
        echo '<input type="text" name="' . esc_attr(self::OPT_ACCOUNT_ID) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_field_api_base()
    {
        $value = get_option(self::OPT_API_BASE, 'https://api.beaconcrm.org/v1/account/');
        echo '<input type="url" name="' . esc_attr(self::OPT_API_BASE) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_settings_page()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'api';
    ?>
        <div class="wrap">
            <h1>Beacon CRM Integration</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=beacon-crm-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">API Configuration</a>
                <a href="?page=beacon-crm-settings&tab=mapping" class="nav-tab <?php echo $active_tab === 'mapping' ? 'nav-tab-active' : ''; ?>">Course Mapping</a>
                <a href="?page=beacon-crm-settings&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">Test Integration</a>
                <a href="?page=beacon-crm-settings&tab=bulk" class="nav-tab <?php echo $active_tab === 'bulk' ? 'nav-tab-active' : ''; ?>">Bulk Date Sync</a>
            </h2>

            <?php $this->render_admin_notices(); ?>

            <div class="beacon-tab-content" style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #c3c4c7;">
                <?php if ($active_tab === 'api') : ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('beacon_crm_options');
                        do_settings_sections('beacon-crm-settings');
                        submit_button();
                        ?>
                    </form>

                <?php elseif ($active_tab === 'mapping') : ?>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h2 style="margin:0;">Course Mappings</h2>
                            <p class="description">Manage mapping for both LearnDash Online Courses and Custom Live Courses.</p>
                        </div>
                        <button type="button" class="button button-primary beacon-add-live-modal-btn">Add Live Course</button>
                    </div>
                    <hr>
                    <?php 
                    $this->render_course_mapping_table(); 
                    $this->render_ajax_mapping_modal();
                    ?>

                <?php elseif ($active_tab === 'test') : ?>
                    <h2>Test Single Order Sync</h2>
                    <p class="description">Search for a specific order to manually trigger the Beacon CRM sync workflow.</p>

                    <style>
                        .beacon-order-search-container .select2-container { width: 100% !important; max-width: 500px; }
                        .beacon-order-search-container .select2-selection--single { min-height: 32px; border: 1px solid #8c8f94; border-radius: 3px; }
                        .beacon-order-search-container .select2-selection__rendered { line-height: 30px; padding-left: 12px; color: #2c3338; }
                        .beacon-order-search-container .select2-selection__arrow { height: 30px; }
                        .beacon-order-search-container .select2-container--focus .select2-selection--single { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
                    </style>

                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="beacon_test_sync">
                        <?php wp_nonce_field('beacon_test_sync_nonce', 'beacon_test_nonce'); ?>
                        <table class="form-table beacon-order-search-container">
                            <tr>
                                <th scope="row"><label for="beacon_test_order_id">Select WooCommerce Order</label></th>
                                <td><select name="beacon_test_order_id" id="beacon_test_order_id" class="wc-order-search" data-placeholder="Search for an order by ID, Name, or Email..." required></select></td>
                            </tr>
                        </table>
                        <?php submit_button('Sync Order Now', 'primary'); ?>
                    </form>
                    <script>
                        jQuery(document).ready(function($) {
                            $('#beacon_test_order_id').selectWoo({
                                ajax: {
                                    url: ajaxurl,
                                    dataType: 'json',
                                    delay: 250,
                                    data: function(params) {
                                        return {
                                            q: params.term,
                                            action: 'beacon_search_orders',
                                            security: '<?php echo wp_create_nonce("beacon_search_orders"); ?>'
                                        };
                                    },
                                    processResults: function(data) { return { results: data }; },
                                    cache: true
                                },
                                minimumInputLength: 1,
                                allowClear: true
                            });
                        });
                    </script>

                <?php elseif ($active_tab === 'bulk') : ?>
                    <h2>Bulk Sync by Date Range</h2>
                    <p class="description">Select a date range to find and push all orders created within that timeframe to Beacon CRM. Processing happens in batches to prevent server timeouts.</p>

                    <div id="beacon-bulk-form-container">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="beacon_date_from">Date From</label></th>
                                <td><input type="date" id="beacon_date_from" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="beacon_date_to">Date To</label></th>
                                <td><input type="date" id="beacon_date_to" required></td>
                            </tr>
                        </table>
                        <p>
                            <button type="button" id="beacon-run-bulk" class="button button-primary">Run Bulk Sync</button>
                        </p>
                    </div>

                    <div id="beacon-progress-container" style="display: none; margin-top: 20px; max-width: 600px;">
                        <div style="background: #e0e0e0; width: 100%; height: 24px; border-radius: 3px; overflow: hidden; position: relative;">
                            <div id="beacon-progress-bar" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                            <span id="beacon-progress-percentage" style="position: absolute; top: 0; left: 0; width: 100%; line-height: 24px; text-align: center; color: #fff; font-weight: bold; mix-blend-mode: difference;">0%</span>
                        </div>
                        <p id="beacon-progress-status" style="font-weight: 600; margin-top: 8px;">Initializing...</p>
                    </div>

                    <script>
                        jQuery(document).ready(function($) {
                            $('#beacon-run-bulk').on('click', function() {
                                const dateFrom = $('#beacon_date_from').val();
                                const dateTo = $('#beacon_date_to').val();

                                if (!dateFrom || !dateTo) {
                                    alert('Please select both a From and To date.');
                                    return;
                                }

                                $('#beacon-bulk-form-container').slideUp();
                                $('#beacon-progress-container').slideDown();
                                $('#beacon-progress-status').text('Locating orders...');

                                $.post(ajaxurl, {
                                    action: 'beacon_init_bulk_sync',
                                    security: '<?php echo wp_create_nonce("beacon_bulk_sync"); ?>',
                                    date_from: dateFrom,
                                    date_to: dateTo
                                }, function(response) {
                                    if (!response.success) {
                                        $('#beacon-progress-status').html('<span style="color:#d63638;">Error: ' + response.data + '</span>');
                                        return;
                                    }

                                    const orderIds = response.data.order_ids;
                                    const totalOrders = response.data.total;
                                    let processedCount = 0;
                                    const chunkSize = 5;

                                    $('#beacon-progress-status').text('Processing 0 of ' + totalOrders + ' orders...');

                                    function processNextChunk() {
                                        if (orderIds.length === 0) {
                                            $('#beacon-progress-bar').css('width', '100%');
                                            $('#beacon-progress-percentage').text('100%');
                                            $('#beacon-progress-status').html('<span style="color:#00a32a;">Sync Complete! Successfully processed ' + totalOrders + ' orders.</span>');
                                            return;
                                        }

                                        const chunk = orderIds.splice(0, chunkSize);

                                        $.post(ajaxurl, {
                                            action: 'beacon_process_chunk',
                                            security: '<?php echo wp_create_nonce("beacon_bulk_sync"); ?>',
                                            order_ids: chunk
                                        }, function(chunkResponse) {
                                            if (chunkResponse.success) {
                                                processedCount += chunk.length;
                                                const percentage = Math.round((processedCount / totalOrders) * 100);

                                                $('#beacon-progress-bar').css('width', percentage + '%');
                                                $('#beacon-progress-percentage').text(percentage + '%');
                                                $('#beacon-progress-status').text('Processing ' + processedCount + ' of ' + totalOrders + ' orders...');

                                                processNextChunk();
                                            } else {
                                                $('#beacon-progress-status').html('<span style="color:#d63638;">Sync failed during chunk processing. Check console.</span>');
                                            }
                                        }).fail(function() {
                                            $('#beacon-progress-status').html('<span style="color:#d63638;">Server error occurred during processing.</span>');
                                        });
                                    }

                                    processNextChunk();
                                });
                            });
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Renders a native WordPress admin table mixing LearnDash Courses and Custom Live Courses.
     */
    private function render_course_mapping_table()
    {
        $filter = isset($_GET['beacon_filter']) ? sanitize_text_field(wp_unslash($_GET['beacon_filter'])) : 'all';
        $paged  = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        $all_items = [];

        // Map of live course ID => linked WooCommerce product IDs (reverse lookup)
        $product_map = $this->get_live_course_product_map();

        // 1. Fetch Live Courses (from wp_options)
        if (in_array($filter, ['all', 'live'])) {
            $live_courses = get_option('beacon_crm_live_courses', []);
            foreach ($live_courses as $lc) {
                $all_items[] = [
                    'source' => 'live',
                    'id'     => $lc['id'],
                    'title'  => $lc['name'],
                    'b_id'   => $lc['beacon_id'],
                    'b_type' => $lc['beacon_type']
                ];
            }
        }

        // 2. Fetch Online Courses (LearnDash)
        if (in_array($filter, ['all', 'online'])) {
            $ld_args = [
                'post_type'      => 'sfwd-courses',
                'posts_per_page' => -1, // Fetch all to combine and manually paginate
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
            ];
            $query = new WP_Query($ld_args);
            foreach ($query->posts as $post) {
                $b_id   = get_post_meta($post->ID, '_beacon_course_id', true);
                $b_type = get_post_meta($post->ID, '_beacon_course_type', true);
                $all_items[] = [
                    'source' => 'learndash',
                    'id'     => $post->ID,
                    'title'  => $post->post_title,
                    'b_id'   => $b_id,
                    'b_type' => $b_type
                ];
            }
        }

        // Handle Array Pagination
        $total_items = count($all_items);
        $max_pages   = ceil($total_items / $per_page);
        $offset      = ($paged - 1) * $per_page;
        $current_items = array_slice($all_items, $offset, $per_page);

        // Filter Controls
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="beacon-crm-settings">';
        echo '<input type="hidden" name="tab" value="mapping">';
        
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<select name="beacon_filter">';
        echo '<option value="all" ' . selected($filter, 'all', false) . '>All Courses</option>';
        echo '<option value="online" ' . selected($filter, 'online', false) . '>Online Courses (LearnDash)</option>';
        echo '<option value="live" ' . selected($filter, 'live', false) . '>Live Courses (Custom)</option>';
        echo '</select>';
        echo '<input type="submit" class="button" value="Filter">';
        echo '</div>';
        
        $this->render_pagination($max_pages, $paged);
        echo '</div></form>';

        // Data Table
        echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
        echo '<thead><tr>';
        echo '<th style="width: 8%;">ID</th>';
        echo '<th style="width: 12%;">Type</th>';
        echo '<th style="width: 28%;">Course Name</th>';
        echo '<th style="width: 17%;">Beacon CRM ID</th>';
        echo '<th style="width: 15%;">Course Type</th>';
        echo '<th style="width: 20%;">Action</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (!empty($current_items)) {
            foreach ($current_items as $item) {
                $status_color = empty($item['b_id']) ? '#d63638' : '#00a32a';
                $source_badge = $item['source'] === 'learndash' 
                    ? '<span style="background:#2271b1; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px;">Online</span>' 
                    : '<span style="background:#d63638; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px;">Live</span>';
                
                echo '<tr id="beacon-row-' . esc_attr($item['id']) . '">';
                echo '<td>' . esc_html(str_replace('lc_', '', $item['id'])) . '</td>';
                echo '<td>' . $source_badge . '</td>';
                echo '<td><strong class="beacon-title-cell">' . esc_html($item['title']) . '</strong></td>';
                
                echo '<td class="beacon-id-cell" style="color:' . $status_color . '; font-weight: 500;">' . (empty($item['b_id']) ? 'Not Mapped' : esc_html($item['b_id'])) . '</td>';
                echo '<td class="beacon-type-cell">' . (empty($item['b_type']) ? '&mdash;' : esc_html($item['b_type'])) . '</td>';
                
                $linked_products = ($item['source'] === 'live' && isset($product_map[(string) $item['id']]))
                    ? implode(',', $product_map[(string) $item['id']])
                    : '';

                echo '<td>';
                echo '<button type="button" class="button button-small beacon-edit-modal-btn"
                        data-source="' . esc_attr($item['source']) . '"
                        data-course-id="' . esc_attr($item['id']) . '"
                        data-beacon-id="' . esc_attr($item['b_id']) . '"
                        data-beacon-type="' . esc_attr($item['b_type']) . '"
                        data-linked-products="' . esc_attr($linked_products) . '"
                        data-course-title="' . esc_attr($item['title']) . '">
                        Edit
                      </button> ';
                
                if ($item['source'] === 'live') {
                    echo '<button type="button" class="button button-small beacon-delete-live-btn" style="color: #d63638; border-color: #d63638;" data-course-id="' . esc_attr($item['id']) . '">Delete</button>';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">No courses found matching this criteria.</td></tr>';
        }

        echo '</tbody>';
        echo '</table>';
        
        echo '<div class="tablenav bottom">';
        $this->render_pagination($max_pages, $paged);
        echo '</div>';
    }

    private function render_pagination($max_pages, $paged)
    {
        if ($max_pages <= 1) return;

        $page_links = paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $max_pages,
            'current'   => $paged,
            'type'      => 'plain'
        ]);

        if ($page_links) {
            echo '<div class="tablenav-pages" style="float: right;">';
            echo '<span class="pagination-links">' . $page_links . '</span>';
            echo '</div>';
        }
    }

    /**
     * Renders the hidden HTML structure and JS logic for the AJAX editing Modal.
     */
    private function render_ajax_mapping_modal()
    {
        ?>
        <style>
            /* Custom product picker (search field + suggestions) for Linked Products.
               Built from scratch to avoid the select2/selectWoo positioning issues inside the modal. */
            #beacon-products-picker { position: relative; }
            #beacon-products-chips {
                display: flex; flex-wrap: wrap; gap: 5px;
                margin-bottom: 6px;
            }
            #beacon-products-chips:empty { margin-bottom: 0; }
            .beacon-product-chip {
                display: inline-flex; align-items: center;
                background: #2271b1; color: #fff;
                border-radius: 3px; padding: 3px 8px;
                font-size: 12px; line-height: 1.4; max-width: 100%;
            }
            .beacon-product-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .beacon-chip-remove {
                cursor: pointer; margin-left: 7px; font-weight: bold;
                color: #cfe3f5; font-size: 14px; line-height: 1;
            }
            .beacon-chip-remove:hover { color: #fff; }
            #beacon-products-search {
                width: 100%; box-sizing: border-box;
                padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px;
            }
            #beacon-products-search:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
            #beacon-products-suggestions {
                display: none; position: absolute; left: 0; right: 0; z-index: 100;
                background: #fff; border: 1px solid #2271b1; border-top: none;
                max-height: 220px; overflow-y: auto;
                box-shadow: 0 4px 8px rgba(0,0,0,0.12);
            }
            .beacon-suggestion {
                padding: 7px 10px; cursor: pointer; font-size: 13px;
                border-bottom: 1px solid #f0f0f1;
            }
            .beacon-suggestion:last-child { border-bottom: none; }
            .beacon-suggestion.is-active, .beacon-suggestion:hover { background: #2271b1; color: #fff; }
            .beacon-suggestion-empty { padding: 8px 10px; font-size: 13px; color: #646970; }
        </style>
        <div id="beacon-mapping-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6);">
            <div id="beacon-modal-box" style="position:relative; background-color:#fff; margin: 10% auto; padding: 0; border: 1px solid #888; width: 600px; border-radius: 4px; box-shadow: 0 3px 6px rgba(0,0,0,0.3);">
                
                <div style="padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; background: #f6f7f7;">
                    <h3 style="margin:0; font-size: 16px;" id="beacon-modal-header">Edit Course Mapping</h3>
                    <span id="beacon-modal-close" style="cursor:pointer; font-size:20px; color:#666;">&times;</span>
                </div>

                <div style="padding: 20px;">
                    <input type="hidden" id="beacon-modal-source" value="">
                    <input type="hidden" id="beacon-modal-course-id" value="">
                    
                    <table class="form-table" style="margin-top: 0;">
                        <tr id="beacon-modal-id-row">
                            <th scope="row" style="padding: 10px 0;"><label for="beacon-modal-custom-id">Course ID</label></th>
                            <td style="padding: 10px 0;">
                                <input type="number" id="beacon-modal-custom-id" class="regular-text" style="width:100%;" min="1">
                                <p class="description" id="beacon-modal-id-desc" style="display:none; font-size: 11px;">Enter a unique integer ID for this live course. Cannot be changed once set.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding: 10px 0;"><label for="beacon-modal-course-title">Course Title</label></th>
                            <td style="padding: 10px 0;">
                                <input type="text" id="beacon-modal-course-title" class="regular-text" style="width:100%;">
                                <p class="description" id="beacon-modal-title-desc" style="display:none; font-size: 11px;">LearnDash course names must be edited within the LearnDash editor.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding: 10px 0;"><label for="beacon-modal-beacon-id">Beacon ID</label></th>
                            <td style="padding: 10px 0;">
                                <input type="text" id="beacon-modal-beacon-id" class="regular-text" style="width:100%;">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding: 10px 0;"><label for="beacon-modal-beacon-type">Course Type</label></th>
                            <td style="padding: 10px 0;">
                                <select id="beacon-modal-beacon-type" style="width:100%;">
                                    <option value="">Select Type...</option>
                                    <option value="MMS">MMS</option>
                                    <option value="OceanWatchers">OceanWatchers</option>
                                    <option value="Introduction">Introduction</option>
                                    <option value="Deep Dive">Deep Dive</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="beacon-modal-products-row">
                            <th scope="row" style="padding: 10px 0; vertical-align: top;"><label for="beacon-products-search">Linked Products</label></th>
                            <td style="padding: 10px 0;">
                                <div id="beacon-products-picker">
                                    <div id="beacon-products-chips"></div>
                                    <input type="text" id="beacon-products-search" placeholder="Search products..." autocomplete="off">
                                    <div id="beacon-products-suggestions"></div>
                                </div>
                                <!-- Hidden data store: holds every product as an option; the custom picker toggles selection here -->
                                <select id="beacon-modal-products" multiple="multiple" style="display:none;">
                                    <?php foreach ($this->get_wc_products_for_select() as $prod_id => $prod_name): ?>
                                        <option value="<?php echo esc_attr($prod_id); ?>"><?php echo esc_html($prod_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" style="font-size: 11px; margin-top: 5px;">Choose which WooCommerce products this Live Course belongs to. Selected products will show this course under "Beacon CRM Integration (Live Courses)".</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="beacon-modal-notice" style="display:none; padding: 10px; margin-top: 15px; border-left: 4px solid;"></div>
                </div>

                <div style="padding: 15px 20px; border-top: 1px solid #ddd; background: #f6f7f7; text-align: right;">
                    <button type="button" class="button" id="beacon-modal-cancel">Cancel</button>
                    <button type="button" class="button button-primary" id="beacon-modal-save">Save Mapping</button>
                    <span class="spinner" id="beacon-modal-spinner" style="float:none; margin-top:4px;"></span>
                </div>

            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var modal = $('#beacon-mapping-modal');
            var $products = $('#beacon-modal-products');

            /* -------------------------------------------------------------- */
            /* Custom Linked Products picker (search box + suggestions list)   */
            /* Built from scratch so it works reliably inside the modal.       */
            /* -------------------------------------------------------------- */
            var $productsSearch = $('#beacon-products-search');
            var $productsChips  = $('#beacon-products-chips');
            var $productsSug    = $('#beacon-products-suggestions');

            // Full catalogue, read once from the hidden <select> options.
            var productCatalog = [];
            $products.find('option').each(function() {
                productCatalog.push({ id: String($(this).val()), name: $(this).text() });
            });

            var selectedProducts = []; // [{id, name}]
            var currentMatches   = [];
            var activeIndex      = -1;

            function findProduct(id) {
                id = String(id);
                for (var i = 0; i < productCatalog.length; i++) {
                    if (productCatalog[i].id === id) return productCatalog[i];
                }
                return null;
            }

            function isSelected(id) {
                id = String(id);
                for (var i = 0; i < selectedProducts.length; i++) {
                    if (selectedProducts[i].id === id) return true;
                }
                return false;
            }

            // Keeps the hidden <select> in sync so the Save handler can read $products.val().
            function syncHiddenSelect() {
                $products.val(selectedProducts.map(function(p) { return p.id; }));
            }

            function renderChips() {
                $productsChips.empty();
                selectedProducts.forEach(function(p) {
                    var chip = $('<span class="beacon-product-chip"></span>');
                    chip.append($('<span></span>').text(p.name));
                    $('<span class="beacon-chip-remove" title="Remove">&times;</span>')
                        .on('click', function() { removeProduct(p.id); })
                        .appendTo(chip);
                    $productsChips.append(chip);
                });
            }

            function addProduct(id) {
                if (isSelected(id)) return;
                var prod = findProduct(id);
                if (!prod) return;
                selectedProducts.push(prod);
                syncHiddenSelect();
                renderChips();
            }

            function removeProduct(id) {
                id = String(id);
                selectedProducts = selectedProducts.filter(function(p) { return p.id !== id; });
                syncHiddenSelect();
                renderChips();
                renderSuggestions($productsSearch.val());
            }

            function hideSuggestions() {
                $productsSug.hide().empty();
                currentMatches = [];
                activeIndex = -1;
            }

            function renderSuggestions(term) {
                term = (term || '').trim().toLowerCase();
                if (term === '') { hideSuggestions(); return; }

                currentMatches = productCatalog.filter(function(p) {
                    return !isSelected(p.id) && p.name.toLowerCase().indexOf(term) !== -1;
                }).slice(0, 30);

                activeIndex = -1;
                $productsSug.empty();

                if (currentMatches.length === 0) {
                    $productsSug.append('<div class="beacon-suggestion-empty">No matching products</div>').show();
                    return;
                }

                currentMatches.forEach(function(p) {
                    $('<div class="beacon-suggestion"></div>')
                        .text(p.name)
                        .on('mousedown', function(e) {
                            e.preventDefault(); // fire before the input loses focus
                            addProduct(p.id);
                            $productsSearch.val('');
                            hideSuggestions();
                            $productsSearch.focus();
                        })
                        .appendTo($productsSug);
                });
                $productsSug.show();
            }

            // Loads the picker from a comma-separated list of product IDs.
            function loadProducts(idsString) {
                var ids = (idsString && idsString.length) ? idsString.split(',') : [];
                selectedProducts = [];
                ids.forEach(function(id) {
                    var prod = findProduct(id);
                    if (prod) selectedProducts.push(prod);
                });
                syncHiddenSelect();
                renderChips();
                $productsSearch.val('');
                hideSuggestions();
            }

            $productsSearch.on('input', function() { renderSuggestions($(this).val()); });
            $productsSearch.on('focus', function() {
                if ($(this).val().trim() !== '') renderSuggestions($(this).val());
            });
            $productsSearch.on('keydown', function(e) {
                if (!$productsSug.is(':visible')) return;
                var items = $productsSug.find('.beacon-suggestion');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                } else if (e.key === 'Enter') {
                    if (activeIndex >= 0 && currentMatches[activeIndex]) {
                        e.preventDefault();
                        addProduct(currentMatches[activeIndex].id);
                        $productsSearch.val('');
                        hideSuggestions();
                    }
                    return;
                } else if (e.key === 'Escape') {
                    hideSuggestions();
                    return;
                } else {
                    return;
                }

                items.removeClass('is-active');
                if (activeIndex >= 0) {
                    var el = items.eq(activeIndex).addClass('is-active')[0];
                    var box = $productsSug[0];
                    if (el.offsetTop < box.scrollTop) {
                        box.scrollTop = el.offsetTop;
                    } else if (el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) {
                        box.scrollTop = el.offsetTop + el.offsetHeight - box.clientHeight;
                    }
                }
            });

            // Close the suggestion list when clicking outside the picker.
            $(document).on('mousedown.beaconproducts', function(e) {
                if (!$(e.target).closest('#beacon-products-picker').length) {
                    hideSuggestions();
                }
            });

            // Shows/hides the Linked Products row (only relevant for Live Courses)
            function toggleProductsRow(source) {
                if (source === 'live') {
                    $('#beacon-modal-products-row').show();
                } else {
                    $('#beacon-modal-products-row').hide();
                }
            }

            // Open Modal for Edit
            $('.beacon-edit-modal-btn').on('click', function() {
                var btn = $(this);
                $('#beacon-modal-header').text('Edit Course Mapping');
                $('#beacon-modal-source').val(btn.attr('data-source'));
                $('#beacon-modal-course-id').val(btn.attr('data-course-id'));
                $('#beacon-modal-course-title').val(btn.attr('data-course-title'));
                $('#beacon-modal-beacon-id').val(btn.attr('data-beacon-id'));
                $('#beacon-modal-beacon-type').val(btn.attr('data-beacon-type'));

                // Handle Course ID display
                $('#beacon-modal-custom-id').val(btn.attr('data-course-id')).prop('readonly', true).css('background', '#f0f0f1');
                $('#beacon-modal-id-desc').hide();

                // Handle Course Title display
                if (btn.attr('data-source') === 'learndash') {
                    $('#beacon-modal-course-title').prop('readonly', true).css('background', '#f0f0f1');
                    $('#beacon-modal-title-desc').show();
                } else {
                    $('#beacon-modal-course-title').prop('readonly', false).css('background', '#fff');
                    $('#beacon-modal-title-desc').hide();
                }

                // Handle Linked Products (Live Courses only)
                var isLive = btn.attr('data-source') === 'live';
                toggleProductsRow(btn.attr('data-source'));
                loadProducts(isLive ? btn.attr('data-linked-products') : '');

                resetModalState();
                modal.fadeIn(200);
            });

            // Open Modal for New Live Course
            $('.beacon-add-live-modal-btn').on('click', function() {
                $('#beacon-modal-header').text('Add Live Course');
                $('#beacon-modal-source').val('live');
                $('#beacon-modal-course-id').val('new');
                
                $('#beacon-modal-custom-id').val('').prop('readonly', false).css('background', '#fff');
                $('#beacon-modal-id-desc').show();

                $('#beacon-modal-course-title').val('').prop('readonly', false).css('background', '#fff');
                $('#beacon-modal-beacon-id').val('');
                $('#beacon-modal-beacon-type').val('');
                $('#beacon-modal-title-desc').hide();

                // Fresh products selector for a brand new Live Course
                toggleProductsRow('live');
                loadProducts('');

                resetModalState();
                modal.fadeIn(200);
            });

            function resetModalState() {
                $('#beacon-modal-notice').hide();
                $('#beacon-modal-save').prop('disabled', false);
                $('#beacon-modal-spinner').removeClass('is-active');
            }

            function closeModal() {
                modal.fadeOut(200);
            }

            $('#beacon-modal-close, #beacon-modal-cancel').on('click', function() {
                closeModal();
            });

            // Handle AJAX Save
            $('#beacon-modal-save').on('click', function() {
                var btn = $(this);
                var source = $('#beacon-modal-source').val();
                var course_id = $('#beacon-modal-course-id').val();
                var custom_id = $('#beacon-modal-custom-id').val();
                var c_title = $('#beacon-modal-course-title').val();
                var b_id = $('#beacon-modal-beacon-id').val();
                var b_type = $('#beacon-modal-beacon-type').val();
                var linked_products = $products.val() || [];
                var noticeBox = $('#beacon-modal-notice');

                if (source === 'live') {
                    if (course_id === 'new') {
                        if (!custom_id || isNaN(custom_id) || custom_id <= 0 || !Number.isInteger(parseFloat(custom_id))) {
                            noticeBox.css({'border-color': '#d63638', 'background': '#fcf0f1'}).text('A valid integer Course ID is required.').fadeIn();
                            return;
                        }
                    }
                    if (c_title.trim() === '') {
                        noticeBox.css({'border-color': '#d63638', 'background': '#fcf0f1'}).text('Course Title is required.').fadeIn();
                        return;
                    }
                }

                btn.prop('disabled', true);
                $('#beacon-modal-spinner').addClass('is-active');
                noticeBox.hide();

                $.post(ajaxurl, {
                    action: 'beacon_save_course_mapping',
                    security: '<?php echo wp_create_nonce("beacon_save_mapping"); ?>',
                    source: source,
                    course_id: course_id,
                    custom_id: custom_id,
                    course_title: c_title,
                    beacon_id: b_id,
                    beacon_type: b_type,
                    linked_products: linked_products
                }, function(response) {
                    btn.prop('disabled', false);
                    $('#beacon-modal-spinner').removeClass('is-active');

                    if (response.success) {
                        if (course_id === 'new') {
                            // If new live course created, reload to update table
                            location.reload();
                        } else {
                            // Dynamically update existing row
                            var row = $('#beacon-row-' + course_id);
                            var color = (response.data.beacon_id === '') ? '#d63638' : '#00a32a';
                            var text_id = (response.data.beacon_id === '') ? 'Not Mapped' : response.data.beacon_id;
                            var text_type = (response.data.beacon_type === '') ? '&mdash;' : response.data.beacon_type;

                            row.find('.beacon-title-cell').text(c_title);
                            row.find('.beacon-id-cell').css('color', color).text(text_id);
                            row.find('.beacon-type-cell').html(text_type);
                            
                            var editBtn = row.find('.beacon-edit-modal-btn');
                            editBtn.attr('data-course-title', c_title);
                            editBtn.attr('data-beacon-id', response.data.beacon_id);
                            editBtn.attr('data-beacon-type', response.data.beacon_type);
                            editBtn.attr('data-linked-products', (linked_products || []).join(','));

                            row.css('background-color', '#e5f5fa');
                            setTimeout(function(){ row.css('background-color', ''); }, 1500);
                            closeModal();
                        }
                    } else {
                        noticeBox.css({'border-color': '#d63638', 'background': '#fcf0f1'}).text('Error: ' + response.data).fadeIn();
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    $('#beacon-modal-spinner').removeClass('is-active');
                    noticeBox.css({'border-color': '#d63638', 'background': '#fcf0f1'}).text('A server error occurred. Please try again.').fadeIn();
                });
            });

            // Handle Live Course Deletion
            $('.beacon-delete-live-btn').on('click', function() {
                if (!confirm('Are you sure you want to delete this Live Course? It will also be removed from any linked WooCommerce products.')) return;
                
                var btn = $(this);
                var course_id = btn.attr('data-course-id');
                btn.prop('disabled', true).text('...');

                $.post(ajaxurl, {
                    action: 'beacon_delete_live_course',
                    security: '<?php echo wp_create_nonce("beacon_delete_live"); ?>',
                    course_id: course_id
                }, function(response) {
                    if (response.success) {
                        $('#beacon-row-' + course_id).fadeOut(400, function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + response.data);
                        btn.prop('disabled', false).text('Delete');
                    }
                }).fail(function() {
                    alert('Server error occurred.');
                    btn.prop('disabled', false).text('Delete');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX Endpoint: Saves mapped fields sent via the modal popup. Supports LD and Live Courses.
     */
    public function ajax_save_course_mapping()
    {
        check_ajax_referer('beacon_save_mapping', 'security');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized access.');

        $source    = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
        $course_id = isset($_POST['course_id']) ? sanitize_text_field(wp_unslash($_POST['course_id'])) : '';
        $custom_id = isset($_POST['custom_id']) ? intval($_POST['custom_id']) : 0;
        $c_title   = isset($_POST['course_title']) ? sanitize_text_field(wp_unslash($_POST['course_title'])) : '';
        $b_id      = isset($_POST['beacon_id']) ? sanitize_text_field(wp_unslash($_POST['beacon_id'])) : '';
        $b_type    = isset($_POST['beacon_type']) ? sanitize_text_field(wp_unslash($_POST['beacon_type'])) : '';
        $linked    = isset($_POST['linked_products']) ? array_map('intval', (array) wp_unslash($_POST['linked_products'])) : [];

        if (empty($course_id)) wp_send_json_error('Invalid Course ID.');

        if ($source === 'live') {
            $live_courses = get_option('beacon_crm_live_courses', []);
            
            if ($course_id === 'new') {
                if ($custom_id <= 0) {
                    wp_send_json_error('A valid integer Course ID is required for Live Courses.');
                }
                if (isset($live_courses[$custom_id])) {
                    wp_send_json_error('This Course ID is already in use by another Live Course.');
                }
                if (get_post_type($custom_id) === 'sfwd-courses') {
                    wp_send_json_error('This Course ID is already in use by a LearnDash Course.');
                }
                $course_id = $custom_id; // Assign the validated custom integer ID
            }

            $live_courses[$course_id] = [
                'id'          => $course_id,
                'name'        => $c_title,
                'beacon_id'   => $b_id,
                'beacon_type' => $b_type
            ];
            update_option('beacon_crm_live_courses', $live_courses);

            // Reflect the selection onto WooCommerce products so this Live Course
            // appears under "Beacon CRM Integration (Live Courses)" on each product.
            $this->sync_live_course_products($course_id, $linked);
        } else {
            // LearnDash Course
            update_post_meta((int)$course_id, '_beacon_course_id', $b_id);
            update_post_meta((int)$course_id, '_beacon_course_type', $b_type);
            delete_post_meta((int)$course_id, '_beacon_courses_data');
        }

        wp_send_json_success([
            'beacon_id'   => $b_id,
            'beacon_type' => $b_type
        ]);
    }

    /**
     * AJAX Endpoint: Safely deletes a Custom Live Course and cleans up WooCommerce Product Meta.
     */
    public function ajax_delete_live_course()
    {
        check_ajax_referer('beacon_delete_live', 'security');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized access.');

        $course_id = isset($_POST['course_id']) ? sanitize_text_field(wp_unslash($_POST['course_id'])) : '';
        if (empty($course_id)) wp_send_json_error('Invalid ID.');

        $live_courses = get_option('beacon_crm_live_courses', []);
        
        if (isset($live_courses[$course_id])) {
            unset($live_courses[$course_id]);
            update_option('beacon_crm_live_courses', $live_courses);

            // Cleanup routine: Remove this Live Course ID from all WooCommerce Products
            global $wpdb;
            $products = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_beacon_live_courses'");
            
            foreach ($products as $p) {
                $val = maybe_unserialize($p->meta_value);
                if (is_array($val) && in_array($course_id, $val)) {
                    $updated_val = array_diff($val, [$course_id]);
                    update_post_meta($p->post_id, '_beacon_live_courses', $updated_val);
                }
            }
            wp_send_json_success();
        }

        wp_send_json_error('Course not found.');
    }

    /* -------------------------------------------------------------------------- */
    /* AJAX & NOTICES                                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * Renders success/error notices passed via URL parameters.
     */
    private function render_admin_notices()
    {
        if (! isset($_GET['beacon_test_status'])) return;

        $status = sanitize_text_field(wp_unslash($_GET['beacon_test_status']));

        if ($status === 'success') {
            $order_id = isset($_GET['tested_order']) ? intval($_GET['tested_order']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Sync triggered for Order #' . esc_html($order_id) . '. Check Logs.</p></div>';
        } elseif ($status === 'bulk_success') {
            $count = isset($_GET['processed_count']) ? intval($_GET['processed_count']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p><strong>Bulk Sync Complete!</strong> Successfully processed ' . esc_html($count) . ' orders.</p></div>';
        } elseif ($status === 'invalid_order') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Valid order(s) not found.</p></div>';
        } elseif ($status === 'missing_auth') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> API Key or Account ID missing.</p></div>';
        }
    }

    /**
     * AJAX Endpoint: Searches WooCommerce orders by ID, Name, or Email for the SelectWoo dropdown.
     */
    public function ajax_search_orders()
    {
        check_ajax_referer('beacon_search_orders', 'security');

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $term = isset($_GET['q']) ? wc_clean(wp_unslash($_GET['q'])) : '';
        if (empty($term)) {
            wp_send_json([]);
        }

        $orders = wc_get_orders([
            's'      => $term,
            'limit'  => 20,
            'return' => 'objects',
        ]);

        $results = [];
        foreach ($orders as $order) {
            $results[] = [
                'id'   => $order->get_id(),
                'text' => '#' . $order->get_id() . ' - ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ' (' . $order->get_billing_email() . ')'
            ];
        }

        wp_send_json($results);
    }

    /**
     * AJAX Endpoint: Initializes the bulk sync by retrieving all relevant Order IDs.
     */
    public function ajax_init_bulk_sync()
    {
        check_ajax_referer('beacon_bulk_sync', 'security');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
        $date_to   = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';

        if (empty($date_from) || empty($date_to)) {
            wp_send_json_error('Invalid date range.');
        }

        if (! $this->get_credentials()) {
            wp_send_json_error('API credentials missing. Please configure them in the API tab.');
        }

        $orders = wc_get_orders([
            'limit'        => -1,
            'date_created' => $date_from . '...' . $date_to,
            'return'       => 'ids',
        ]);

        if (empty($orders)) {
            wp_send_json_error('No orders found in this date range.');
        }

        wp_send_json_success(['order_ids' => $orders, 'total' => count($orders)]);
    }

    /**
     * AJAX Endpoint: Processes a specific chunk of Order IDs to prevent timeouts.
     * Note: Decoupled `handle_training_logic` from Order bulk loops as LearnDash enrollment manages this natively.
     */
    public function ajax_process_chunk()
    {
        check_ajax_referer('beacon_bulk_sync', 'security');
        if (! current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $order_ids = isset($_POST['order_ids']) ? array_map('intval', (array) $_POST['order_ids']) : [];

        if (empty($order_ids)) {
            wp_send_json_success();
        }

        foreach ($order_ids as $order_id) {
            $this->handle_payment_complete($order_id);
            // Training logic explicitly removed here to prevent dual-triggering; LearnDash enrollment hook oversees this.
        }

        // Enforce a strict 500ms delay per chunk to safeguard against API rate-limiting
        usleep(500000);

        wp_send_json_success();
    }

    /* -------------------------------------------------------------------------- */
    /* API UTILITIES & SUBMISSION HANDLERS                                        */
    /* -------------------------------------------------------------------------- */

    public function handle_test_sync_submission()
    {
        if (! isset($_POST['beacon_test_nonce']) || ! wp_verify_nonce(sanitize_key($_POST['beacon_test_nonce']), 'beacon_test_sync_nonce')) wp_die('Invalid security nonce.');
        if (! current_user_can('manage_options')) wp_die('Unauthorized user.');

        $order_id = isset($_POST['beacon_test_order_id']) ? intval($_POST['beacon_test_order_id']) : 0;
        $order    = wc_get_order($order_id);

        if (! $order) {
            wp_redirect(add_query_arg(['page' => 'beacon-crm-settings', 'beacon_test_status' => 'invalid_order', 'tested_order' => $order_id], admin_url('admin.php')));
            exit;
        }

        if (! $this->get_credentials()) {
            wp_redirect(add_query_arg(['page' => 'beacon-crm-settings', 'beacon_test_status' => 'missing_auth'], admin_url('admin.php')));
            exit;
        }

        // Execute Sequences
        $this->handle_payment_complete($order_id);
        $this->sync_live_courses_from_order($order_id);

        wp_redirect(add_query_arg(['page' => 'beacon-crm-settings', 'beacon_test_status' => 'success', 'tested_order' => $order_id], admin_url('admin.php')));
        exit;
    }

    private function get_credentials()
    {
        $api_key    = get_option(self::OPT_API_KEY);
        $account_id = get_option(self::OPT_ACCOUNT_ID);
        $api_base   = get_option(self::OPT_API_BASE, 'https://api.beaconcrm.org/v1/account/');

        if (empty($api_key) || empty($account_id)) return false;

        return [
            'api_key'    => $api_key,
            'account_id' => $account_id,
            'base_url'   => trailingslashit($api_base) . $account_id . '/'
        ];
    }

    private function get_headers($api_key)
    {
        return [
            'Authorization'      => 'Bearer ' . $api_key,
            'Beacon-Application' => 'developer_api',
            'Content-Type'       => 'application/json'
        ];
    }

    private function send_request($resource, $body, $order_id = 0, $method = 'PUT')
    {
        $creds = $this->get_credentials();
        if (! $creds) return false;

        $response = wp_remote_request($creds['base_url'] . $resource, [
            'body'    => wp_json_encode($body),
            'headers' => $this->get_headers($creds['api_key']),
            'method'  => $method,
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            error_log("Beacon API Error (Context ID {$order_id}): " . $response->get_error_message());
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("Beacon API JSON Decode Error: " . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /* -------------------------------------------------------------------------- */
    /* CORE BUSINESS LOGIC                                                        */
    /* -------------------------------------------------------------------------- */

    private function get_or_create_person($order)
    {
        $user_id     = $order->get_user_id();
        $existing_id = get_user_meta($user_id, 'beacon_user_id', true);
        if (! empty($existing_id)) return $existing_id;

        $first_name   = $order->get_billing_first_name();
        $last_name    = $order->get_billing_last_name();
        $email        = $order->get_billing_email();
        $phone        = $order->get_billing_phone();
        $country      = $order->get_billing_country();
        $country_name = isset(WC()->countries->countries[$country]) ? WC()->countries->countries[$country] : $country;

        $payload = [
            "primary_field_key" => "emails",
            "entity"            => [
                "emails"  => [["email" => $email, "is_primary" => true]],
                "name"    => ["full" => "$first_name $last_name", "last" => $last_name, "first" => $first_name],
                'type'    => ['Supporter'],
                "address" => [[
                    "address_line_one" => $order->get_billing_address_1(),
                    "address_line_two" => $order->get_billing_address_2(),
                    "city"             => $order->get_billing_city(),
                    "region"           => $order->get_billing_state(),
                    "postal_code"      => $order->get_billing_postcode(),
                    "country"          => $country_name,
                ]],
                "notes"   => 'Updated via woocommerce checkout'
            ],
        ];

        if (! empty($phone)) {
            $clean_phone = preg_replace('/[^\d+]/', '', $phone);
            if (! empty($clean_phone)) {
                $payload['entity']['phone_numbers'] = [["number" => $clean_phone, "is_primary" => true]];
            }
        }

        $resource = 'entity/person/upsert';
        $response = $this->send_request($resource, $payload, $order->get_id());

        if ($response && isset($response['entity']['id'])) {
            update_user_meta($user_id, 'beacon_user_id', $response['entity']['id']);
            $this->log_to_db("[Person Created] Order " . $order->get_id(), ['type' => 'person', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
            return $response['entity']['id'];
        }

        if (isset($response['error']['raw']) && strpos($response['error']['raw'], 'phone_numbers') !== false) {
            unset($payload['entity']['phone_numbers']);
            $payload['entity']['notes'] .= ' | Notice: Original phone number rejected by CRM validation and omitted.';
            $retry_response = $this->send_request($resource, $payload, $order->get_id());

            if ($retry_response && isset($retry_response['entity']['id'])) {
                update_user_meta($user_id, 'beacon_user_id', $retry_response['entity']['id']);
                $this->log_to_db("[Person Created - Phone Omitted] Order " . $order->get_id(), ['type' => 'person', 'api_url' => $resource, 'args' => $payload, 'return' => $retry_response]);
                return $retry_response['entity']['id'];
            }
            $response = $retry_response;
        }

        $this->log_to_db("[Person Sync Failed] Order " . $order->get_id(), ['type' => 'person', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
        return false;
    }

    private function get_or_create_person_from_user($user_id)
    {
        $existing_id = get_user_meta($user_id, 'beacon_user_id', true);
        if (! empty($existing_id)) return $existing_id;

        $user = get_userdata($user_id);
        if (! $user) return false;

        $first_name = $user->first_name ?: $user->display_name;
        $last_name  = $user->last_name ?: 'Unknown';
        $email      = $user->user_email;

        $payload = [
            "primary_field_key" => "emails",
            "entity"            => [
                "emails"  => [["email" => $email, "is_primary" => true]],
                "name"    => ["full" => "$first_name $last_name", "last" => $last_name, "first" => $first_name],
                'type'    => ['Supporter'],
                "notes"   => 'Created/Updated via direct LearnDash enrollment trigger'
            ],
        ];

        $resource = 'entity/person/upsert';
        $response = $this->send_request($resource, $payload, 0);

        if ($response && isset($response['entity']['id'])) {
            update_user_meta($user_id, 'beacon_user_id', $response['entity']['id']);
            $this->log_to_db("[Person Created] LD User Fallback " . $user_id, ['type' => 'person', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
            return $response['entity']['id'];
        }

        $this->log_to_db("[Person Sync Failed] LD User Fallback " . $user_id, ['type' => 'person', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
        return false;
    }

    public function handle_payment_complete($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) return;

        $beacon_person_id = $this->get_or_create_person($order);
        if (! $beacon_person_id) {
            $this->log_to_db("[Payment Aborted] Order " . $order_id, ['type' => 'payment', 'api_url' => 'N/A', 'args' => ['error' => 'Sequence aborted: Missing or failed Person ID generation.'], 'return' => false]);
            return;
        }

        $date_paid   = $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d') : date('Y-m-d');
        $external_id = $order->get_transaction_id() ?: 'MANUAL-' . $order_id;
        $resource    = 'entity/payment/upsert';

        $product_names = [];
        $has_bundle    = false;

        foreach ($order->get_items() as $item) {
            $name = $item->get_name();
            if (! $has_bundle && has_term('bundles', 'product_cat', $item->get_product_id())) {
                $name .= ' (Bundle Payment)';
                $has_bundle = true;
            }
            $product_names[] = $item->get_name();
        }

        $payload = [
            "primary_field_key" => "external_id",
            "entity"            => [
                'external_id'    => $external_id,
                'amount'         => ['value' => $order->get_total(), 'currency' => 'GBP'],
                'type'           => ['Course fees'],
                'source'         => ['Training Course'],
                'payment_method' => ['Card'],
                'payment_date'   => [$date_paid],
                'customer'       => [intval($beacon_person_id)],
                'notes'          => 'Payment via WC: ' . implode(', ', $product_names) . " [Order ID: {$order_id}]",
            ],
        ];

        $response = $this->send_request($resource, $payload, $order_id, 'PUT');
        $this->log_to_db("[Payment] Order " . $order_id, ['type' => 'payment', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
    }

    /**
     * UNTOUCHED: Native LearnDash Training Logic
     */
    public function handle_training_logic($user_id, $course_id, $course_access_list = [], $remove = false)
    {
        if ($remove) return;

        $beacon_person_id = $this->get_or_create_person_from_user($user_id);
        if (! $beacon_person_id) return;

        $resource = 'entity/c_training/upsert';
        $b_id     = get_post_meta($course_id, '_beacon_course_id', true);
        $b_type   = get_post_meta($course_id, '_beacon_course_type', true);

        if (empty($b_id) || empty($b_type)) {
            $this->log_to_db("[Training Aborted] LD Course " . $course_id, ['type' => 'training', 'api_url' => 'N/A', 'args' => ['error' => 'No Beacon CRM mapped fields on LearnDash Course ID: ' . $course_id], 'return' => false]);
            return;
        }

        $c_name = $user_id . '_' . $course_id;
        $payload = [
            "primary_field_key" => "c_previous_db_id",
            "entity"            => [
                "c_person"         => [intval($beacon_person_id)],
                "c_course"         => [intval($b_id)],
                "c_course_type"    => [$b_type],
                "c_previous_db_id" => $c_name
            ]
        ];

        $response = $this->send_request($resource, $payload, $course_id);
        $this->log_to_db("[Training Enrolled] LD Course " . $course_id, ['type' => 'training', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
    }

    /**
     * NEW: Syncs Custom Live Courses stored in Product Meta immediately on WC Order Completion.
     */
    public function sync_live_courses_from_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) return;

        $live_courses_option = get_option('beacon_crm_live_courses', []);
        if (empty($live_courses_option)) return;

        // Create a fast lookup map array for validation
        $lc_map = [];
        foreach ($live_courses_option as $lc) {
            $lc_map[$lc['id']] = $lc;
        }

        $user_id = $order->get_user_id();
        // Generates or fetches the CRM ID strictly using WooCommerce context
        $beacon_person_id = $this->get_or_create_person($order);
        if (! $beacon_person_id) return;

        $resource = 'entity/c_training/upsert';

        foreach ($order->get_items() as $item) {
            $variation_id = $item->get_variation_id();
            $product_id   = $item->get_product_id();
            $source_id    = $variation_id ?: $product_id;

            // Fetch mapped Live Courses added in WC Product/Variation Editor
            $mapped_live_courses = get_post_meta($source_id, '_beacon_live_courses', true);

            // Variation overrides parent; a variation with no mapping inherits the parent's.
            if ($variation_id && (empty($mapped_live_courses) || !is_array($mapped_live_courses))) {
                $mapped_live_courses = get_post_meta($product_id, '_beacon_live_courses', true);
            }

            if (!empty($mapped_live_courses) && is_array($mapped_live_courses)) {
                foreach ($mapped_live_courses as $lc_id) {
                    
                    // Verify the assigned Live Course still natively exists in wp_options
                    if (isset($lc_map[$lc_id])) {
                        $course = $lc_map[$lc_id];

                        if (!empty($course['beacon_id']) && !empty($course['beacon_type'])) {
                            $c_name = $user_id . '_' . $lc_id;

                            $payload = [
                                "primary_field_key" => "c_previous_db_id",
                                "entity"            => [
                                    "c_person"         => [intval($beacon_person_id)],
                                    "c_course"         => [intval($course['beacon_id'])],
                                    "c_course_type"    => [$course['beacon_type']],
                                    "c_previous_db_id" => $c_name
                                ]
                            ];

                            $response = $this->send_request($resource, $payload, $order_id);
                            $this->log_to_db("[Live Training Sync] Order " . $order_id, ['type' => 'training', 'api_url' => $resource, 'args' => $payload, 'return' => $response]);
                        }
                    }
                }
            }
        }
    }

    private function log_to_db($title, $meta_fields = [])
    {
        $status = 'success';
        if (empty($meta_fields['return']) || (is_array($meta_fields['return']) && isset($meta_fields['return']['errors']))) {
            $status = 'error';
        }
        $meta_fields['status'] = $status;

        $result = wp_insert_post([
            'post_title'  => sanitize_text_field($title),
            'post_status' => 'publish',
            'post_type'   => 'beaconcrmlogs',
            'meta_input'  => $meta_fields
        ], true);

        if (is_wp_error($result)) error_log('Beacon Log Error: ' . $result->get_error_message());
        return $result;
    }

    /* -------------------------------------------------------------------------- */
    /* LOG FILTERING & ADMIN COLUMNS                                              */
    /* -------------------------------------------------------------------------- */

    public function add_log_columns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            if ($key === 'date') {
                $new_columns['log_type']   = 'Payload Type';
                $new_columns['log_status'] = 'Status';
            }
            $new_columns[$key] = $title;
        }
        return $new_columns;
    }

    public function fill_log_columns($column, $post_id)
    {
        if ('log_type' === $column) {
            $type = get_post_meta($post_id, 'type', true);
            echo $type ? esc_html(ucfirst($type)) : '&mdash;';
        }
        if ('log_status' === $column) {
            $status = get_post_meta($post_id, 'status', true);
            if ($status === 'success') echo '<span style="color: #00a32a; font-weight: 600;">Success</span>';
            elseif ($status === 'error') echo '<span style="color: #d63638; font-weight: 600;">Error</span>';
            else echo '&mdash;';
        }
    }

    public function add_log_filters($post_type)
    {
        if ('beaconcrmlogs' !== $post_type) return;

        $current_type   = isset($_GET['beacon_log_type']) ? sanitize_text_field(wp_unslash($_GET['beacon_log_type'])) : '';
        $current_status = isset($_GET['beacon_log_status']) ? sanitize_text_field(wp_unslash($_GET['beacon_log_status'])) : '';

    ?>
        <select name="beacon_log_type">
            <option value="">All Payload Types</option>
            <option value="person" <?php selected($current_type, 'person'); ?>>Person</option>
            <option value="payment" <?php selected($current_type, 'payment'); ?>>Payment</option>
            <option value="training" <?php selected($current_type, 'training'); ?>>Training</option>
        </select>
        <select name="beacon_log_status">
            <option value="">All Statuses</option>
            <option value="success" <?php selected($current_status, 'success'); ?>>Success</option>
            <option value="error" <?php selected($current_status, 'error'); ?>>Error</option>
        </select>
<?php
    }

    public function filter_logs_by_meta($query)
    {
        global $pagenow;
        if ('edit.php' !== $pagenow || ! $query->is_main_query() || 'beaconcrmlogs' !== $query->get('post_type')) return;

        $meta_query = $query->get('meta_query') ?: [];
        if (! empty($_GET['beacon_log_type'])) $meta_query[] = ['key' => 'type', 'value' => sanitize_text_field(wp_unslash($_GET['beacon_log_type'])), 'compare' => '='];
        if (! empty($_GET['beacon_log_status'])) $meta_query[] = ['key' => 'status', 'value' => sanitize_text_field(wp_unslash($_GET['beacon_log_status'])), 'compare' => '='];
        if (! empty($meta_query)) $query->set('meta_query', $meta_query);
    }

    public function add_beacon_id_user_column($columns)
    {
        $columns['beacon_id'] = 'Beacon ID';
        return $columns;
    }

    public function fill_beacon_id_user_column($output, $column_name, $user_id)
    {
        if ($column_name === 'beacon_id') {
            $id = get_user_meta($user_id, 'beacon_user_id', true);
            return $id ? esc_html($id) : '&mdash;';
        }
        return $output;
    }

    public function make_beacon_id_column_sortable($columns)
    {
        $columns['beacon_id'] = 'beacon_user_id';
        return $columns;
    }

    public function render_log_metabox($post)
    {
        $log_type   = get_post_meta($post->ID, 'type', true);
        $api_url    = get_post_meta($post->ID, 'api_url', true);
        $log_args   = get_post_meta($post->ID, 'args', true);
        $log_return = get_post_meta($post->ID, 'return', true);
    ?>
        <style>
            .beacon-log-row { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
            .beacon-log-label { font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px; color: #2c3338; }
            .beacon-log-code { background: #f0f0f1; padding: 10px; border: 1px solid #ccc; overflow: auto; font-family: monospace; max-height: 300px; }
            .beacon-log-value { font-size: 14px; }
        </style>
        <div class="beacon-crm-log-container">
            <div class="beacon-log-row"><span class="beacon-log-label">Type:</span><div class="beacon-log-value"><?php echo esc_html($log_type ?: 'N/A'); ?></div></div>
            <div class="beacon-log-row"><span class="beacon-log-label">API URL:</span><div class="beacon-log-value"><?php echo $api_url ? esc_html($api_url) : 'N/A'; ?></div></div>
            <div class="beacon-log-row"><span class="beacon-log-label">Request Args:</span><div class="beacon-log-code"><pre><?php echo esc_html(print_r($log_args, true)); ?></pre></div></div>
            <div class="beacon-log-row" style="border-bottom:none;"><span class="beacon-log-label">API Return:</span><div class="beacon-log-code"><pre><?php echo esc_html(print_r($log_return, true)); ?></pre></div></div>
        </div>
    <?php
    }
}

// Initialise the singleton instance.
Beacon_CRM_Integration::get_instance();