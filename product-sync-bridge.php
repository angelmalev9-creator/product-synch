<?php
/**
 * Plugin Name: Product Sync Bridge
 * Description: Full multi-source product backfill + ongoing sync за WooCommerce. Първо качва всички липсващи продукти, после следи само за нови.
 * Version: 26.0.0
 * Author: Product Sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class Product_Sync_Bridge_V26 {
    const VERSION = '26.0.0';
    const SETTINGS_KEY = 'psb_settings_v2';
    const LAST_SYNC_KEY = 'psb_last_sync_v2';
    const LOG_KEY = 'psb_log_v2';
    const CRON_HOOK = 'psb_auto_sync_event';
    const BACKFILL_HOOK = 'psb_backfill_continue_event';
    const QUEUE_KEY = 'psb_full_import_queue_v3';
    const QUEUE_META_KEY = 'psb_full_import_queue_meta_v3';
    const PRICING_KEY = 'psb_category_pricing_v1';
    const AUDIT_HOOK = 'psb_catalog_audit_event';
    const ASYNC_HOOK = 'psb_async_worker_action';
    const IMAGE_HOOK = 'psb_image_worker_action';
    const AS_GROUP = 'product-sync-bridge';
    const WORKER_LOCK_KEY = 'psb_worker_lock_v1';
    const LIVE_REQUEST_LOCK_KEY = 'psb_live_request_lock_v26';
    const IMAGE_QUEUE_KEY = 'psb_image_queue_v1';
    const FAILED_KEY = 'psb_unresolved_source_urls_v1';
    const IGNORED_KEY = 'psb_ignored_irrelevant_source_urls_v1';
    const DISCOVERY_STATE_KEY = 'psb_discovery_state_v2';
    const DISCOVERY_SEEN_KEY = 'psb_discovery_seen_v2';
    const LIVE_MODE_KEY = 'psb_live_sync_active_v26';
    const LIVE_TURBO_BATCH = 24;
    const ACTIVE_SOURCE_KEY = 'psb_active_source_v21';
    const RUN_STATS_KEY = 'psb_full_run_stats_v21';
    const LIVE_IMAGE_BATCH = 2;
    const WATCHDOG_HOOK = 'psb_safe_watchdog_v26';
    const DEFERRED_AUDIT_HOOK = 'psb_deferred_audit_v26';
    const DEFERRED_AUDIT_QUEUE_KEY = 'psb_deferred_audit_queue_v26';
    const LIVE_MAX_BATCH = 30;
    const BACKGROUND_SAFE_BATCH = 5;
    const LIVE_TIME_BUDGET = 18;
    const HYPER_FETCH_BATCH = 120;
    const CLI_MAX_BATCH = 120;
    const VPS_REST_NAMESPACE = 'product-sync/v1';
    const STAGE_SCHEMA_VERSION = '26.0.0';
    const STAGE_SCHEMA_KEY = 'psb_stage_schema_v26';

    private static $bulk_mode = false;
    private static $bulk_image_jobs = array();
    private static $bulk_audit_ids = array();
    private static $bulk_stat_events = array();

    const META_SOURCE = '_psb_source';
    const META_SOURCE_URL = '_psb_source_url';
    const META_SOURCE_ID = '_psb_source_product_id';
    const META_SOURCE_KEY = '_psb_source_key';
    const META_SOURCE_PRICE = '_psb_source_price';
    const META_SOURCE_REGULAR_PRICE = '_psb_source_regular_price';
    const META_SOURCE_SALE_PRICE = '_psb_source_sale_price';
    const META_SOURCE_ON_SALE = '_psb_source_on_sale';
    const META_FINGERPRINT = '_psb_fingerprint';
    const META_SUPPLIER = '_psb_supplier';
    const META_CATEGORY_PATH = '_psb_category_path_json';
    const META_SOURCE_KEYS_MULTI = '_psb_source_keys';
    const META_SOURCE_ALIASES = '_psb_source_aliases_json';
    const META_SUPPLIERS = '_psb_suppliers_json';
    const META_IRRELEVANT = '_psb_irrelevant';
    const META_IRRELEVANT_REASON = '_psb_irrelevant_reason';
    const META_PAYLOAD_HASH = '_psb_payload_hash_v26';

    public static function init() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));

        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'auto_sync'));
        add_action(self::BACKFILL_HOOK, array(__CLASS__, 'auto_sync'));
        add_action(self::ASYNC_HOOK, array(__CLASS__, 'async_worker'));
        add_action(self::IMAGE_HOOK, array(__CLASS__, 'image_worker'));
        add_action(self::WATCHDOG_HOOK, array(__CLASS__, 'safe_watchdog'));
        add_action(self::DEFERRED_AUDIT_HOOK, array(__CLASS__, 'deferred_audit_worker'));
        add_action('admin_post_psb_reset_queue', array(__CLASS__, 'reset_queue'));
        add_action('admin_post_psb_force_reconcile', array(__CLASS__, 'force_reconcile'));
        add_action('wp_ajax_psb_live_seed', array(__CLASS__, 'live_seed'));
        add_action('wp_ajax_psb_live_step', array(__CLASS__, 'live_step'));
        add_action('wp_ajax_psb_live_image_step', array(__CLASS__, 'live_image_step'));
        add_action('wp_ajax_psb_live_abort', array(__CLASS__, 'live_abort'));
        add_action('wp_ajax_psb_bulk_delete_products', array(__CLASS__, 'bulk_delete_products_ajax'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_psb_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_psb_run_sync', array(__CLASS__, 'run_sync_now'));
        add_action('init', array(__CLASS__, 'register_catalog_taxonomies'), 20);
        add_action(self::AUDIT_HOOK, array(__CLASS__, 'audit_batch'));
        add_action('admin_post_psb_catalog_save', array(__CLASS__, 'catalog_save'));
        add_action('admin_post_psb_catalog_audit', array(__CLASS__, 'catalog_audit_now'));
        add_action('admin_post_psb_pricing_save', array(__CLASS__, 'pricing_save'));
        add_action('admin_post_psb_pricing_apply', array(__CLASS__, 'pricing_apply'));
        add_action('admin_post_psb_reprocess_catalog', array(__CLASS__, 'reprocess_catalog'));
        add_action('admin_post_psb_quality_repair', array(__CLASS__, 'quality_repair'));
        add_action('admin_post_psb_cleanup_irrelevant', array(__CLASS__, 'cleanup_irrelevant'));
        add_filter('manage_edit-product_columns', array(__CLASS__, 'product_admin_columns'));
        add_action('manage_product_posts_custom_column', array(__CLASS__, 'product_admin_column_content'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'product_admin_supplier_filter'), 10, 2);
        add_action('pre_get_posts', array(__CLASS__, 'product_admin_supplier_query'));
        add_action('admin_init', array(__CLASS__, 'deactivate_old_copies'), 1);
        add_action('init', array(__CLASS__, 'ensure_stage_table'), 5);
        add_action('rest_api_init', array(__CLASS__, 'register_vps_routes'));
    }

    public static function defaults() {
        return array(
            'robot_url' => '',
            'sync_secret' => '',
            'enabled' => 0,
            'interval' => 'psb_5_minutes',
            'batch_size' => 15,
            'product_status' => 'publish',
        );
    }

    public static function settings() {
        $saved = get_option(self::SETTINGS_KEY, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    private static function live_mode_active() {
        $ts = (int) get_option(self::LIVE_MODE_KEY, 0);
        if ($ts <= 0) return false;
        if ((time() - $ts) > 5 * MINUTE_IN_SECONDS) {
            delete_option(self::LIVE_MODE_KEY);
            return false;
        }
        return true;
    }

    private static function suspend_background_workers() {
        // While the visible HYPER loop is running, no WP-Cron/Action Scheduler
        // worker is allowed to import products or download images in parallel.
        // Parallel workers were the main reason shared hosting returned 503.
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        wp_clear_scheduled_hook(self::IMAGE_HOOK);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ASYNC_HOOK, array(), self::AS_GROUP);
            as_unschedule_all_actions(self::IMAGE_HOOK, array(), self::AS_GROUP);
        }
        delete_transient(self::WORKER_LOCK_KEY);
    }

    private static function touch_live_mode() {
        $was_active = self::live_mode_active();
        update_option(self::LIVE_MODE_KEY, time(), false);
        // Unscheduling Action Scheduler/WP-Cron on every AJAX request is expensive.
        // Do it only once when the visible HYPER session starts.
        if (!$was_active) self::suspend_background_workers();
        self::schedule_action(self::WATCHDOG_HOOK, array(), 150);
    }

    private static function clear_live_mode() {
        delete_option(self::LIVE_MODE_KEY);
    }


    public static function safe_watchdog() {
        $ts = (int) get_option(self::LIVE_MODE_KEY, 0);
        if ($ts <= 0) return;
        $age = time() - $ts;
        if ($age < 240) {
            self::schedule_action(self::WATCHDOG_HOOK, array(), 120);
            return;
        }
        // The browser tab is gone or the visible loop stopped. Resume safely in the background.
        self::clear_live_mode();
        delete_transient(self::WORKER_LOCK_KEY);
        if (self::stage_pending_count() || self::get_queue()) self::schedule_action(self::ASYNC_HOOK, array(), 5);
        elseif (self::get_image_queue()) self::schedule_action(self::IMAGE_HOOK, array(), 8);
        else self::schedule_action(self::DEFERRED_AUDIT_HOOK, array(), 12);
    }

    private static function queue_audit_product($product_id) {
        $product_id = (int) $product_id;
        if (!$product_id) return;
        if (self::$bulk_mode) {
            self::$bulk_audit_ids[$product_id] = $product_id;
            return;
        }
        self::audit_product($product_id);
    }

    private static function flush_bulk_audits() {
        if (!self::$bulk_audit_ids) return;
        $queue = get_option(self::DEFERRED_AUDIT_QUEUE_KEY, array());
        if (!is_array($queue)) $queue = array();
        $map = array();
        foreach ($queue as $id) { $id=(int)$id; if($id)$map[$id]=$id; }
        foreach (self::$bulk_audit_ids as $id) $map[(int)$id]=(int)$id;
        update_option(self::DEFERRED_AUDIT_QUEUE_KEY, array_values($map), false);
        self::$bulk_audit_ids = array();
    }

    public static function deferred_audit_worker() {
        if (self::live_mode_active()) return;
        $queue = get_option(self::DEFERRED_AUDIT_QUEUE_KEY, array());
        if (!is_array($queue) || !$queue) return;
        $selected = array_slice(array_values($queue), 0, 4);
        $queue = array_slice(array_values($queue), count($selected));
        foreach ($selected as $id) {
            try { self::audit_product((int)$id); } catch (Throwable $e) {}
        }
        update_option(self::DEFERRED_AUDIT_QUEUE_KEY, $queue, false);
        if ($queue) self::schedule_action(self::DEFERRED_AUDIT_HOOK, array(), 12);
    }

    private static function begin_bulk_mode() {
        self::$bulk_mode = true;
        self::$bulk_image_jobs = array();
        self::$bulk_audit_ids = array();
        self::$bulk_stat_events = array();
    }

    private static function flush_bulk_stats() {
        if (!self::$bulk_stat_events) return;
        $r=self::get_run_stats();
        $map=array('created'=>'created','created_no_price'=>'created','merged'=>'merged','duplicate'=>'duplicates','ignored'=>'ignored','failed'=>'failed','refreshed'=>'duplicates');
        foreach(self::$bulk_stat_events as $event){
            $status=(string)($event[0]??'');$source=sanitize_text_field((string)($event[1]??''));
            if(!isset($map[$status]))continue;
            $key=$map[$status];$r[$key]=(int)($r[$key]??0)+1;
            if($status==='created_no_price')$r['created_no_price']=(int)($r['created_no_price']??0)+1;
            if($source){
                if(!isset($r['sources'][$source])||!is_array($r['sources'][$source]))$r['sources'][$source]=array('discovered'=>0,'known'=>0,'queued'=>0,'created'=>0,'merged'=>0,'ignored'=>0,'failed'=>0);
                if(in_array($key,array('created','merged','ignored','failed'),true))$r['sources'][$source][$key]=(int)($r['sources'][$source][$key]??0)+1;
            }
        }
        self::save_run_stats($r);
        self::$bulk_stat_events=array();
    }

    private static function flush_bulk_images() {
        if (!self::$bulk_image_jobs) return;
        $queue=self::get_image_queue();
        $by_id=array();
        foreach($queue as $i=>$item){$pid=(int)($item['product_id']??0);if($pid)$by_id[$pid]=$i;}
        foreach(self::$bulk_image_jobs as $pid=>$job){
            $pid=(int)$pid;
            if(isset($by_id[$pid])){
                $i=$by_id[$pid];
                $old_index=max(0,(int)($queue[$i]['index']??0));
                $queue[$i]=$job;
                $queue[$i]['index']=min($old_index,max(0,count($job['urls'])-1));
            }else{
                $by_id[$pid]=count($queue);
                $queue[]=$job;
            }
        }
        self::save_image_queue($queue);
        self::$bulk_image_jobs=array();
    }

    private static function end_bulk_mode() {
        self::flush_bulk_images();
        self::flush_bulk_audits();
        self::flush_bulk_stats();
        self::$bulk_mode=false;
    }

    private static function memory_pressure_high() {
        $limit = ini_get('memory_limit');
        if (!$limit || $limit === '-1') return false;
        $bytes = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes($limit) : 0;
        if ($bytes <= 0) return false;
        return memory_get_usage(true) > ($bytes * 0.76);
    }

    private static function empty_run_stats() {
        return array(
            'started_at'=>current_time('mysql'),
            'discovered'=>0,'already_known'=>0,'queued'=>0,
            'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'ignored'=>0,'failed'=>0,
            'sources'=>array(),'catalog_roots'=>array(),'catalog_estimates'=>array(),
        );
    }

    private static function get_run_stats() {
        $r=get_option(self::RUN_STATS_KEY,array());
        return is_array($r)&&$r ? wp_parse_args($r,self::empty_run_stats()) : self::empty_run_stats();
    }

    private static function reset_run_stats() {
        $r=self::empty_run_stats();
        update_option(self::RUN_STATS_KEY,$r,false);
        return $r;
    }

    private static function save_run_stats($r) {
        update_option(self::RUN_STATS_KEY,is_array($r)?$r:self::empty_run_stats(),false);
    }

    private static function run_stats_add_discovery($source,$new_unique,$known,$queued) {
        $r=self::get_run_stats();
        $source=sanitize_text_field((string)$source);
        if(!isset($r['sources'][$source])||!is_array($r['sources'][$source]))$r['sources'][$source]=array('discovered'=>0,'known'=>0,'queued'=>0,'created'=>0,'merged'=>0,'ignored'=>0,'failed'=>0);
        $new_unique=max(0,(int)$new_unique);$known=max(0,(int)$known);$queued=max(0,(int)$queued);
        $r['discovered']=(int)$r['discovered']+$new_unique;
        $r['already_known']=(int)$r['already_known']+$known;
        $r['queued']=(int)$r['queued']+$queued;
        $r['sources'][$source]['discovered']=(int)($r['sources'][$source]['discovered']??0)+$new_unique;
        $r['sources'][$source]['known']=(int)($r['sources'][$source]['known']??0)+$known;
        $r['sources'][$source]['queued']=(int)($r['sources'][$source]['queued']??0)+$queued;
        self::save_run_stats($r);
    }

    private static function run_stats_note_catalog($source,$category,$total) {
        $source=sanitize_text_field((string)$source);$category=sanitize_text_field((string)$category);$total=max(0,(int)$total);
        if(!$source||!$category||!$total)return;
        $r=self::get_run_stats();
        if(!isset($r['catalog_roots'][$source])||!is_array($r['catalog_roots'][$source]))$r['catalog_roots'][$source]=array();
        $r['catalog_roots'][$source][$category]=$total;
        $r['catalog_estimates'][$source]=array_sum(array_map('intval',$r['catalog_roots'][$source]));
        self::save_run_stats($r);
    }

    private static function run_stats_record_import_status($status,$source='') {
        if(self::$bulk_mode){ self::$bulk_stat_events[]=array((string)$status,(string)$source); return; }
        $map=array('created'=>'created','created_no_price'=>'created','merged'=>'merged','duplicate'=>'duplicates','ignored'=>'ignored','failed'=>'failed');
        if(!isset($map[$status]))return;
        $r=self::get_run_stats();$key=$map[$status];$r[$key]=(int)($r[$key]??0)+1;
        if($status==='created_no_price')$r['created_no_price']=(int)($r['created_no_price']??0)+1;
        $source=sanitize_text_field((string)$source);
        if($source){
            if(!isset($r['sources'][$source])||!is_array($r['sources'][$source]))$r['sources'][$source]=array('discovered'=>0,'known'=>0,'queued'=>0,'created'=>0,'merged'=>0,'ignored'=>0,'failed'=>0);
            if(in_array($key,array('created','merged','ignored','failed'),true))$r['sources'][$source][$key]=(int)($r['sources'][$source][$key]??0)+1;
        }
        self::save_run_stats($r);
    }

    private static function apply_run_stats(&$summary) {
        if(!is_array($summary))$summary=array();
        $r=self::get_run_stats();
        $summary['discovered']=(int)($r['discovered']??0);
        $summary['already_known']=(int)($r['already_known']??0);
        $simple_sources=array();
        foreach((array)($r['sources']??array()) as $src=>$row)$simple_sources[$src]=(int)($row['discovered']??0);
        $summary['sources']=$simple_sources;
        $summary['run_created']=(int)($r['created']??0);
        $summary['run_created_no_price']=(int)($r['created_no_price']??0);
        $summary['run_merged']=(int)($r['merged']??0);
        $summary['run_duplicates']=(int)($r['duplicates']??0);
        $summary['run_ignored']=(int)($r['ignored']??0);
        $summary['run_failed']=(int)($r['failed']??0);
        $summary['catalog_estimates']=is_array($r['catalog_estimates']??null)?$r['catalog_estimates']:array();
        $summary['source_details']=is_array($r['sources']??null)?$r['sources']:array();
        $summary['covered_urls']=$summary['already_known']+$summary['run_created']+$summary['run_merged']+$summary['run_duplicates']+$summary['run_ignored'];
        $queue_remaining=(int)($summary['queue_remaining']??0);
        $summary['unresolved_urls']=max(0,$summary['discovered']-$summary['covered_urls']-$queue_remaining);
    }

    public static function deactivate_old_copies() {
        if (!is_admin()) return;
        if (!function_exists('deactivate_plugins') || !function_exists('get_plugin_data')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (file_exists($plugin_file)) require_once $plugin_file;
        }
        if (!function_exists('deactivate_plugins') || !function_exists('get_plugin_data')) return;

        $current = plugin_basename(__FILE__);
        $active = (array) get_option('active_plugins', array());
        foreach ($active as $plugin) {
            if ($plugin === $current) continue;
            $full = trailingslashit(WP_PLUGIN_DIR) . $plugin;
            if (!is_file($full)) continue;
            $data = get_plugin_data($full, false, false);
            if (!empty($data['Name']) && trim((string)$data['Name']) === 'Product Sync Bridge') {
                // Silent deactivation preserves every WooCommerce product and all PSB queue/options.
                deactivate_plugins($plugin, true);
            }
        }
    }

    public static function activate() {
        self::deactivate_old_copies();
        self::ensure_stage_table();
        if (!get_option(self::SETTINGS_KEY)) {
            add_option(self::SETTINGS_KEY, self::defaults(), '', false);
        }
        // Do not allow activation itself to fatal if WooCommerce is temporarily not fully loaded.
        if (class_exists('WooCommerce') && function_exists('wc_create_attribute')) {
            self::ensure_global_attributes();
        }
        self::reschedule();
        if (!wp_next_scheduled(self::AUDIT_HOOK)) wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::AUDIT_HOOK);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        wp_clear_scheduled_hook(self::AUDIT_HOOK);
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        wp_clear_scheduled_hook(self::IMAGE_HOOK);
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
        wp_clear_scheduled_hook(self::DEFERRED_AUDIT_HOOK);
        delete_transient(self::LIVE_REQUEST_LOCK_KEY);
        self::clear_live_mode();
    }

    public static function cron_schedules($schedules) {
        $schedules['psb_5_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'На всеки 5 минути',
        );
        $schedules['psb_30_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => 'На всеки 30 минути',
        );
        return $schedules;
    }

    private static function reschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $s = self::settings();
        if (!empty($s['enabled']) && !empty($s['robot_url'])) {
            $interval = in_array($s['interval'], array('psb_5_minutes', 'psb_30_minutes', 'hourly', 'twicedaily', 'daily'), true)
                ? $s['interval']
                : 'psb_5_minutes';
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
        }
    }

    public static function auto_sync() {
        if (self::live_mode_active()) return;
        self::request_async_sync();
    }

    private static function schedule_action($hook, $args = array(), $delay = 0) {
        $timestamp = time() + max(0, (int)$delay);
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action($hook, $args, self::AS_GROUP)) {
                as_schedule_single_action($timestamp, $hook, $args, self::AS_GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event($timestamp, $hook, $args);
        }
    }

    private static function request_async_sync() {
        if (self::live_mode_active()) return;
        self::schedule_action(self::ASYNC_HOOK, array(), 1);
    }

    public static function async_worker() {
        if (self::live_mode_active()) return;
        if (get_transient(self::WORKER_LOCK_KEY)) return;
        set_transient(self::WORKER_LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS);
        try {
            $summary = self::stage_pending_count() ? self::materialize_stage_batch(self::BACKGROUND_SAFE_BATCH, false) : self::perform_sync(false);
            if (!empty($summary['queue_remaining']) || self::stage_pending_count()) {
                self::schedule_action(self::ASYNC_HOOK, array(), 3);
            } elseif (self::get_image_queue()) {
                self::schedule_action(self::IMAGE_HOOK, array(), 8);
            } else {
                self::schedule_action(self::DEFERRED_AUDIT_HOOK, array(), 12);
            }
        } catch (Throwable $e) {
            self::schedule_action(self::ASYNC_HOOK, array(), 60);
        }
        delete_transient(self::WORKER_LOCK_KEY);
    }

    public static function run_sync_now() {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('psb_run_sync');
        // V17: the main button always launches the visible TURBO importer.
        // No more confusing background-only start with static numbers.
        self::clear_sync_state(true);
        self::reset_run_stats();
        self::touch_live_mode();
        update_option('psb_force_full_reconcile_v1', 1, false);
        self::set_active_source('mma.bg');
        wp_safe_redirect(admin_url('admin.php?page=product-sync-bridge&full_reconcile=1&live_full=1&staged=1'));
        exit;
    }

    public static function save_settings() {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('psb_save_settings');

        $robot_url = isset($_POST['robot_url']) ? esc_url_raw(trim(wp_unslash($_POST['robot_url']))) : '';
        $sync_secret = isset($_POST['sync_secret']) ? sanitize_text_field(wp_unslash($_POST['sync_secret'])) : '';
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $interval = isset($_POST['interval']) ? sanitize_key(wp_unslash($_POST['interval'])) : 'psb_5_minutes';
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 15;
        $batch_size = max(1, min(30, $batch_size));
        $product_status = isset($_POST['product_status']) ? sanitize_key(wp_unslash($_POST['product_status'])) : 'publish';
        if (!in_array($product_status, array('publish', 'draft'), true)) $product_status = 'publish';
        if (!in_array($interval, array('psb_5_minutes', 'psb_30_minutes', 'hourly', 'twicedaily', 'daily'), true)) $interval = 'psb_5_minutes';

        update_option(self::SETTINGS_KEY, array(
            'robot_url' => untrailingslashit($robot_url),
            'sync_secret' => $sync_secret,
            'enabled' => $enabled,
            'interval' => $interval,
            'batch_size' => $batch_size,
            'product_status' => $product_status,
        ), false);

        self::reschedule();
        wp_safe_redirect(admin_url('admin.php?page=product-sync-bridge&saved=1'));
        exit;
    }

    public static function reset_queue() {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('psb_reset_queue');
        self::clear_sync_state(true);
        self::reset_run_stats();
        self::set_active_source('');
        self::clear_live_mode();
        self::request_async_sync();
        wp_safe_redirect(admin_url('admin.php?page=product-sync-bridge&queue_reset=1&started=1'));
        exit;
    }

    public static function force_reconcile() {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('psb_force_reconcile');
        self::clear_sync_state(true);
        self::reset_run_stats();
        self::touch_live_mode();
        update_option('psb_force_full_reconcile_v1', 1, false);
        // Do NOT depend on WP-Cron/Action Scheduler to start the full import.
        // The admin page will seed and pump the queue via small AJAX requests.
        self::set_active_source('mma.bg');
        wp_safe_redirect(admin_url('admin.php?page=product-sync-bridge&full_reconcile=1&live_full=1&staged=1'));
        exit;
    }

    private static function clear_sync_state($clear_failed = false) {
        foreach (array(
            self::QUEUE_KEY, self::QUEUE_META_KEY, self::DISCOVERY_STATE_KEY, self::DISCOVERY_SEEN_KEY,
            'psb_full_import_queue_v1','psb_full_import_queue_meta_v1',
            'psb_full_import_queue_v2','psb_full_import_queue_meta_v2'
        ) as $key) delete_option($key);
        if ($clear_failed) delete_option(self::FAILED_KEY);
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);
        wp_clear_scheduled_hook(self::ASYNC_HOOK);
        wp_clear_scheduled_hook(self::IMAGE_HOOK);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ASYNC_HOOK, array(), self::AS_GROUP);
            as_unschedule_all_actions(self::IMAGE_HOOK, array(), self::AS_GROUP);
        }
        delete_transient(self::WORKER_LOCK_KEY);
    }

    private static function all_sources() {
        return array('mma.bg','szfighters.com','kmsport.bg','leaderfitness.net');
    }

    private static function discovery_sources() {
        $active=sanitize_text_field((string)get_option(self::ACTIVE_SOURCE_KEY,''));
        return in_array($active,self::all_sources(),true) ? array($active) : self::all_sources();
    }

    private static function set_active_source($source='') {
        $source=sanitize_text_field((string)$source);
        if($source && in_array($source,self::all_sources(),true)) update_option(self::ACTIVE_SOURCE_KEY,$source,false);
        else delete_option(self::ACTIVE_SOURCE_KEY);
    }

    private static function start_discovery_cycle($clear_failed = false) {
        delete_option(self::QUEUE_KEY);
        delete_option(self::QUEUE_META_KEY);
        delete_option(self::DISCOVERY_STATE_KEY);
        delete_option(self::DISCOVERY_SEEN_KEY);
        if ($clear_failed) delete_option(self::FAILED_KEY);
        $state = array(
            'source_index'=>0,
            'cursor'=>'0',
            'discovered'=>0,
            'already_known'=>0,
            'sources'=>array(),
            'messages'=>array(),
            'started_at'=>current_time('mysql'),
            'retries'=>0,
        );
        update_option(self::DISCOVERY_STATE_KEY,$state,false);
        update_option(self::DISCOVERY_SEEN_KEY,array(),false);
        return $state;
    }

    private static function bulk_known_queue_keys($items) {
        global $wpdb;
        $items=is_array($items)?$items:array();
        if(!$items)return array();
        $hash_to_queue=array(); $url_to_queue=array();
        foreach($items as $row){
            $src=sanitize_text_field($row['source']??'');
            $url=self::normalize_source_url($row['url']??'');
            if(!$src||!$url)continue;
            $qkey=self::queue_item_key(array('source'=>$src,'url'=>$url));
            $hash=self::source_key($src,$url);
            $hash_to_queue[$hash]=$qkey; $url_to_queue[$url]=$qkey;
        }
        $known=array();
        foreach(array_chunk(array_keys($hash_to_queue),400) as $chunk){
            if(!$chunk)continue;
            $ph=implode(',',array_fill(0,count($chunk),'%s'));
            $sql="SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type='product' AND pm.meta_key IN (%s,%s) AND pm.meta_value IN ($ph)";
            $args=array_merge(array(self::META_SOURCE_KEY,self::META_SOURCE_KEYS_MULTI),$chunk);
            $vals=$wpdb->get_col($wpdb->prepare($sql,$args));
            foreach((array)$vals as $v)if(isset($hash_to_queue[$v]))$known[$hash_to_queue[$v]]=true;
        }
        foreach(array_chunk(array_keys($url_to_queue),250) as $chunk){
            if(!$chunk)continue;
            $ph=implode(',',array_fill(0,count($chunk),'%s'));
            $sql="SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type='product' AND pm.meta_key=%s AND pm.meta_value IN ($ph)";
            $args=array_merge(array(self::META_SOURCE_URL),$chunk);
            $vals=$wpdb->get_col($wpdb->prepare($sql,$args));
            foreach((array)$vals as $v)if(isset($url_to_queue[$v]))$known[$url_to_queue[$v]]=true;
        }
        return $known;
    }

    private static function seed_queue_chunk($reset = false, $auto_new_cycle = false) {
        $started=microtime(true);
        $summary=array(
            'time'=>current_time('mysql'),'manual'=>true,'status'=>'running',
            'discovered'=>0,'already_known'=>0,'new_found'=>0,'requested'=>0,
            'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'errors'=>0,
            'run_created'=>0,'run_created_no_price'=>0,'run_merged'=>0,'run_duplicates'=>0,'run_ignored'=>0,'run_failed'=>0,
            'covered_urls'=>0,'unresolved_urls'=>0,'queue_remaining'=>0,'messages'=>array(),'sources'=>array(),
            'discovery_done'=>false,'discovery_source'=>'','discovery_cursor'=>'0','discovery_cursor_label'=>'0'
        );
        try {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) throw new Exception('WooCommerce не е активен.');
            $meta=get_option(self::QUEUE_META_KEY,array()); if(!is_array($meta))$meta=array();
            $state=get_option(self::DISCOVERY_STATE_KEY,array()); if(!is_array($state))$state=array();
            if($reset){
                self::clear_sync_state(true);
                $state=self::start_discovery_cycle(true);
                $meta=array();
            } elseif(!$state) {
                if($auto_new_cycle && !empty($meta['discovery_complete']) && !self::get_queue()) {
                    $state=self::start_discovery_cycle(false);
                    $meta=array();
                } elseif(empty($meta['discovery_complete'])) {
                    $state=self::start_discovery_cycle(false);
                }
            }

            $sources=self::discovery_sources();
            if(!$state && !empty($meta['discovery_complete'])) {
                $summary['discovered']=(int)($meta['discovered']??0);
                $summary['already_known']=(int)($meta['already_known']??0);
                $summary['new_found']=(int)($meta['new_found']??0);
                $summary['sources']=is_array($meta['sources']??null)?$meta['sources']:array();
                $summary['queue_remaining']=count(self::get_queue());
                $summary['covered_urls']=$summary['already_known'];
                $summary['discovery_done']=true;
                $summary['status']='ok';
                return $summary;
            }
            if(!$state)$state=self::start_discovery_cycle(false);

            $idx=(int)($state['source_index']??0);
            if($idx>=count($sources)) {
                $queue=self::get_queue();
                $meta=array(
                    'discovered'=>(int)($state['discovered']??0),
                    'already_known'=>(int)($state['already_known']??0),
                    'new_found'=>count($queue),
                    'sources'=>is_array($state['sources']??null)?$state['sources']:array(),
                    'started_at'=>$state['started_at']??current_time('mysql'),
                    'run_created'=>0,'run_created_no_price'=>0,'run_merged'=>0,'run_duplicates'=>0,'run_ignored'=>0,'run_failed'=>0,
                    'discovery_complete'=>1,
                );
                update_option(self::QUEUE_META_KEY,$meta,false);
                delete_option(self::DISCOVERY_STATE_KEY); delete_option(self::DISCOVERY_SEEN_KEY);
                $summary['discovered']=$meta['discovered']; $summary['already_known']=$meta['already_known'];
                $summary['new_found']=$meta['new_found']; $summary['sources']=$meta['sources'];
                $summary['queue_remaining']=count($queue); $summary['covered_urls']=$summary['already_known'];
                $summary['discovery_done']=true; $summary['status']=$queue?'running':'ok';
                $summary['messages'][]='Сканирането на четирите доставчика приключи. Опашка за качване: '.count($queue).'.';
                return $summary;
            }

            $source=$sources[$idx];
            $cursor=(string)($state['cursor']??'0');
            $summary['discovery_source']=$source; $summary['discovery_cursor']=$cursor;
            $path='/api/discover-chunk?source='.rawurlencode($source).'&cursor='.rawurlencode($cursor).'&limit=300';
            try {
                $discover=self::robot_request($path,'GET',null,45);
                $state['retries']=0;
                $summary['discovery_total_units']=(int)($discover['totalUnits']??0);
                $summary['discovery_source_index']=$idx;
                $summary['discovery_source_total']=count($sources);
                if($source==='mma.bg' && !empty($discover['category']) && !empty($discover['categoryTotal'])) self::run_stats_note_catalog($source,$discover['category'],$discover['categoryTotal']);
                if($source==='mma.bg'){
                    $mc=(int)($discover['nextCursor']??$cursor);
                    $catLabel=sanitize_text_field((string)($discover['category']??''));
                    $pageNow=(int)($discover['currentPage']??(($mc%1000)+1));
                    $pageTo=(int)($discover['processedToPage']??$pageNow);
                    $pageMax=(int)($discover['categoryPages']??0);
                    $summary['discovery_cursor_label']=($catLabel?$catLabel.' · ':'').'страници '.$pageNow.($pageTo>$pageNow?'–'.$pageTo:'').($pageMax?' от '.$pageMax:'');
                } else {
                    $summary['discovery_cursor_label']=(string)($discover['nextCursor']??$cursor);
                }
            } catch(Throwable $e) {
                $state['retries']=(int)($state['retries']??0)+1;
                update_option(self::DISCOVERY_STATE_KEY,$state,false);
                $summary['status']='warning'; $summary['errors']=1;
                $summary['messages'][]=$source.': '.$e->getMessage().' — ще опитам същата малка част отново.';
                $summary['discovered']=(int)($state['discovered']??0); $summary['already_known']=(int)($state['already_known']??0);
                $summary['sources']=is_array($state['sources']??null)?$state['sources']:array();
                $summary['discovery_source_index']=$idx; $summary['discovery_source_total']=count($sources);
                $summary['queue_remaining']=count(self::get_queue()); $summary['new_found']=$summary['queue_remaining'];
                return $summary;
            }

            $seen=get_option(self::DISCOVERY_SEEN_KEY,array()); if(!is_array($seen))$seen=array();
            $queue=self::get_queue(); $queueMap=array(); foreach($queue as $q)$queueMap[self::queue_item_key($q)]=true;
            $added=0; $known=0; $newUnique=0;
            $items=!empty($discover['items'])&&is_array($discover['items'])?$discover['items']:array();
            $normalized_for_bulk=array();
            foreach($items as $r){
                if(!is_array($r))continue;
                $u=!empty($r['url'])?self::normalize_source_url($r['url']):'';
                $s=!empty($r['source'])?sanitize_text_field($r['source']):$source;
                if($u&&$s)$normalized_for_bulk[]=array('url'=>$u,'source'=>$s);
            }
            $bulkKnown=self::bulk_known_queue_keys($normalized_for_bulk);
            foreach($items as $row){
                if(!is_array($row))continue;
                $url=!empty($row['url'])?self::normalize_source_url($row['url']):'';
                $src=!empty($row['source'])?sanitize_text_field($row['source']):$source;
                if(!$url||!$src)continue;
                $item=array('url'=>$url,'source'=>$src,'attempts'=>0); $key=self::queue_item_key($item);
                if(isset($seen[$key]))continue;
                $seen[$key]=1; $newUnique++; $state['discovered']=(int)($state['discovered']??0)+1;
                $state['sources'][$src]=(int)($state['sources'][$src]??0)+1;
                if(self::is_ignored_source_url($src,$url)){
                    $known++; $state['already_known']=(int)($state['already_known']??0)+1; $state['ignored_known']=(int)($state['ignored_known']??0)+1; self::clear_failed_for_item($item);
                } elseif(isset($bulkKnown[$key])){
                    $known++; $state['already_known']=(int)($state['already_known']??0)+1; self::clear_failed_for_item($item);
                } elseif(!isset($queueMap[$key])) {
                    $queue[]=$item; $queueMap[$key]=true; $added++;
                }
            }
            update_option(self::DISCOVERY_SEEN_KEY,$seen,false); self::save_queue($queue);
            self::run_stats_add_discovery($source,$newUnique,$known,$added);
            if(!empty($discover['errors'])&&is_array($discover['errors'])) foreach($discover['errors'] as $err) if(is_array($err)&&!empty($err['message'])) $summary['messages'][]=sanitize_text_field(($err['source']??$source).': '.$err['message']);

            $done=!empty($discover['done']);
            if($done){
                $state['source_index']=$idx+1; $state['cursor']='0';
                $summary['messages'][]=$source.': частта е готова. Нови URL-и: '.$newUnique.', за качване: '.$added.', вече налични: '.$known.'.';
            } else {
                $state['cursor']=(string)($discover['nextCursor']??($cursor+1));
                $summary['messages'][]=$source.': открити още '.$newUnique.' уникални URL-а. Продължавам от cursor '.$state['cursor'].'.';
            }
            update_option(self::DISCOVERY_STATE_KEY,$state,false);

            $allDone=((int)$state['source_index']>=count($sources));
            if($allDone){
                $meta=array(
                    'discovered'=>(int)$state['discovered'],'already_known'=>(int)$state['already_known'],'new_found'=>count($queue),
                    'sources'=>$state['sources'],'started_at'=>$state['started_at'],
                    'run_created'=>0,'run_created_no_price'=>0,'run_merged'=>0,'run_duplicates'=>0,'run_ignored'=>0,'run_failed'=>0,'discovery_complete'=>1,
                );
                update_option(self::QUEUE_META_KEY,$meta,false); delete_option(self::DISCOVERY_STATE_KEY); delete_option(self::DISCOVERY_SEEN_KEY);
                $summary['discovery_done']=true;
            }
            $summary['discovered']=(int)$state['discovered']; $summary['already_known']=(int)$state['already_known'];
            $summary['sources']=$state['sources']; $summary['queue_remaining']=count($queue); $summary['new_found']=count($queue);
            $summary['covered_urls']=$summary['already_known']; $summary['status']='running';
        } catch(Throwable $e){
            $summary['status']='error'; $summary['errors']++; $summary['messages'][]=$e->getMessage();
        }
        $summary['duration_seconds']=round(microtime(true)-$started,2);
        update_option(self::LAST_SYNC_KEY,$summary,false);
        return $summary;
    }

    private static function seed_queue_only() {
        $started = microtime(true);
        $summary = array(
            'time'=>current_time('mysql'),'manual'=>true,'status'=>'running',
            'discovered'=>0,'already_known'=>0,'new_found'=>0,'requested'=>0,
            'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'errors'=>0,
            'run_created'=>0,'run_created_no_price'=>0,'run_merged'=>0,'run_duplicates'=>0,'run_ignored'=>0,'run_failed'=>0,
            'covered_urls'=>0,'unresolved_urls'=>0,'queue_remaining'=>0,'messages'=>array(),'sources'=>array(),
        );
        try {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) throw new Exception('WooCommerce не е активен.');
            $discover = self::robot_request('/api/discover');
            $items = array();
            if (!empty($discover['items']) && is_array($discover['items'])) {
                foreach ($discover['items'] as $row) {
                    if (!is_array($row)) continue;
                    $url = !empty($row['url']) ? self::normalize_source_url($row['url']) : '';
                    $source = !empty($row['source']) ? sanitize_text_field($row['source']) : '';
                    if ($url && $source) $items[] = array('url'=>$url,'source'=>$source,'attempts'=>0);
                }
            } elseif (!empty($discover['products']) && is_array($discover['products'])) {
                $fallback_source = !empty($discover['source']) && $discover['source'] !== 'multi' ? sanitize_text_field($discover['source']) : 'mma.bg';
                foreach ($discover['products'] as $url) {
                    $url = self::normalize_source_url($url);
                    if ($url) $items[] = array('url'=>$url,'source'=>$fallback_source,'attempts'=>0);
                }
            }
            $dedup=array(); foreach($items as $item) $dedup[self::queue_item_key($item)]=$item; $items=array_values($dedup);
            $summary['discovered']=count($items);
            if (!empty($discover['counts']) && is_array($discover['counts'])) $summary['sources']=$discover['counts'];
            if (!empty($discover['errors']) && is_array($discover['errors'])) {
                foreach($discover['errors'] as $err) if(is_array($err)&&!empty($err['message'])) $summary['messages'][]=sanitize_text_field(($err['source']??'source').': '.$err['message']);
            }
            $queue=array();
            foreach($items as $item){
                if(self::is_ignored_source_url($item['source'],$item['url'])){
                    $summary['already_known']++; $summary['ignored_known']=(int)($summary['ignored_known']??0)+1;
                    self::clear_failed_for_item($item);
                } elseif(self::find_existing($item['source'],$item['url'],'','','')){
                    $summary['already_known']++;
                    self::clear_failed_for_item($item);
                } else $queue[]=$item;
            }
            $summary['new_found']=count($queue);
            $summary['queue_remaining']=count($queue);
            $summary['covered_urls']=$summary['already_known'];
            $summary['unresolved_urls']=max(0,$summary['discovered']-$summary['covered_urls']-$summary['queue_remaining']);
            $meta=array(
                'discovered'=>$summary['discovered'],'already_known'=>$summary['already_known'],'new_found'=>$summary['new_found'],
                'sources'=>$summary['sources'],'started_at'=>current_time('mysql'),
                'run_created'=>0,'run_created_no_price'=>0,'run_merged'=>0,'run_duplicates'=>0,'run_ignored'=>0,'run_failed'=>0,
            );
            self::save_queue($queue); update_option(self::QUEUE_META_KEY,$meta,false); delete_option('psb_force_full_reconcile_v1');
            $summary['messages'][]='Опашката е създадена: '.$summary['queue_remaining'].' продукта за проверка/качване.';
            $summary['status']=$queue?'running':'ok';
            if($queue) self::schedule_backfill_if_needed(count($queue));
        } catch(Throwable $e){
            $summary['status']='error'; $summary['errors']++; $summary['messages'][]=$e->getMessage();
        }
        self::finish_sync($summary,$started);
        return $summary;
    }

    private static function ajax_guard() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(array('message'=>'Forbidden'),403);
        check_ajax_referer('psb_live_sync','nonce');
    }

    public static function live_seed() {
        self::ajax_guard();
        self::touch_live_mode();
        $reset=!empty($_POST['reset']);
        $requested_source=isset($_POST['source'])?sanitize_text_field(wp_unslash($_POST['source'])):'';
        if($requested_source && in_array($requested_source,self::all_sources(),true)) self::set_active_source($requested_source);
        $summary=self::seed_queue_chunk($reset,false);
        self::apply_run_stats($summary);
        $summary['active_source']=sanitize_text_field((string)get_option(self::ACTIVE_SOURCE_KEY,''));
        $summary['image_queue_remaining'] = count(self::get_image_queue());
        $summary['woo_total'] = self::count_imported_products();
        if(($summary['status']??'')==='error') wp_send_json_error($summary);
        wp_send_json_success($summary);
    }

    public static function live_step() {
        self::ajax_guard();
        self::touch_live_mode();
        self::ensure_stage_table();

        $requested_batch = isset($_POST['batch']) ? absint($_POST['batch']) : self::LIVE_TURBO_BATCH;
        $requested_batch = max(1, min(self::LIVE_MAX_BATCH, $requested_batch));

        if (get_transient(self::LIVE_REQUEST_LOCK_KEY)) {
            wp_send_json_success(array(
                'busy'=>1,
                'queue_remaining'=>count(self::get_queue()) + self::stage_pending_count(),
                'stage_pending'=>self::stage_pending_count(),
                'image_queue_remaining'=>count(self::get_image_queue()),
                'recommended_batch'=>$requested_batch,
                'messages'=>array('Друг HYPER worker още работи. Изчаквам го, без да пускам втори процес.'),
            ));
        }

        set_transient(self::LIVE_REQUEST_LOCK_KEY, 1, 3 * MINUTE_IN_SECONDS);
        delete_transient(self::WORKER_LOCK_KEY);
        try {
            if (self::stage_pending_count() > 0) {
                $summary = self::materialize_stage_batch($requested_batch, true);
            } elseif (self::get_queue()) {
                $summary = self::prefetch_hyper_stage();
            } else {
                $summary = array(
                    'status'=>'ok','errors'=>0,'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'ignored'=>0,
                    'queue_remaining'=>0,'stage_pending'=>0,'processed_in_request'=>0,'recommended_batch'=>$requested_batch,
                    'messages'=>array('Опашката за продукти е готова.'),
                );
            }
            self::apply_run_stats($summary);
            $summary['queue_remaining'] = count(self::get_queue()) + self::stage_pending_count();
            $summary['stage_pending'] = self::stage_pending_count();
            $summary['image_queue_remaining'] = count(self::get_image_queue());
            $summary['woo_total'] = self::count_imported_products();
        } catch (Throwable $e) {
            $summary=array(
                'status'=>'warning','errors'=>1,'queue_remaining'=>count(self::get_queue()) + self::stage_pending_count(),
                'stage_pending'=>self::stage_pending_count(),
                'image_queue_remaining'=>count(self::get_image_queue()),
                'recommended_batch'=>max(2,(int)floor($requested_batch/2)),
                'messages'=>array('Временна грешка: '.$e->getMessage().'. HYPER buffer-ът и URL опашката са запазени.'),
            );
        }
        delete_transient(self::LIVE_REQUEST_LOCK_KEY);
        wp_send_json_success($summary);
    }

    public static function live_image_step() {
        self::ajax_guard();
        self::touch_live_mode();
        $started=microtime(true);
        $before=count(self::get_image_queue());
        $requested=isset($_POST['batch'])?absint($_POST['batch']):1;
        $loops=max(1,min(self::LIVE_IMAGE_BATCH,$requested,max(1,$before)));
        $done=0;
        if($before>0) {
            for ($i=0; $i<$loops; $i++) {
                if (!self::get_image_queue()) break;
                self::image_worker(true);
                $done++;
                if ((microtime(true)-$started)>18) break;
            }
        }
        $remaining=count(self::get_image_queue());
        if($remaining===0) {
            self::clear_live_mode(); self::set_active_source('');
            self::schedule_action(self::DEFERRED_AUDIT_HOOK,array(),8);
        }
        wp_send_json_success(array('before'=>$before,'remaining'=>$remaining,'processed'=>$done,'duration_seconds'=>round(microtime(true)-$started,2)));
    }

    public static function live_abort() {
        self::ajax_guard();
        self::clear_live_mode();
        wp_send_json_success(array('ok'=>1));
    }

    private static function stage_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'psb_stage_v24';
    }

    public static function ensure_stage_table() {
        if (get_option(self::STAGE_SCHEMA_KEY) === self::STAGE_SCHEMA_VERSION) return;
        global $wpdb;
        $table = self::stage_table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key char(64) NOT NULL,
            source varchar(191) NOT NULL,
            source_url text NOT NULL,
            payload longtext NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            worker_token varchar(64) NOT NULL DEFAULT '',
            locked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_key (source_key),
            KEY attempts (attempts),
            KEY worker_token (worker_token),
            KEY locked_at (locked_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::STAGE_SCHEMA_KEY, self::STAGE_SCHEMA_VERSION, false);
    }

    private static function stage_pending_count() {
        self::ensure_stage_table();
        global $wpdb;
        $table = self::stage_table_name();
        return max(0, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
    }

    private static function stage_products(array $products) {
        if (!$products) return 0;
        self::ensure_stage_table();
        global $wpdb;
        $table = self::stage_table_name();
        $now = current_time('mysql');
        $added = 0;
        foreach (array_chunk($products, 100) as $chunk) {
            $values = array();
            $params = array();
            foreach ($chunk as $raw) {
                if (!is_array($raw)) continue;
                $source = sanitize_text_field((string)($raw['source'] ?? ''));
                $url = self::normalize_source_url((string)($raw['sourceUrl'] ?? ''));
                if (!$source || !$url) continue;
                $hash = hash('sha256', self::source_key($source, $url));
                $payload = wp_json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if (!$payload) continue;
                $values[] = '(%s,%s,%s,%s,0,\'\',NULL,%s,%s)';
                array_push($params, $hash, $source, $url, $payload, $now, $now);
                $added++;
            }
            if (!$values) continue;
            $sql = "INSERT INTO {$table} (source_key,source,source_url,payload,attempts,worker_token,locked_at,created_at,updated_at) VALUES " . implode(',', $values) . " ON DUPLICATE KEY UPDATE payload=VALUES(payload), source=VALUES(source), source_url=VALUES(source_url), attempts=0, worker_token='', locked_at=NULL, updated_at=VALUES(updated_at)";
            $wpdb->query($wpdb->prepare($sql, $params));
        }
        return $added;
    }

    private static function stage_rows($limit) {
        self::ensure_stage_table();
        global $wpdb;
        $table = self::stage_table_name();
        $max = (defined('WP_CLI') && WP_CLI) ? self::CLI_MAX_BATCH : self::LIVE_MAX_BATCH;
        $limit = max(1, min($max, (int)$limit));
        $stale = gmdate('Y-m-d H:i:s', time() - 600);
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET worker_token='', locked_at=NULL WHERE worker_token<>'' AND locked_at IS NOT NULL AND locked_at < %s", $stale));
        return (array)$wpdb->get_results($wpdb->prepare("SELECT id,payload,attempts FROM {$table} WHERE worker_token='' ORDER BY id ASC LIMIT %d", $limit), ARRAY_A);
    }

    private static function delete_stage_ids(array $ids) {
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!$ids) return;
        global $wpdb;
        $table = self::stage_table_name();
        $wpdb->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ")");
    }

    private static function stage_retry_or_fail($row, $message) {
        global $wpdb;
        $table = self::stage_table_name();
        $id = absint($row['id'] ?? 0);
        if (!$id) return;
        $attempts = (int)($row['attempts'] ?? 0) + 1;
        $raw = json_decode((string)($row['payload'] ?? ''), true);
        if ($attempts >= 3) {
            if (is_array($raw)) {
                self::record_failed(array('source'=>$raw['source']??'','url'=>$raw['sourceUrl']??''), $message);
                self::run_stats_record_import_status('failed', (string)($raw['source']??''));
            }
            self::delete_stage_ids(array($id));
            return;
        }
        $wpdb->update($table, array('attempts'=>$attempts,'worker_token'=>'','locked_at'=>null,'updated_at'=>current_time('mysql')), array('id'=>$id), array('%d','%s','%s','%s'), array('%d'));
    }

    private static function prefetch_hyper_stage() {
        $started = microtime(true);
        $queue = self::get_queue();
        $selected = array_slice($queue, 0, self::HYPER_FETCH_BATCH);
        $rest = array_slice($queue, count($selected));
        $summary = array(
            'status'=>'running','errors'=>0,'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'ignored'=>0,
            'requested'=>count($selected),'processed_in_request'=>0,'recommended_batch'=>self::LIVE_TURBO_BATCH,'messages'=>array(),
        );
        if (!$selected) {
            $summary['queue_remaining'] = self::stage_pending_count();
            return $summary;
        }

        try {
            $batch = self::robot_request('/api/batch', 'POST', array('items'=>$selected), 120);
        } catch (Throwable $e) {
            $summary['status']='warning';
            $summary['errors']=1;
            $summary['queue_remaining']=count($queue)+self::stage_pending_count();
            $summary['messages'][]='Vercel HYPER fetch не успя: '.$e->getMessage().'. URL опашката е непокътната.';
            return $summary;
        }

        $products = !empty($batch['products']) && is_array($batch['products']) ? $batch['products'] : array();
        $staged = self::stage_products($products);
        $handled = array();
        foreach ($products as $raw) {
            if (!is_array($raw)) continue;
            $key = sanitize_text_field((string)($raw['source']??'')) . '|' . self::normalize_source_url((string)($raw['sourceUrl']??''));
            if ($key !== '|') $handled[$key] = true;
        }
        $retry = array();
        foreach ($selected as $item) {
            $key = self::queue_item_key($item);
            if (!isset($handled[$key])) $retry[] = $item;
        }
        self::save_queue(array_merge($retry, $rest));

        $stage_pending = self::stage_pending_count();
        $summary['staged_added']=$staged;
        $summary['stage_pending']=$stage_pending;
        $summary['queue_remaining']=count($retry)+count($rest)+$stage_pending;
        $summary['duration_seconds']=round(microtime(true)-$started,2);
        $summary['messages'][]='HYPER BUFFER: заредих '.$staged.' продукта наведнъж от Robot-а за '.$summary['duration_seconds'].' сек. Сега ги превръщам в реални WooCommerce продукти на безопасни групи.';
        if ($retry) $summary['messages'][]=count($retry).' URL-а не бяха прочетени в тази голяма група и остават за повторен опит.';
        return $summary;
    }

    private static function materialize_stage_batch($batch_size, $live = true) {
        $started = microtime(true);
        $rows = self::stage_rows($batch_size);
        $summary = array(
            'status'=>'running','errors'=>0,'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'ignored'=>0,
            'requested'=>count($rows),'processed_in_request'=>0,'recommended_batch'=>max(1,min(self::LIVE_MAX_BATCH,(int)$batch_size)),'messages'=>array(),
        );
        if (!$rows) {
            $summary['queue_remaining']=count(self::get_queue());
            return $summary;
        }

        $deadline = microtime(true) + ($live ? self::LIVE_TIME_BUDGET : 13);
        $done_ids = array();
        self::begin_bulk_mode();
        wp_defer_term_counting(true);
        try {
            foreach ($rows as $row) {
                if ($summary['processed_in_request'] > 0 && (microtime(true) >= $deadline || self::memory_pressure_high())) break;
                $raw = json_decode((string)($row['payload']??''), true);
                if (!is_array($raw)) {
                    $done_ids[] = absint($row['id']??0);
                    continue;
                }
                $summary['processed_in_request']++;
                try {
                    $result = self::import_one($raw);
                    $status = (string)($result['status']??'');
                    self::run_stats_record_import_status($status,(string)($raw['source']??''));
                    self::clear_failed_for_item(array('source'=>$raw['source']??'','url'=>$raw['sourceUrl']??''));
                    if ($status==='created') $summary['created']++;
                    elseif ($status==='created_no_price') { $summary['created']++; $summary['created_no_price']++; }
                    elseif ($status==='merged') $summary['merged']++;
                    elseif ($status==='duplicate' || $status==='refreshed') $summary['duplicates']++;
                    elseif ($status==='ignored') $summary['ignored']++;
                    $done_ids[] = absint($row['id']);
                } catch (Throwable $e) {
                    $summary['errors']++;
                    self::stage_retry_or_fail($row,$e->getMessage());
                    $summary['messages'][]=$e->getMessage();
                }
            }
        } finally {
            wp_defer_term_counting(false);
            self::end_bulk_mode();
        }
        self::delete_stage_ids($done_ids);

        $elapsed = microtime(true)-$started;
        $processed = max(1,(int)$summary['processed_in_request']);
        $rate = $processed / max(.01,$elapsed);
        $current = max(1,min(self::LIVE_MAX_BATCH,(int)$batch_size));
        if ($elapsed < 5 && !self::memory_pressure_high()) $summary['recommended_batch']=min(self::LIVE_MAX_BATCH,$current+4);
        elseif ($elapsed > 14 || self::memory_pressure_high()) $summary['recommended_batch']=max(3,$current-5);
        elseif ($elapsed > 9) $summary['recommended_batch']=max(3,$current-2);
        $summary['duration_seconds']=round($elapsed,2);
        $summary['rate_per_second']=round($rate,2);
        $summary['stage_pending']=self::stage_pending_count();
        $summary['queue_remaining']=count(self::get_queue())+$summary['stage_pending'];
        $summary['messages'][]='WooCommerce HYPER MATERIALIZE: обработени '.$summary['processed_in_request'].' продукта за '.round($elapsed,2).' сек. ('.round($rate,2).'/сек). Buffer: '.$summary['stage_pending'].'. URL опашка: '.count(self::get_queue()).'.';
        if ($summary['queue_remaining']===0) $summary['status']=$summary['errors']?'warning':'ok';
        return $summary;
    }

    private static function get_failed() {
        $rows = get_option(self::FAILED_KEY, array());
        return is_array($rows) ? $rows : array();
    }

    private static function save_failed($rows) {
        update_option(self::FAILED_KEY, is_array($rows) ? $rows : array(), false);
    }

    private static function record_failed($item, $message = '') {
        $rows = self::get_failed();
        $key = self::queue_item_key($item);
        $rows[$key] = array(
            'source' => sanitize_text_field((string)($item['source'] ?? '')),
            'url' => self::normalize_source_url((string)($item['url'] ?? '')),
            'message' => sanitize_text_field((string)$message),
            'last_try' => current_time('mysql'),
        );
        self::save_failed($rows);
    }

    private static function clear_failed_for_item($item) {
        $rows = self::get_failed();
        $key = self::queue_item_key($item);
        if (isset($rows[$key])) { unset($rows[$key]); self::save_failed($rows); }
    }

    private static function get_queue() {
        $queue = get_option(self::QUEUE_KEY, array());
        return is_array($queue) ? array_values($queue) : array();
    }

    private static function save_queue($queue) {
        update_option(self::QUEUE_KEY, array_values(is_array($queue) ? $queue : array()), false);
    }

    private static function normalize_source_url($url) {
        $url = trim((string)$url);
        $url = preg_replace('/(?:%20|\s)+$/i', '', $url);
        $url = preg_replace('/#.*$/', '', $url);
        return esc_url_raw($url);
    }

    private static function queue_item_key($item) {
        return sanitize_text_field((string) ($item['source'] ?? '')) . '|' . self::normalize_source_url((string) ($item['url'] ?? ''));
    }

    private static function schedule_backfill_if_needed($remaining) {
        if ($remaining <= 0) return;
        self::schedule_action(self::ASYNC_HOOK, array(), 3);
    }

    private static function robot_request($path, $method = 'GET', $body = null, $timeout = 120) {
        $s = self::settings();
        $base = untrailingslashit((string) $s['robot_url']);
        if (!$base) throw new Exception('Липсва Vercel Robot URL в настройките.');

        $headers = array('accept' => 'application/json');
        if (!empty($s['sync_secret'])) {
            $headers['x-sync-key'] = (string) $s['sync_secret'];
        }
        if ($body !== null) $headers['content-type'] = 'application/json';

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => max(30, min(150, (int)$timeout)),
            'redirection' => 3,
        );
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $response = wp_remote_request($base . $path, $args);
        if (is_wp_error($response)) throw new Exception($response->get_error_message());

        $code = (int) wp_remote_retrieve_response_code($response);
        $text = (string) wp_remote_retrieve_body($response);
        $data = json_decode($text, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['error']) ? $data['error'] : ('Robot HTTP ' . $code);
            throw new Exception($message);
        }
        if (!is_array($data)) throw new Exception('Robot-ът върна невалиден JSON.');
        return $data;
    }

    private static function perform_sync($manual, $live = false, $live_batch = null) {
        $started = microtime(true);
        $summary = array(
            'time' => current_time('mysql'), 'manual' => (bool) $manual, 'status' => 'running',
            'discovered' => 0, 'already_known' => 0, 'new_found' => 0, 'requested' => 0,
            'created' => 0, 'created_no_price' => 0, 'merged' => 0, 'duplicates' => 0, 'errors' => 0,
            'run_created' => 0, 'run_created_no_price' => 0, 'run_merged' => 0, 'run_duplicates' => 0, 'run_ignored' => 0, 'run_failed' => 0,
            'covered_urls' => 0, 'unresolved_urls' => 0, 'queue_remaining' => 0,
            'messages' => array(), 'sources' => array(),
        );

        try {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) throw new Exception('WooCommerce не е активен.');
            $s = self::settings();
            $queue = self::get_queue();
            $meta = get_option(self::QUEUE_META_KEY, array());
            if (!is_array($meta)) $meta = array();

            if (!$queue) {
                // Never call the huge /api/discover endpoint here. Discovery is split into small source chunks.
                $seed=self::seed_queue_chunk(false,!$manual);
                $queue=self::get_queue();
                $meta=get_option(self::QUEUE_META_KEY,array()); if(!is_array($meta))$meta=array();
                $summary['discovered']=(int)($seed['discovered']??($meta['discovered']??0));
                $summary['already_known']=(int)($seed['already_known']??($meta['already_known']??0));
                $summary['new_found']=(int)($seed['new_found']??count($queue));
                $summary['sources']=!empty($seed['sources'])&&is_array($seed['sources'])?$seed['sources']:(is_array($meta['sources']??null)?$meta['sources']:array());
                if(!$queue){
                    $summary['queue_remaining']=0;
                    $summary['covered_urls']=$summary['already_known'];
                    $summary['unresolved_urls']=max(0,$summary['discovered']-$summary['covered_urls']);
                    $summary['messages']=array_merge($summary['messages'],is_array($seed['messages']??null)?$seed['messages']:array());
                    if(empty($seed['discovery_done'])){
                        $summary['status']='running';
                        self::schedule_action(self::ASYNC_HOOK,array(),5);
                    } else {
                        $summary['status']=$summary['unresolved_urls']?'warning':'ok';
                    }
                    self::finish_sync($summary,$started); return $summary;
                }
            } else {
                $summary['discovered'] = (int)($meta['discovered'] ?? 0);
                $summary['already_known'] = (int)($meta['already_known'] ?? 0);
                $summary['new_found'] = (int)($meta['new_found'] ?? count($queue));
                $summary['sources'] = !empty($meta['sources']) && is_array($meta['sources']) ? $meta['sources'] : array();
            }
            foreach (array('run_created','run_created_no_price','run_merged','run_duplicates','run_ignored','run_failed') as $k) $summary[$k]=(int)($meta[$k] ?? 0);

            if (!$queue) {
                $summary['covered_urls'] = $summary['already_known'] + $summary['run_created'] + $summary['run_merged'] + $summary['run_duplicates'] + $summary['run_ignored'];
                $summary['unresolved_urls'] = max(0,$summary['discovered'] - $summary['covered_urls']);
                $summary['status'] = $summary['unresolved_urls'] ? 'warning' : 'ok';
                if ($summary['unresolved_urls']) $summary['messages'][] = 'Има '.$summary['unresolved_urls'].' source URL-и без успешно качен/обединен продукт. Те ще се пробват отново при следващото пълно сканиране.';
                else $summary['messages'][] = 'Всички открити source URL-и са покрити: качени са като уникални продукти или са обединени само като надеждни дубликати.';
                self::finish_sync($summary,$started); return $summary;
            }

            $batch_size = $live ? max(1,min(self::LIVE_MAX_BATCH,absint($live_batch ?: self::LIVE_TURBO_BATCH))) : max(1,min(self::BACKGROUND_SAFE_BATCH,absint($s['batch_size'])));
            $selected = array_slice($queue,0,$batch_size);
            $queue = array_slice($queue,count($selected));
            $summary['requested'] = count($selected);
            try {
                $batch = self::robot_request('/api/batch','POST',array('items'=>$selected),120);
            } catch (Throwable $e) {
                // Never lose selected URLs because of a temporary Vercel/network failure.
                // Put them back at the front and let the live loop retry later.
                $queue = array_merge($selected, $queue);
                self::save_queue($queue);
                update_option(self::QUEUE_META_KEY,$meta,false);
                foreach (array('run_created','run_created_no_price','run_merged','run_duplicates','run_ignored','run_failed') as $k) $summary[$k]=(int)($meta[$k] ?? 0);
                $summary['queue_remaining']=count($queue);
                $summary['covered_urls']=$summary['already_known']+$summary['run_created']+$summary['run_merged']+$summary['run_duplicates']+$summary['run_ignored'];
                $summary['unresolved_urls']=max(0,$summary['discovered']-$summary['covered_urls']-$summary['queue_remaining']);
                $summary['status']='warning';
                $summary['errors']++;
                $summary['messages'][]='Временна грешка при четене на '.count($selected).' продукта: '.$e->getMessage().'. URL-ите са върнати в опашката и ще се опитат пак.';
                if(!$live) self::schedule_backfill_if_needed($summary['queue_remaining']);
                self::finish_sync($summary,$started);
                return $summary;
            }
            $products = !empty($batch['products']) && is_array($batch['products']) ? $batch['products'] : array();
            $handled = array();
            $deferred_unprocessed = array();
            $time_budget_hit = false;
            $processed_in_request = 0;
            $deadline = microtime(true) + ($live ? self::LIVE_TIME_BUDGET : 14);

            // One request = one compact DB batch. Expensive option writes for images,
            // audit results and run stats are buffered and flushed only once.
            self::begin_bulk_mode();
            wp_defer_term_counting(true);
            foreach ($products as $raw) {
                if (!is_array($raw)) continue;
                if ($processed_in_request > 0 && (microtime(true) >= $deadline || self::memory_pressure_high())) {
                    $time_budget_hit = true;
                    break;
                }
                $raw_key = sanitize_text_field((string)($raw['source'] ?? '')) . '|' . self::normalize_source_url((string)($raw['sourceUrl'] ?? ''));
                if ($raw_key !== '|') $handled[$raw_key] = true;
                $processed_in_request++;
                try {
                    $result = self::import_one($raw);
                    self::run_stats_record_import_status((string)($result['status']??''),(string)($raw['source']??''));
                    self::clear_failed_for_item(array('source'=>$raw['source'] ?? '','url'=>$raw['sourceUrl'] ?? ''));
                    if ($result['status'] === 'created') { $summary['created']++; $meta['run_created']=(int)($meta['run_created'] ?? 0)+1; }
                    elseif ($result['status'] === 'created_no_price') { $summary['created']++; $summary['created_no_price']++; $meta['run_created']=(int)($meta['run_created'] ?? 0)+1; $meta['run_created_no_price']=(int)($meta['run_created_no_price'] ?? 0)+1; }
                    elseif ($result['status'] === 'merged') { $summary['merged']++; $meta['run_merged']=(int)($meta['run_merged'] ?? 0)+1; }
                    elseif ($result['status'] === 'duplicate') { $summary['duplicates']++; $meta['run_duplicates']=(int)($meta['run_duplicates'] ?? 0)+1; }
                    elseif ($result['status'] === 'ignored') { $summary['ignored']=(int)($summary['ignored']??0)+1; $meta['run_ignored']=(int)($meta['run_ignored'] ?? 0)+1; }
                    elseif ($result['status'] === 'refreshed') { $summary['duplicates']++; $meta['run_duplicates']=(int)($meta['run_duplicates'] ?? 0)+1; }
                } catch (Throwable $e) {
                    $summary['errors']++; $summary['messages'][]=$e->getMessage();
                    foreach ($selected as $item) if (self::queue_item_key($item) === $raw_key) {
                        $attempts=(int)($item['attempts'] ?? 0)+1;
                        if ($attempts<3) { $item['attempts']=$attempts; $queue[]=$item; }
                        else { self::record_failed($item,$e->getMessage()); $meta['run_failed']=(int)($meta['run_failed'] ?? 0)+1; self::run_stats_record_import_status('failed',(string)($item['source']??'')); }
                        break;
                    }
                }
            }
            wp_defer_term_counting(false);
            self::end_bulk_mode();

            foreach ($selected as $item) {
                $key=self::queue_item_key($item); if (isset($handled[$key])) continue;
                if ($time_budget_hit) {
                    // Server-side time/memory guard: not an error and not an attempt.
                    // Return untouched items to the FRONT so they continue immediately.
                    $deferred_unprocessed[]=$item;
                    continue;
                }
                $attempts=(int)($item['attempts'] ?? 0)+1;
                if ($attempts<3) { $item['attempts']=$attempts; $queue[]=$item; }
                else {
                    self::record_failed($item,'Robot-ът не успя да прочете продукта след 3 опита.');
                    $meta['run_failed']=(int)($meta['run_failed'] ?? 0)+1;
                    self::run_stats_record_import_status('failed',(string)($item['source']??''));
                    $summary['errors']++; $summary['messages'][]='Остава неразрешен: '.($item['source'] ?? '').' '.($item['url'] ?? '');
                }
            }
            if ($deferred_unprocessed) $queue=array_merge($deferred_unprocessed,$queue);
            if (!empty($batch['errors']) && is_array($batch['errors'])) foreach ($batch['errors'] as $error) if (is_array($error) && !empty($error['message'])) $summary['messages'][]=sanitize_text_field(($error['source'] ?? '').' '.$error['message']);

            $elapsed=microtime(true)-$started;
            $recommended=$batch_size;
            if($time_budget_hit || $elapsed>17 || self::memory_pressure_high()) $recommended=max(2,$batch_size-3);
            elseif($processed_in_request >= $batch_size && $elapsed<5) $recommended=min(self::LIVE_MAX_BATCH,$batch_size+3);
            elseif($processed_in_request >= $batch_size && $elapsed<9) $recommended=min(self::LIVE_MAX_BATCH,$batch_size+1);
            elseif($elapsed>12) $recommended=max(2,$batch_size-1);
            $summary['processed_in_request']=$processed_in_request;
            $summary['time_budget_hit']=$time_budget_hit?1:0;
            $summary['recommended_batch']=$recommended;
            $summary['memory_mb']=round(memory_get_usage(true)/1048576,1);

            self::save_queue($queue); update_option(self::QUEUE_META_KEY,$meta,false);
            foreach (array('run_created','run_created_no_price','run_merged','run_duplicates','run_ignored','run_failed') as $k) $summary[$k]=(int)($meta[$k] ?? 0);
            $summary['queue_remaining']=count($queue);
            $summary['covered_urls']=$summary['already_known']+$summary['run_created']+$summary['run_merged']+$summary['run_duplicates']+$summary['run_ignored'];
            $summary['unresolved_urls']=max(0,$summary['discovered']-$summary['covered_urls']-$summary['queue_remaining']);
            $summary['status']=($summary['errors']||$summary['unresolved_urls'])?'warning':'ok';
            if ($summary['queue_remaining']>0) {
                $summary['messages'][]='Остават за обработка: '.$summary['queue_remaining'].'. Продължавам автоматично.';
                if(!$live) self::schedule_backfill_if_needed($summary['queue_remaining']);
            } else {
                wp_clear_scheduled_hook(self::BACKFILL_HOOK);
                if ($summary['unresolved_urls']>0) $summary['messages'][]='Опашката е обработена, но '.$summary['unresolved_urls'].' URL-и още не са покрити. Те НЕ се считат за завършени и ще се пробват пак.';
                else $summary['messages'][]='ПЪЛНА СИНХРОНИЗАЦИЯ: всички '.$summary['discovered'].' открити URL-и са покрити. Реалните повторения са обединени, останалите са качени като отделни продукти.';
            }
        } catch (Throwable $e) { $summary['status']='error'; $summary['errors']++; $summary['messages'][]=$e->getMessage(); }
        self::finish_sync($summary,$started); return $summary;
    }

    private static function finish_sync(&$summary, $started) {
        $summary['duration_seconds'] = round(microtime(true) - $started, 2);
        update_option(self::LAST_SYNC_KEY, $summary, false);

        $log = get_option(self::LOG_KEY, array());
        if (!is_array($log)) $log = array();
        array_unshift($log, $summary);
        $log = array_slice($log, 0, 20);
        update_option(self::LOG_KEY, $log, false);
    }

    private static function count_imported_products() {
        $counts = wp_count_posts('product');
        $total = 0;
        foreach (array('publish','draft','pending','private') as $status) {
            if (isset($counts->$status)) $total += (int)$counts->$status;
        }
        return $total;
    }

    public static function register_catalog_taxonomies() {
        if (!taxonomy_exists('product_brand')) {
            register_taxonomy('product_brand', array('product'), array(
                'labels' => array('name'=>'Производители','singular_name'=>'Производител'),
                'public' => true,
                'hierarchical' => false,
                'show_admin_column' => true,
                'show_in_rest' => true,
                'rewrite' => array('slug'=>'brand'),
            ));
        }
        self::ensure_global_attributes();
    }

    public static function ensure_global_attributes() {
        if (!function_exists('wc_get_attribute_taxonomies') || !function_exists('wc_create_attribute')) return;
        $wanted = array('razmer'=>'Размер','tsvyat'=>'Цвят','gramazh'=>'Грамаж');
        $existing = array();
        foreach (wc_get_attribute_taxonomies() as $attr) $existing[sanitize_title($attr->attribute_name)] = true;
        foreach ($wanted as $slug=>$label) {
            if (!empty($existing[$slug])) continue;
            $result = wc_create_attribute(array('name'=>$label,'slug'=>$slug,'type'=>'select','order_by'=>'menu_order','has_archives'=>true));
            if (!is_wp_error($result)) delete_transient('wc_attribute_taxonomies');
        }
    }

    private static function supplier_label($source) {
        $source = strtolower(trim((string)$source));
        $map = array(
            'mma.bg' => 'MMA.bg',
            'szfighters.com' => 'SZ Fighters',
            'kmsport.bg' => 'KMSPORT',
            'leaderfitness.net' => 'Leader Fitness',
        );
        return isset($map[$source]) ? $map[$source] : ($source ? $source : 'Ръчно добавен');
    }

    private static function product_supplier_label($product_id) {
        $saved = trim((string)get_post_meta($product_id,self::META_SUPPLIER,true));
        if ($saved !== '') return $saved;
        $source = trim((string)get_post_meta($product_id,self::META_SOURCE,true));
        return self::supplier_label($source);
    }

    public static function product_admin_columns($columns) {
        $out = array();
        foreach ($columns as $key=>$label) {
            $out[$key] = $label;
            if ($key === 'name') {
                $out['psb_supplier'] = 'Доставчик';
                $out['psb_source'] = 'Източник';
            }
        }
        return $out;
    }

    public static function product_admin_column_content($column, $post_id) {
        if ($column === 'psb_supplier') {
            echo '<strong>'.esc_html(self::product_supplier_label($post_id)).'</strong>';
            return;
        }
        if ($column === 'psb_source') {
            $source = (string)get_post_meta($post_id,self::META_SOURCE,true);
            $url = (string)get_post_meta($post_id,self::META_SOURCE_URL,true);
            if (!$source) { echo '<span style="color:#777">Ръчно</span>'; return; }
            if ($url) echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($source).' ↗</a>';
            else echo esc_html($source);
        }
    }

    public static function product_admin_supplier_filter($post_type, $which='top') {
        if ($post_type !== 'product' || $which !== 'top') return;
        $selected = isset($_GET['psb_supplier_source']) ? sanitize_text_field(wp_unslash($_GET['psb_supplier_source'])) : '';
        echo '<select name="psb_supplier_source"><option value="">Всички доставчици</option>';
        foreach (array('mma.bg','szfighters.com','kmsport.bg','leaderfitness.net') as $source) {
            echo '<option value="'.esc_attr($source).'" '.selected($selected,$source,false).'>'.esc_html(self::supplier_label($source)).'</option>';
        }
        echo '</select>';
    }

    public static function product_admin_supplier_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        global $pagenow;
        if ($pagenow !== 'edit.php' || $query->get('post_type') !== 'product') return;
        $source = isset($_GET['psb_supplier_source']) ? sanitize_text_field(wp_unslash($_GET['psb_supplier_source'])) : '';
        if (!$source) return;
        $meta = (array)$query->get('meta_query');
        $meta[] = array('key'=>self::META_SOURCE,'value'=>$source,'compare'=>'=');
        $query->set('meta_query',$meta);
    }

    private static function normalized_text($text) {
        $text = wp_strip_all_tags((string)$text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text);
        return preg_replace('/\s+/u',' ',trim($text));
    }

    private static function infer_brand($title, $brand='') {
        $brand = trim((string)$brand);
        if ($brand !== '') return $brand;
        $brands = array('Venum','Everlast','Leone','RDX','Fairtex','Ringhorns','Ground Game','Dominator','SZ Fighters','Pride Sport','Bad Boy','Phantom Athletics','Armageddon','Adidas','Manto','King Pro Boxing','KMSPORT','AMILA','Body Sculpture','Intex','Select','Nautilus','Schwinn','UFC','Reebok','Hayabusa','Twins','Top King','Tatami','Scramble','Century','Green Hill','Lonsdale','Metal Boxe');
        $low = self::normalized_text($title);
        foreach ($brands as $candidate) {
            if (false !== strpos($low, self::normalized_text($candidate))) return $candidate;
        }
        $parts = preg_split('/\s*[-–|]\s*/u',(string)$title);
        if (!empty($parts[0]) && strlen($parts[0]) >= 2 && strlen($parts[0]) <= 28 && !preg_match('/^(mma|мма|бокс|boxing|ръкавици|gloves|стак|комплект)$/iu',trim($parts[0]))) return trim($parts[0]);
        return '';
    }

    private static function ensure_category($name, $parent=0) {
        $name = trim((string)$name);
        if ($name==='') return 0;
        $exists = term_exists($name,'product_cat',$parent);
        if (!$exists) $exists = wp_insert_term($name,'product_cat',array('parent'=>(int)$parent));
        if (is_wp_error($exists)) return 0;
        return is_array($exists) ? (int)$exists['term_id'] : (int)$exists;
    }

    private static function source_category_id($raw_category,$equipment,$clothing,$fitness) {
        $raw=trim((string)$raw_category); if($raw==='')return 0;
        $n=self::normalized_text($raw);
        $equipment_names=array(
            'mma/граплинг ръкавици','боксови ръкавици','други ръкавици','карате ръкавици','протектори за уста','бинтове','протектори за крака','протектори за глава','други протектори','треньорски аксесоари','боксови чували','екипи за бойни изкуства'
        );
        $clothing_names=array('тениски','суитчъри и блузи','къси гащета','тренировъчни сакове и раници','други облекла','рашгарди','потници','клинове','шапки');
        $fitness_names=array('фитнес аксесоари','аксесоари','фоумролер','ортопедични аксесоари','ръкавици за фитнес','мъжки ръкавици за фитнес','дамски ръкавици за фитнес','въжета','шейкъри','колани','ластици','вериги за глава','фитили','жилетки с тежести','тежести');
        if(in_array($n,array_map(array(__CLASS__,'normalized_text'),$equipment_names),true))return self::ensure_category($raw,$equipment);
        if(in_array($n,array_map(array(__CLASS__,'normalized_text'),$clothing_names),true))return self::ensure_category($raw,$clothing);
        if(in_array($n,array_map(array(__CLASS__,'normalized_text'),$fitness_names),true))return self::ensure_category($raw,$fitness);
        if(preg_match('/^(айкидо|таекуондо|карате|джиу джицу|самбо|бразилско джиу джицу|джудо|кунг-фу)$/iu',$raw)){
            $uniforms=self::ensure_category('Екипи за бойни изкуства',$equipment);return self::ensure_category($raw,$uniforms);
        }
        if(preg_match('/детск.*протектор.*уст/iu',$raw)){
            $mouth=self::ensure_category('Протектори за уста',$equipment);return self::ensure_category($raw,$mouth);
        }
        if(preg_match('/гир|дъмб|теж|щанг|диск|лост|пейк|стойк|тренаж|кардио|вело|пътек|уред|кросфит|crossfit|fitness|фитнес|ластик|въже|топк|kettlebell|dumbbell/iu',$raw)) return self::ensure_category($raw,$fitness);
        if(preg_match('/бокс|mma|мма|кик|муай|ръкав|glove|протектор|каск|бинт|чувал|лап|карате|джудо|самбо|джиу|taekwondo/iu',$raw)) return self::ensure_category($raw,$equipment);
        return self::ensure_category($raw,self::ensure_category('Други категории'));
    }

    private static function ensure_category_path($path) {
        if(!is_array($path))return 0;
        $parent=0;$last=0;
        foreach($path as $name){
            $name=sanitize_text_field($name);
            if($name===''||preg_match('/^(начало|home|магазин|shop|всички продукти)$/iu',$name))continue;
            $last=self::ensure_category($name,$parent);
            if($last)$parent=$last;
        }
        return $last;
    }

    private static function classify_categories($title, $raw_category='', $attach_raw=true) {
        $text = self::normalized_text($title . ' ' . $raw_category);
        $ids = array();
        $equipment = self::ensure_category('Екипировка за бойни спортове');
        $clothing = self::ensure_category('Спортни облекла и дрехи');
        $fitness = self::ensure_category('Фитнес аксесоари');

        $rules = array(
            array('/(mma|мма|grappl|граплинг).*(ръкав|glove)|((ръкав|glove).*(mma|мма|grappl))/iu','MMA/Граплинг ръкавици',$equipment),
            array('/(боксов|boxing).*(ръкав|glove)|((ръкав|glove).*(бокс|boxing))/iu','Боксови ръкавици',$equipment),
            array('/карате.*(ръкав|glove)|(ръкав|glove).*карате/iu','Карате ръкавици',$equipment),
            array('/(mouthguard|mouth guard|протектор.*уст|гума.*зъб)/iu','Протектори за уста',$equipment),
            array('/(бинт|hand wrap|wraps)/iu','Бинтове',$equipment),
            array('/(shin guard|shinguard|кори|протектор.*крак|наколен)/iu','Протектори за крака',$equipment),
            array('/(head ?guard|каска|протектор.*глав)/iu','Протектори за глава',$equipment),
            array('/(лапи|падове|focus mitt|kick pad|thai pad|треньорск)/iu','Треньорски аксесоари',$equipment),
            array('/(боксов.*чувал|punching bag|heavy bag|чувал)/iu','Боксови чували',$equipment),
            array('/(ръкав|glove)/iu','Други ръкавици',$equipment),
            array('/(протектор|защит)/iu','Други протектори',$equipment),
            array('/(тениск|t-shirt|tshirt)/iu','Тениски',$clothing),
            array('/(суитч|hoodie|блуз)/iu','Суитчъри и блузи',$clothing),
            array('/(rashguard|рашгард)/iu','Рашгарди',$clothing),
            array('/(потник|tank top)/iu','Потници',$clothing),
            array('/(клин|leggings)/iu','Клинове',$clothing),
            array('/(шапк|cap|beanie)/iu','Шапки',$clothing),
            array('/(shorts|шорт|къси гащ)/iu','Къси гащета',$clothing),
            array('/(сак|раница|bag|backpack)/iu','Тренировъчни сакове и раници',$clothing),
            array('/(въже|jump rope|skipping)/iu','Въжета',$fitness),
            array('/(ластик|resistance band)/iu','Ластици',$fitness),
            array('/(шейкър|shaker)/iu','Шейкъри',$fitness),
            array('/(колан|belt)/iu','Колани',$fitness),
            array('/(foam ?roller|фоум)/iu','Фоумролер',$fitness),
            array('/(fitness.*glove|фитнес.*ръкав)/iu','Ръкавици за фитнес',$fitness),
            array('/(дъмбел|гиря|тежест|kettlebell|dumbbell)/iu','Тежести',$fitness),
        );
        foreach ($rules as $rule) if (preg_match($rule[0],$text)) { $id=self::ensure_category($rule[1],$rule[2]); if($id)$ids[]=$id; break; }

        foreach (array(
            'MMA'=>array('/\bmma\b|\bмма\b|граплинг/iu',$equipment),
            'Бокс'=>array('/бокс|boxing/iu',$equipment),
            'Кикбокс'=>array('/kickbox|кикбокс/iu',$equipment),
            'Муай Тай'=>array('/muay|муай/iu',$equipment),
            'За деца'=>array('/kids|junior|детск|за деца/iu',$equipment),
        ) as $name=>$rule) if (preg_match($rule[0],$text)) { $id=self::ensure_category($name,$rule[1]); if($id)$ids[]=$id; }

        if ($attach_raw && $raw_category!=='') { $id=self::source_category_id($raw_category,$equipment,$clothing,$fitness); if($id)$ids[]=$id; }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function normalize_weight_value($value) {
        $value = sanitize_text_field((string)$value);
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|кг)\b/iu',$value,$m)) {
            $n = rtrim(rtrim(number_format((float)str_replace(',','.',$m[1]),2,'.',''),'0'),'.');
            return $n.' кг';
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(g|гр|грама?)\b/iu',$value,$m)) {
            $n = rtrim(rtrim(number_format((float)str_replace(',','.',$m[1]),2,'.',''),'0'),'.');
            return $n.' г';
        }
        return $value;
    }

    private static function normalize_attributes($raw, $sizes=array()) {
        $attrs = array();
        if (!empty($raw['attributes']) && is_array($raw['attributes'])) {
            foreach ($raw['attributes'] as $name=>$values) {
                $name=sanitize_text_field($name); if($name==='') continue;
                if (!is_array($values)) $values=preg_split('/[,|;]/u',(string)$values);
                $clean=array(); foreach($values as $v){$v=sanitize_text_field($v);if($v!==''&&!preg_match('/избери|choose|select|--/iu',$v))$clean[]=$v;}
                if($clean)$attrs[$name]=array_values(array_unique($clean));
            }
        }
        if ($sizes) $attrs['Размер']=array_values(array_unique(array_merge(isset($attrs['Размер'])?$attrs['Размер']:array(),$sizes)));
        $title=(string)($raw['title']??'');
        if (empty($attrs['Цвят'])) {
            $colors=array('Black'=>'Черен','White'=>'Бял','Red'=>'Червен','Blue'=>'Син','Green'=>'Зелен','Yellow'=>'Жълт','Gold'=>'Златен','Silver'=>'Сребърен','Pink'=>'Розов','Purple'=>'Лилав','Orange'=>'Оранжев','Grey'=>'Сив','Gray'=>'Сив','Черен'=>'Черен','Бял'=>'Бял','Червен'=>'Червен','Син'=>'Син','Зелен'=>'Зелен');
            foreach($colors as $needle=>$label)if(false!==stripos($title,$needle))$attrs['Цвят'][]=$label;
            if(!empty($attrs['Цвят']))$attrs['Цвят']=array_values(array_unique($attrs['Цвят']));
        }
        if (empty($attrs['Грамаж']) && preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|кг|g|гр)\b/iu',$title,$m)) $attrs['Грамаж']=array(self::normalize_weight_value($m[1].' '.$m[2]));
        foreach ($attrs as $attr_name=>$values) {
            if (preg_match('/грамаж|тегло|weight/iu',$attr_name)) {
                $attrs[$attr_name]=array_values(array_unique(array_filter(array_map(array(__CLASS__,'normalize_weight_value'),(array)$values))));
            }
        }
        return $attrs;
    }

    private static function attribute_slug_for_name($name) {
        $n=self::normalized_text($name);
        if (preg_match('/размер|size/iu',$n)) return 'razmer';
        if (preg_match('/цвят|color|colour/iu',$n)) return 'tsvyat';
        if (preg_match('/грамаж|тегло|weight/iu',$n)) return 'gramazh';
        return '';
    }

    private static function build_product_attributes($product_id, $attrs, $variation_sizes=array()) {
        $wc_attrs=array(); $position=0;
        foreach ($attrs as $name=>$values) {
            $slug=self::attribute_slug_for_name($name);
            $tax=$slug ? 'pa_'.$slug : '';
            if ($slug && taxonomy_exists($tax)) {
                $term_ids=array();
                foreach($values as $value){$term=term_exists($value,$tax);if(!$term)$term=wp_insert_term($value,$tax);if(!is_wp_error($term))$term_ids[]=is_array($term)?(int)$term['term_id']:(int)$term;}
                if($term_ids){wp_set_object_terms($product_id,$term_ids,$tax,false);$a=new WC_Product_Attribute();$a->set_id(wc_attribute_taxonomy_id_by_name($tax));$a->set_name($tax);$a->set_options($term_ids);$a->set_position($position++);$a->set_visible(true);$a->set_variation($slug==='razmer' && !empty($variation_sizes));$wc_attrs[]=$a;}
            } else {
                $a=new WC_Product_Attribute();$a->set_id(0);$a->set_name($name);$a->set_options($values);$a->set_position($position++);$a->set_visible(true);$a->set_variation(false);$wc_attrs[]=$a;
            }
        }
        return $wc_attrs;
    }

    private static function category_pricing_rule($category_ids) {
        $rules=get_option(self::PRICING_KEY,array()); if(!is_array($rules))return array('markup'=>0,'promo'=>0);
        $best=array('depth'=>-1,'markup'=>0,'promo'=>0);
        foreach((array)$category_ids as $id){if(empty($rules[$id]))continue;$depth=count(get_ancestors((int)$id,'product_cat'));if($depth>$best['depth'])$best=array('depth'=>$depth,'markup'=>(float)($rules[$id]['markup']??0),'promo'=>(float)($rules[$id]['promo']??0));}
        return array('markup'=>$best['markup'],'promo'=>$best['promo']);
    }

    private static function apply_price_rule_values($source_price,$category_ids) {
        $source=(float)$source_price; if($source<=0)return array('regular'=>'','sale'=>'');
        $rule=self::category_pricing_rule($category_ids);
        $regular=round($source*(1+($rule['markup']/100)),2);
        $sale=''; if($rule['promo']>0 && $rule['promo']<100)$sale=round($regular*(1-($rule['promo']/100)),2);
        return array('regular'=>(string)$regular,'sale'=>$sale!==''?(string)$sale:'');
    }


    private static function pricing_values_from_raw(array $raw,$category_ids) {
        $current=isset($raw['sourcePrice'])?(float)$raw['sourcePrice']:0;
        if($current<=0)return array('regular'=>'','sale'=>'');
        $source_regular=isset($raw['sourceRegularPrice'])?(float)$raw['sourceRegularPrice']:0;
        $source_sale=isset($raw['sourceSalePrice'])?(float)$raw['sourceSalePrice']:0;
        $source_on_sale=!empty($raw['sourceOnSale']);

        // If the supplier has a genuine promotion, keep it as a real WooCommerce sale.
        // Kickbox.bg is exactly 2% lower than the supplier's current promo price.
        if($source_on_sale && $source_regular>0 && $source_sale>0 && $source_regular>$source_sale){
            $rule=self::category_pricing_rule($category_ids);
            $regular=round($source_regular*(1+($rule['markup']/100)),2);
            $sale=round($source_sale*0.98,2);
            if($regular<=0)$regular=$source_regular;
            if($sale<=0)$sale=$source_sale;
            if($sale >= $regular)$sale=round($regular*0.98,2);
            return array('regular'=>(string)$regular,'sale'=>(string)$sale);
        }
        return self::apply_price_rule_values($current,$category_ids);
    }

    private static function source_price_raw_from_product($product_id) {
        $current=(float)get_post_meta($product_id,self::META_SOURCE_PRICE,true);
        $regular=(float)get_post_meta($product_id,self::META_SOURCE_REGULAR_PRICE,true);
        $sale=(float)get_post_meta($product_id,self::META_SOURCE_SALE_PRICE,true);
        $on_sale=(bool)get_post_meta($product_id,self::META_SOURCE_ON_SALE,true);
        return array('sourcePrice'=>$current,'sourceRegularPrice'=>$regular,'sourceSalePrice'=>$sale,'sourceOnSale'=>$on_sale);
    }

    private static function assign_brand($product_id,$brand) {
        $brand=trim((string)$brand);
        update_post_meta($product_id,'_psb_brand',$brand);
        if($brand!=='' && taxonomy_exists('product_brand')){ $term=term_exists($brand,'product_brand');if(!$term)$term=wp_insert_term($brand,'product_brand');if(!is_wp_error($term)){wp_set_object_terms($product_id,array(is_array($term)?(int)$term['term_id']:(int)$term),'product_brand',false);delete_post_meta($product_id,'_psb_needs_brand');return;} }
        update_post_meta($product_id,'_psb_needs_brand',1);
    }

    private static function image_fingerprint($images) {
        if(!is_array($images)||empty($images[0]))return '';
        $url=preg_replace('/[?#].*$/','',strtolower(trim((string)$images[0])));
        return $url?hash('sha256',$url):'';
    }

    private static function expected_source_host($source) {
        $map=array(
            'mma.bg'=>'mma.bg',
            'szfighters.com'=>'szfighters.com',
            'kmsport.bg'=>'kmsport.bg',
            'leaderfitness.net'=>'leaderfitness.net',
        );
        $source=strtolower(trim((string)$source));
        return isset($map[$source])?$map[$source]:'';
    }

    private static function source_url_is_valid($source,$url) {
        $expected=self::expected_source_host($source);
        if(!$expected||!$url)return false;
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        $host=preg_replace('/^www\./','',$host);
        return $host===$expected || substr($host,-strlen('.'.$expected))==='.'. $expected;
    }

    private static function title_is_valid($title) {
        $title=trim(wp_strip_all_tags((string)$title));
        if(mb_strlen($title)<4 || mb_strlen($title)>240)return false;
        $bad=array('undefined','null','product','продукт','home','shop','начало','без име','no title');
        $low=mb_strtolower($title,'UTF-8');
        foreach($bad as $b) if($low===$b)return false;
        if(preg_match('/^[\W_]+$/u',$title))return false;
        return true;
    }

    private static function relevance_reason_from_text($title, $category='', $category_path=array()) {
        $title_n=self::normalized_text($title);
        $cat_text=(string)$category;
        if(is_array($category_path))$cat_text.=' '.implode(' ',array_map('sanitize_text_field',$category_path));
        else $cat_text.=' '.(string)$category_path;
        $cat_n=self::normalized_text($cat_text);

        // Equipment-looking products are allowed even when a supplier has placed them in a broad nutrition/fitness category.
        $gear_exception=preg_match('/(ръкав|glove|бинт|wrap|протектор|guard|каск|headguard|лап|pad|чувал|punching|short|шорт|тениск|t-?shirt|блуз|hoodie|rash|рашгард|клин|шап|сак|bag|раница|шейк|shaker|бутил|bottle|колан|belt|въже|rope|ластик|band|дъмб|dumbbell|гир|kettlebell|тежест|weight|стойк|пейк|bench|тренаж|машин|уред|mat|постел|бокс|boxing|kickbox|кикбокс|mma|мма|muay|муай|карате|джудо|самбо|джиу|taekwondo)/iu',$title_n);

        $hard_title=array(
            '/\b(bcaa|eaa)\b|аминокисел/iu'=>'Хранителна добавка / аминокиселини',
            '/аргинин|arginine|глутамин|glutamine|креатин|creatine|цитрулин|citrulline|бета[ -]?аланин|beta[ -]?alanine/iu'=>'Хранителна добавка',
            '/\b(whey|casein|protein)\b|протеин(?!ов.*шейк)/iu'=>'Протеин / хранителна добавка',
            '/гейн[ъе]р|gainer|предтрениров|pre[ -]?workout|post[ -]?workout|напомпва|nitric|азотн/iu'=>'Предтренировъчен / хранителна добавка',
            '/карнитин|carnitine|fat[ -]?burn|горелк.*мазнин|cla\b|testosterone|тестостерон.*буст/iu'=>'Добавка за контрол на тегло/хормонален бустер',
            '/витамин|multivitamin|минерал|omega[ -]?3|омега[ -]?3|колаген|collagen|zma\b/iu'=>'Витамини / хранителна добавка',
            '/спрей.*готвен|cooking[ -]?spray|сироп|syrup|фъстъчено.*масло|peanut[ -]?butter|protein[ -]?bar|протеинов.*бар|energy[ -]?bar|овесен.*(бар|каша)|сос\b|sauce\b|подсладител|sweetener/iu'=>'Храна / продукт за готвене',
            '/масажиращ.*възглавниц|massage.*pillow|мемори.*възглавниц|memory.*pillow|ортопедич.*възглавниц|масажен.*пистолет|massage.*gun|масажор(?!.*ролер)|massage.*chair|матрак|mattress|сауна|sauna|козметик|cosmetic/iu'=>'Домашен/уелнес продукт, несвързан с бойни спортове',
        );
        foreach($hard_title as $rx=>$reason){
            if(preg_match($rx,$title_n) && !$gear_exception)return $reason;
        }

        $bad_category='/хранителн.*добав|спортн.*добав|аминокисел|\bbcaa\b|\beaa\b|протеин|креатин|глутамин|аргинин|предтрениров|pre[ -]?workout|напомпва|азотн|гейн[ъе]р|fat[ -]?burn|мазнин.*гор|витамин|минерал|колаген|омега|карнитин|здравословн.*хран|спортно хранене|суплемент|supplement|спрей.*готвен|cooking/iu';
        if(preg_match($bad_category,$cat_n) && !$gear_exception)return 'Неподходяща категория за Kickbox.bg';

        $all=$title_n.' '.$cat_n;
        $positive=$gear_exception || preg_match('/(екипиров|бойн.*спорт|martial|combat|fitness|фитнес|трениров|training|gym|зала|кардио|cardio|лост|barbell|диск|plate|дъмб|dumbbell|гир|kettlebell|пейк|bench|стойк|rack|тренаж|machine|велоерг|treadmill|пътека|гребен|rowing|ролер|roller|постел|mat|йога|yoga|тежест|weight|спортн.*дрех|облекл|тениск|t-?shirt|суитч|hoodie|шорт|short|рашгард|rashguard|клин|legging|потник|tank|спортен сак|training bag|раница|backpack|спортна бутил|bottle|шейкър|shaker|ръкавиц|glove|бинт|wrap|протектор|guard|каск|headguard|лап|focus mitt|чувал|punching bag|въже|jump rope|ластик|resistance band|колан|lifting belt|наколен|knee|налакът|elbow|наглезен|ankle|бокс|boxing|kickbox|кикбокс|mma|мма|muay|муай|карате|karate|джудо|judo|самбо|sambo|джиу|jiu|taekwondo|борба|wrestling|граплинг|grappling)/iu',$all);
        if(!$positive)return 'Няма ясна връзка с бойни спортове, спортно облекло или тренировъчна/фитнес екипировка';
        return '';
    }

    private static function relevance_reason_from_raw(array $raw) {
        return self::relevance_reason_from_text(
            sanitize_text_field($raw['title']??''),
            sanitize_text_field($raw['category']??''),
            isset($raw['categoryPath'])&&is_array($raw['categoryPath'])?$raw['categoryPath']:array()
        );
    }

    private static function ignored_sources() {
        $rows=get_option(self::IGNORED_KEY,array());
        return is_array($rows)?$rows:array();
    }

    private static function is_ignored_source_url($source,$url) {
        if(!$source||!$url)return false;
        $rows=self::ignored_sources();
        return isset($rows[self::source_key($source,self::normalize_source_url($url))]);
    }

    private static function mark_ignored_source_url($source,$url,$reason,$title='') {
        if(!$source||!$url)return;
        $url=self::normalize_source_url($url);
        $rows=self::ignored_sources();
        $rows[self::source_key($source,$url)]=array(
            'source'=>sanitize_text_field($source),'url'=>$url,'reason'=>sanitize_text_field($reason),
            'title'=>sanitize_text_field($title),'time'=>current_time('mysql')
        );
        if(count($rows)>12000)$rows=array_slice($rows,-12000,null,true);
        update_option(self::IGNORED_KEY,$rows,false);
    }

    private static function quarantine_irrelevant_product($product_id,$reason) {
        $product_id=(int)$product_id; if(!$product_id)return false;
        $p=wc_get_product($product_id); if(!$p)return false;
        update_post_meta($product_id,self::META_IRRELEVANT,1);
        update_post_meta($product_id,self::META_IRRELEVANT_REASON,sanitize_text_field($reason));
        update_post_meta($product_id,'_psb_bestseller',0);
        $p->set_featured(false);
        $p->set_catalog_visibility('hidden');
        $p->save();
        if(get_post_status($product_id)!=='draft')wp_update_post(array('ID'=>$product_id,'post_status'=>'draft'));
        $source=(string)get_post_meta($product_id,self::META_SOURCE,true);
        $url=(string)get_post_meta($product_id,self::META_SOURCE_URL,true);
        if($source&&$url)self::mark_ignored_source_url($source,$url,$reason,$p->get_name());
        return true;
    }

    private static function raw_quality_issues(array $raw) {
        $issues=array();
        $source=sanitize_text_field($raw['source']??'');
        $url=self::normalize_source_url($raw['sourceUrl']??'');
        $title=sanitize_text_field($raw['title']??'');
        if(!$source || !$url || !self::source_url_is_valid($source,$url))$issues[]='Невалиден/липсващ source URL';
        if(!self::title_is_valid($title))$issues[]='Невалидно име на продукт';
        if(isset($raw['sourcePrice']) && !is_numeric($raw['sourcePrice']))$issues[]='Невалидна цена';
        if(isset($raw['sourceRegularPrice']) && $raw['sourceRegularPrice']!==null && $raw['sourceRegularPrice']!=='' && !is_numeric($raw['sourceRegularPrice']))$issues[]='Невалидна редовна цена';
        if(isset($raw['sourceSalePrice']) && $raw['sourceSalePrice']!==null && $raw['sourceSalePrice']!=='' && !is_numeric($raw['sourceSalePrice']))$issues[]='Невалидна промо цена';
        return $issues;
    }

    private static function sync_variations_for_sizes($product_id,$sizes,$prices,$in_stock) {
        $sizes=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)$sizes))));
        if(!$sizes)return;
        wp_set_object_terms($product_id,'variable','product_type');
        $product=new WC_Product_Variable($product_id);
        $attrs=self::normalize_attributes(array('title'=>$product->get_name()),$sizes);
        $wc_attrs=self::build_product_attributes($product_id,$attrs,$sizes);
        if($wc_attrs)$product->set_attributes($wc_attrs);
        $product->set_stock_status($in_stock?'instock':'outofstock');
        $product->save();
        $tax='pa_razmer';
        $existing=array();
        foreach($product->get_children() as $vid){
            $v=wc_get_product($vid); if(!$v)continue;
            $va=$v->get_attributes();
            $val=(string)($va[$tax]??$va['razmer']??'');
            if($val)$existing[$val]=$v;
        }
        foreach($sizes as $size){
            $term=taxonomy_exists($tax)?get_term_by('name',$size,$tax):false;
            $value=$term?$term->slug:sanitize_title($size);
            $v=$existing[$value]??null;
            if(!$v){$v=new WC_Product_Variation();$v->set_parent_id($product_id);}
            $v->set_regular_price((string)$prices['regular']);
            $v->set_sale_price($prices['sale']!==''?(string)$prices['sale']:'');
            $v->set_stock_status($in_stock?'instock':'outofstock');
            $v->set_attributes(array($tax=>$value));
            $v->save();
        }
        WC_Product_Variable::sync($product_id);
    }

    private static function refresh_existing_product($product_id,array $raw) {
        $product_id=(int)$product_id;
        $product=wc_get_product($product_id); if(!$product)return false;
        $source=sanitize_text_field($raw['source']??'');
        $source_url=self::normalize_source_url($raw['sourceUrl']??'');
        $source_id=sanitize_text_field($raw['sourceProductId']??'');
        $sku=sanitize_text_field($raw['sku']??'');
        $title=sanitize_text_field($raw['title']??'');
        $quality=self::raw_quality_issues($raw);
        if($quality){
            update_post_meta($product_id,'_psb_quality_issues',$quality);
            update_post_meta($product_id,'_psb_quality_quarantined',1);
            wp_update_post(array('ID'=>$product_id,'post_status'=>'draft'));
            self::audit_product($product_id);
            return false;
        }
        delete_post_meta($product_id,'_psb_quality_quarantined');
        delete_post_meta($product_id,'_psb_quality_issues');
        if(get_post_meta($product_id,self::META_IRRELEVANT,true)){
            delete_post_meta($product_id,self::META_IRRELEVANT); delete_post_meta($product_id,self::META_IRRELEVANT_REASON);
            $product->set_catalog_visibility('visible');
        }
        $brand=self::infer_brand($title,sanitize_text_field($raw['brand']??''));
        $source_price=isset($raw['sourcePrice'])?(float)$raw['sourcePrice']:0;
        $sizes=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($raw['sizes']??array())))));
        $category_ids=self::classify_categories($title,sanitize_text_field($raw['category']??''),true);
        $prices=self::pricing_values_from_raw($raw,$category_ids);
        $in_stock=$source_price>0 && !empty($raw['inStock']);
        $product->set_name($title);
        $product->set_description(wp_kses_post($raw['description']??''));
        if($category_ids)$product->set_category_ids($category_ids);
        if(!$sizes){
            $product->set_regular_price((string)$prices['regular']);
            $product->set_sale_price($prices['sale']!==''?(string)$prices['sale']:'');
            $product->set_stock_status($in_stock?'instock':'outofstock');
            $product->save();
        } else {
            $product->save();
            self::sync_variations_for_sizes($product_id,$sizes,$prices,$in_stock);
        }
        if($sku!==''&&!wc_get_product_id_by_sku($sku)){$product=wc_get_product($product_id);$product->set_sku($sku);$product->save();}
        update_post_meta($product_id,self::META_SOURCE,$source);
        update_post_meta($product_id,self::META_SOURCE_URL,$source_url);
        update_post_meta($product_id,self::META_SOURCE_ID,$source_id);
        update_post_meta($product_id,self::META_SOURCE_PRICE,$source_price>0?$source_price:'');
        update_post_meta($product_id,self::META_SOURCE_REGULAR_PRICE,isset($raw['sourceRegularPrice'])&&(float)$raw['sourceRegularPrice']>0?(float)$raw['sourceRegularPrice']:'');
        update_post_meta($product_id,self::META_SOURCE_SALE_PRICE,isset($raw['sourceSalePrice'])&&(float)$raw['sourceSalePrice']>0?(float)$raw['sourceSalePrice']:'');
        update_post_meta($product_id,self::META_SOURCE_ON_SALE,!empty($raw['sourceOnSale'])?1:0);
        update_post_meta($product_id,self::META_SUPPLIER,self::supplier_label($source));
        update_post_meta($product_id,self::META_FINGERPRINT,self::fingerprint($brand,$title));
        update_post_meta($product_id,'_psb_missing_price',$source_price>0?0:1);
        self::register_source_alias($product_id,$source,$source_url,$source_id,$sku);
        self::assign_brand($product_id,$brand);
        self::queue_product_images($product_id,$raw['images']??array());
        update_post_meta($product_id,self::META_PAYLOAD_HASH,self::raw_payload_hash($raw));
        $s=self::settings();
        if($s['product_status']!=='draft' && $source_price>0)wp_update_post(array('ID'=>$product_id,'post_status'=>'publish'));
        self::queue_audit_product($product_id);
        return true;
    }

    private static function audit_product($product_id) {
        $product=wc_get_product($product_id); if(!$product)return array();
        $issues=array();
        if((float)$product->get_regular_price()<=0 && (float)$product->get_sale_price()<=0)$issues[]='Без цена';
        $desc=trim(wp_strip_all_tags($product->get_description())); if(strlen($desc)<40)$issues[]='Липсва/кратко описание';
        if(!$product->get_image_id())$issues[]='Без снимка';
        $source=(string)get_post_meta($product_id,self::META_SOURCE,true);$source_url=(string)get_post_meta($product_id,self::META_SOURCE_URL,true);
        $rel_reason=self::relevance_reason_from_text($product->get_name(),self::product_category_names($product_id),json_decode((string)get_post_meta($product_id,self::META_CATEGORY_PATH,true),true)); if($rel_reason)$issues[]='Неподходящ за Kickbox.bg: '.$rel_reason;
        if($source && !self::source_url_is_valid($source,$source_url))$issues[]='Липсва/невалидна връзка към доставчик';
        if(!self::title_is_valid($product->get_name()))$issues[]='Подозрително име';
        if($product->is_type('variable') && (float)$product->get_price()<=0)$issues[]='Вариантите нямат валидна цена';
        $brands=taxonomy_exists('product_brand')?wp_get_object_terms($product_id,'product_brand',array('fields'=>'names')):array();if(!$brands||is_wp_error($brands))$issues[]='Без производител';
        $fp=get_post_meta($product_id,self::META_FINGERPRINT,true);if($fp){$dups=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>2,'post__not_in'=>array($product_id),'meta_key'=>self::META_FINGERPRINT,'meta_value'=>$fp));if($dups)$issues[]='Възможен дубликат';}
        $ifp=get_post_meta($product_id,'_psb_image_fingerprint',true);if($ifp){$dups=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'post__not_in'=>array($product_id),'meta_key'=>'_psb_image_fingerprint','meta_value'=>$ifp));if($dups)$issues[]='Повтаряща се основна снимка';}
        update_post_meta($product_id,'_psb_issues',$issues); update_post_meta($product_id,'_psb_has_issues',$issues?1:0);
        return $issues;
    }

    public static function audit_batch() {
        $ids=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>120,'orderby'=>'modified','order'=>'DESC'));
        foreach($ids as $id)self::audit_product($id);
    }

    private static function find_source_alias($source, $source_url) {
        $key = self::source_key($source, $source_url);
        $ids = get_posts(array(
            'post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,
            'meta_key'=>self::META_SOURCE_KEYS_MULTI,'meta_value'=>$key
        ));
        return $ids ? (int)$ids[0] : 0;
    }

    private static function register_source_alias($product_id, $source, $source_url, $source_id = '', $sku = '') {
        $product_id = (int)$product_id;
        if (!$product_id || !$source || !$source_url) return;
        $source_url = self::normalize_source_url($source_url);
        $key = self::source_key($source, $source_url);
        $existing_keys = get_post_meta($product_id, self::META_SOURCE_KEYS_MULTI, false);
        if (!in_array($key, (array)$existing_keys, true)) add_post_meta($product_id, self::META_SOURCE_KEYS_MULTI, $key, false);

        $aliases = json_decode((string)get_post_meta($product_id, self::META_SOURCE_ALIASES, true), true);
        if (!is_array($aliases)) $aliases = array();
        $aliases[$key] = array('source'=>$source,'url'=>$source_url,'source_id'=>$source_id,'sku'=>$sku);
        update_post_meta($product_id, self::META_SOURCE_ALIASES, wp_json_encode(array_values($aliases), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        $suppliers = json_decode((string)get_post_meta($product_id, self::META_SUPPLIERS, true), true);
        if (!is_array($suppliers)) $suppliers = array();
        $suppliers[] = self::supplier_label($source);
        update_post_meta($product_id, self::META_SUPPLIERS, wp_json_encode(array_values(array_unique($suppliers)), JSON_UNESCAPED_UNICODE));
    }

    private static function find_merge_candidate($source, $source_id, $sku, $fingerprint, $title = '', $brand = '') {
        // Same supplier source ID is a hard duplicate.
        if ($source && $source_id !== '') {
            $ids = get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'meta_query'=>array('relation'=>'AND',array('key'=>self::META_SOURCE,'value'=>$source),array('key'=>self::META_SOURCE_ID,'value'=>$source_id))));
            if ($ids) return (int)$ids[0];
        }
        // Same SKU or exact brand+title from another supplier: merge supplier listing instead of dropping it.
        if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) { $id = wc_get_product_id_by_sku($sku); if ($id) return (int)$id; }
        if ($fingerprint !== '') {
            $ids = get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'meta_key'=>self::META_FINGERPRINT,'meta_value'=>$fingerprint));
            if ($ids) return (int)$ids[0];
        }
        // Legacy imports may not have fingerprint meta. Exact title + matching brand is a safe fallback.
        if ($title !== '') {
            $ids = get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>10,'title'=>$title));
            foreach((array)$ids as $id){
                $existing_brand=self::product_current_brand((int)$id);
                if($brand==='' || $existing_brand==='' || self::fingerprint($existing_brand,$title)===self::fingerprint($brand,$title)) return (int)$id;
            }
        }
        return 0;
    }

    private static function raw_payload_hash(array $raw) {
        $payload = array(
            'source' => sanitize_text_field($raw['source'] ?? ''),
            'sourceUrl' => self::normalize_source_url($raw['sourceUrl'] ?? ''),
            'sourceProductId' => sanitize_text_field($raw['sourceProductId'] ?? ''),
            'sku' => sanitize_text_field($raw['sku'] ?? ''),
            'title' => sanitize_text_field($raw['title'] ?? ''),
            'brand' => sanitize_text_field($raw['brand'] ?? ''),
            'description' => wp_strip_all_tags((string)($raw['description'] ?? '')),
            'sourcePrice' => (string)($raw['sourcePrice'] ?? ''),
            'sourceRegularPrice' => (string)($raw['sourceRegularPrice'] ?? ''),
            'sourceSalePrice' => (string)($raw['sourceSalePrice'] ?? ''),
            'sourceOnSale' => !empty($raw['sourceOnSale']) ? 1 : 0,
            'inStock' => !empty($raw['inStock']) ? 1 : 0,
            'category' => sanitize_text_field($raw['category'] ?? ''),
            'categoryPath' => array_values(array_map('sanitize_text_field', (array)($raw['categoryPath'] ?? array()))),
            'sizes' => array_values(array_unique(array_map('sanitize_text_field', (array)($raw['sizes'] ?? array())))),
            'images' => array_slice(array_values(array_map('esc_url_raw', (array)($raw['images'] ?? array()))), 0, 3),
        );
        return hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function existing_matches_raw_fast($product_id, array $raw) {
        $product = wc_get_product((int)$product_id);
        if (!$product) return false;
        $title = sanitize_text_field($raw['title'] ?? '');
        if (self::normalized_text($product->get_name()) !== self::normalized_text($title)) return false;
        $raw_source_price = isset($raw['sourcePrice']) ? (float)$raw['sourcePrice'] : 0.0;
        $stored_source_price = (float)get_post_meta($product_id, self::META_SOURCE_PRICE, true);
        if (abs($raw_source_price - $stored_source_price) > 0.001) return false;
        $raw_regular = isset($raw['sourceRegularPrice']) ? (float)$raw['sourceRegularPrice'] : 0.0;
        $stored_regular = (float)get_post_meta($product_id, self::META_SOURCE_REGULAR_PRICE, true);
        if ($raw_regular > 0 && abs($raw_regular - $stored_regular) > 0.001) return false;
        $raw_sale = isset($raw['sourceSalePrice']) ? (float)$raw['sourceSalePrice'] : 0.0;
        $stored_sale = (float)get_post_meta($product_id, self::META_SOURCE_SALE_PRICE, true);
        if ($raw_sale > 0 && abs($raw_sale - $stored_sale) > 0.001) return false;
        $raw_img = self::image_fingerprint($raw['images'] ?? array());
        $stored_img = (string)get_post_meta($product_id, '_psb_image_fingerprint', true);
        if ($raw_img && $stored_img && $raw_img !== $stored_img) return false;
        $raw_desc = self::normalized_text(wp_strip_all_tags((string)($raw['description'] ?? '')));
        $cur_desc = self::normalized_text(wp_strip_all_tags((string)$product->get_description()));
        if ($raw_desc !== '' && $cur_desc !== '' && hash('sha256',$raw_desc) !== hash('sha256',$cur_desc)) return false;
        $sizes = array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($raw['sizes']??array())))));
        if ($sizes && !$product->is_type('variable')) return false;
        if ($sizes && $product->is_type('variable') && count($product->get_children()) < min(1,count($sizes))) return false;
        return true;
    }

    private static function import_one(array $raw) {
        $source=sanitize_text_field($raw['source']??'');
        $source_url=self::normalize_source_url($raw['sourceUrl']??'');
        $source_id=sanitize_text_field($raw['sourceProductId']??'');
        $sku=sanitize_text_field($raw['sku']??'');
        $title=sanitize_text_field($raw['title']??'');
        $brand=self::infer_brand($title,sanitize_text_field($raw['brand']??''));
        $fingerprint=self::fingerprint($brand,$title);
        if(!$source||!$source_url||!$title)throw new Exception('Продуктът няма source, sourceUrl или title.');
        $quality=self::raw_quality_issues($raw);
        if($quality)throw new Exception('Пропуснат невалиден продукт: '.implode(', ',$quality).' — '.$title);
        $irrelevant=self::relevance_reason_from_raw($raw);
        if($irrelevant){
            $existing_irrelevant=self::find_existing($source,$source_url,$source_id,'','');
            if($existing_irrelevant)self::quarantine_irrelevant_product($existing_irrelevant,$irrelevant);
            self::mark_ignored_source_url($source,$source_url,$irrelevant,$title);
            return array('status'=>'ignored','productId'=>(int)$existing_irrelevant,'title'=>$title,'reason'=>$irrelevant);
        }

        // 1) Exact listing already linked. V24 skips expensive WooCommerce saves when the source payload is unchanged.
        $payload_hash=self::raw_payload_hash($raw);
        $existing=self::find_existing($source,$source_url,$source_id,'','');
        if($existing){
            $stored_hash=(string)get_post_meta($existing,self::META_PAYLOAD_HASH,true);
            if(($stored_hash && hash_equals($stored_hash,$payload_hash)) || (!$stored_hash && self::existing_matches_raw_fast($existing,$raw))){
                if(!$stored_hash) update_post_meta($existing,self::META_PAYLOAD_HASH,$payload_hash);
                self::register_source_alias($existing,$source,$source_url,$source_id,$sku);
                return array('status'=>'duplicate','productId'=>(int)$existing,'title'=>$title);
            }
            self::refresh_existing_product($existing,$raw);
            update_post_meta($existing,self::META_PAYLOAD_HASH,$payload_hash);
            return array('status'=>'refreshed','productId'=>(int)$existing,'title'=>$title);
        }

        // 2) Same real product from another supplier: keep ONE storefront product, but register this supplier URL as covered.
        $merge=self::find_merge_candidate($source,$source_id,$sku,$fingerprint,$title,$brand);
        if($merge){ self::register_source_alias($merge,$source,$source_url,$source_id,$sku); return array('status'=>'merged','productId'=>(int)$merge,'title'=>$title); }

        $source_price=isset($raw['sourcePrice'])?(float)$raw['sourcePrice']:0;
        $has_valid_price=$source_price>0;
        $sizes=array();foreach((array)($raw['sizes']??array()) as $size){$size=sanitize_text_field($size);if($size!=='')$sizes[]=$size;}$sizes=array_values(array_unique($sizes));
        $raw_category=sanitize_text_field($raw['category']??'');
        $has_category_path=!empty($raw['categoryPath'])&&is_array($raw['categoryPath']);
        // Keep the original breadcrumb for admin/reference, but do not create every breadcrumb node as a public shop category.
        // This prevents the shop from exploding into 100+ top-level categories.
        $category_ids=self::classify_categories($title,$raw_category,true);
        $prices=self::pricing_values_from_raw($raw,$category_ids);
        $attributes=self::normalize_attributes($raw,$sizes);

        $s=self::settings();
        $product=$sizes?new WC_Product_Variable():new WC_Product_Simple();
        $product->set_name($title);
        $product->set_status($s['product_status']==='draft'?'draft':'publish');
        $product->set_catalog_visibility('visible');
        $product->set_description(wp_kses_post($raw['description']??''));
        $product->set_regular_price($prices['regular']);
        if($prices['sale']!=='')$product->set_sale_price($prices['sale']);
        $product->set_manage_stock(false);
        $product->set_stock_status($has_valid_price&&!empty($raw['inStock'])?'instock':'outofstock');
        if($sku!==''&&!wc_get_product_id_by_sku($sku))$product->set_sku($sku);
        if($category_ids)$product->set_category_ids($category_ids);

        // Save first, then attach global taxonomy attributes.
        $product_id=$product->save(); if(!$product_id)throw new Exception('WooCommerce не успя да създаде продукта: '.$title);
        $wc_attrs=self::build_product_attributes($product_id,$attributes,$sizes);
        if($wc_attrs){$product=wc_get_product($product_id);$product->set_attributes($wc_attrs);$product->save();}

        update_post_meta($product_id,self::META_SOURCE,$source);
        update_post_meta($product_id,self::META_SOURCE_URL,$source_url);
        update_post_meta($product_id,self::META_SOURCE_ID,$source_id);
        update_post_meta($product_id,self::META_SOURCE_KEY,self::source_key($source,$source_url));
        update_post_meta($product_id,self::META_SOURCE_PRICE,$has_valid_price?$source_price:'');
        update_post_meta($product_id,self::META_SOURCE_REGULAR_PRICE,isset($raw['sourceRegularPrice'])&&(float)$raw['sourceRegularPrice']>0?(float)$raw['sourceRegularPrice']:'');
        update_post_meta($product_id,self::META_SOURCE_SALE_PRICE,isset($raw['sourceSalePrice'])&&(float)$raw['sourceSalePrice']>0?(float)$raw['sourceSalePrice']:'');
        update_post_meta($product_id,self::META_SOURCE_ON_SALE,!empty($raw['sourceOnSale'])?1:0);
        update_post_meta($product_id,self::META_SUPPLIER,self::supplier_label($source));
        self::register_source_alias($product_id,$source,$source_url,$source_id,$sku);
        update_post_meta($product_id,self::META_CATEGORY_PATH,$has_category_path?wp_json_encode(array_values(array_map('sanitize_text_field',$raw['categoryPath'])),JSON_UNESCAPED_UNICODE):'');
        update_post_meta($product_id,'_psb_missing_price',$has_valid_price?0:1);
        update_post_meta($product_id,self::META_FINGERPRINT,$fingerprint);
        update_post_meta($product_id,'_psb_attributes_json',wp_json_encode($attributes,JSON_UNESCAPED_UNICODE));
        update_post_meta($product_id,'_psb_image_fingerprint',self::image_fingerprint($raw['images']??array()));
        update_post_meta($product_id,self::META_PAYLOAD_HASH,$payload_hash);
        self::assign_brand($product_id,$brand);

        $seo_description=wp_trim_words(wp_strip_all_tags((string)($raw['description']??'')),28,'…');if(!$seo_description)$seo_description=$title;
        // Avoid four unnecessary DB writes per product when an SEO plugin is not active.
        if(defined('WPSEO_VERSION') || class_exists('WPSEO_Options')){update_post_meta($product_id,'_yoast_wpseo_title',$title);update_post_meta($product_id,'_yoast_wpseo_metadesc',$seo_description);}
        if(defined('RANK_MATH_VERSION') || class_exists('RankMath')){update_post_meta($product_id,'rank_math_title',$title);update_post_meta($product_id,'rank_math_description',$seo_description);}

        if($sizes){
            $tax='pa_razmer';
            foreach($sizes as $size){
                $variation=new WC_Product_Variation();$variation->set_parent_id($product_id);$variation->set_regular_price($prices['regular']);if($prices['sale']!=='')$variation->set_sale_price($prices['sale']);$variation->set_stock_status($has_valid_price&&!empty($raw['inStock'])?'instock':'outofstock');
                if(taxonomy_exists($tax)){$term=get_term_by('name',$size,$tax);$value=$term?$term->slug:sanitize_title($size);$variation->set_attributes(array($tax=>$value));}else{$variation->set_attributes(array(sanitize_title('Размер')=>$size));}
                $variation->save();
            }
        }
        self::queue_product_images($product_id,$raw['images']??array());
        self::queue_audit_product($product_id);
        return array('status'=>$has_valid_price?'created':'created_no_price','productId'=>(int)$product_id,'title'=>$title);
    }

    private static function find_existing($source, $source_url, $source_id = '', $sku = '', $fingerprint = '') {
        if ($source && $source_url) {
            $source_url = self::normalize_source_url($source_url);
            $key = self::source_key($source, $source_url);
            $ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => array('relation'=>'OR',
                    array('key'=>self::META_SOURCE_KEY,'value'=>$key),
                    array('key'=>self::META_SOURCE_KEYS_MULTI,'value'=>$key),
                    // Legacy V4-V8 imports stored only the raw source URL.
                    array('key'=>self::META_SOURCE_URL,'value'=>$source_url),
                ),
            ));
            if ($ids) {
                self::register_source_alias((int)$ids[0],$source,$source_url,$source_id,$sku);
                return (int) $ids[0];
            }
        }

        if ($source && $source_id !== '') {
            $ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => self::META_SOURCE, 'value' => $source),
                    array('key' => self::META_SOURCE_ID, 'value' => $source_id),
                ),
            ));
            if ($ids) return (int) $ids[0];
        }

        if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $id = wc_get_product_id_by_sku($sku);
            if ($id) return (int) $id;
        }

        if ($fingerprint !== '') {
            $ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => self::META_FINGERPRINT,
                'meta_value' => $fingerprint,
            ));
            if ($ids) return (int) $ids[0];
        }

        return 0;
    }

    private static function source_key($source, $source_url) {
        return hash('sha256', strtolower(trim($source)) . '|' . untrailingslashit(strtolower(trim($source_url))));
    }

    private static function fingerprint($brand, $title) {
        $brand = trim((string)$brand);
        $title = trim((string)$title);
        if ($brand === '' || $title === '') return '';
        $text = $brand . '|' . $title;
        if (function_exists('mb_strtolower')) $text = mb_strtolower($text, 'UTF-8'); else $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
        return strlen($text) >= 8 ? hash('sha256', $text) : '';
    }

    private static function get_image_queue() {
        $queue = get_option(self::IMAGE_QUEUE_KEY, array());
        return is_array($queue) ? array_values($queue) : array();
    }

    private static function save_image_queue($queue) {
        update_option(self::IMAGE_QUEUE_KEY, array_values(is_array($queue) ? $queue : array()), false);
    }

    private static function queue_product_images($product_id, $images) {
        if (!is_array($images) || !$images) return;
        $clean = array();
        foreach (array_slice(array_values(array_unique($images)), 0, 3) as $url) {
            $url = self::normalize_source_url($url);
            if ($url) $clean[] = $url;
        }
        if (!$clean) return;

        if (self::$bulk_mode) {
            self::$bulk_image_jobs[(int)$product_id] = array('product_id'=>(int)$product_id,'urls'=>$clean,'index'=>0);
            return;
        }

        $queue = self::get_image_queue();
        $found = false;
        foreach ($queue as &$item) {
            if ((int)($item['product_id'] ?? 0) === (int)$product_id) {
                $item['urls'] = $clean;
                $item['index'] = isset($item['index']) ? (int)$item['index'] : 0;
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) $queue[] = array('product_id'=>(int)$product_id,'urls'=>$clean,'index'=>0);
        self::save_image_queue($queue);
        // During visible live import, images are intentionally deferred until ALL
        // products from all suppliers are created. This prevents media downloads
        // from competing with WooCommerce writes on shared hosting.
        if (!self::live_mode_active()) self::schedule_action(self::IMAGE_HOOK, array(), 5);
    }

    public static function image_import_sizes($sizes) {
        $keep=array('thumbnail','medium','woocommerce_thumbnail','woocommerce_single','woocommerce_gallery_thumbnail');
        $out=array();
        foreach((array)$sizes as $name=>$cfg) if(in_array($name,$keep,true)) $out[$name]=$cfg;
        return $out ?: $sizes;
    }

    private static function existing_attachment_for_source($url) {
        static $cache=array();
        $key=md5((string)$url);
        if(isset($cache[$key])) return (int)$cache[$key];
        $ids=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>1,'meta_key'=>'_source_url','meta_value'=>$url));
        $cache[$key]=$ids?(int)$ids[0]:0;
        return (int)$cache[$key];
    }

    public static function image_worker($direct = false) {
        if (self::live_mode_active() && !$direct) return;
        $queue = self::get_image_queue();
        if (!$queue) return;
        $item = array_shift($queue);
        $product_id = (int)($item['product_id'] ?? 0);
        $urls = !empty($item['urls']) && is_array($item['urls']) ? array_values($item['urls']) : array();
        $index = max(0, (int)($item['index'] ?? 0));
        if (!$product_id || !$urls || $index >= count($urls) || get_post_type($product_id) !== 'product') {
            self::save_image_queue($queue);
            if ($queue && !self::live_mode_active()) self::schedule_action(self::IMAGE_HOOK, array(), 5);
            return;
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $url = self::normalize_source_url($urls[$index]);
        if ($url) {
            $existing_attachment=self::existing_attachment_for_source($url);
            if($existing_attachment){
                $id=$existing_attachment;
            }else{
                // Product imports do not need every WordPress image size. Keeping only
                // the storefront sizes reduces CPU/RAM while WooCommerce thumbnails stay correct.
                add_filter('intermediate_image_sizes_advanced',array(__CLASS__,'image_import_sizes'),99,1);
                $id = media_sideload_image($url, $product_id, get_the_title($product_id), 'id');
                remove_filter('intermediate_image_sizes_advanced',array(__CLASS__,'image_import_sizes'),99);
            }
            if (!is_wp_error($id)) {
                $id = (int)$id;
                if (!get_post_thumbnail_id($product_id)) {
                    set_post_thumbnail($product_id, $id);
                } else {
                    $gallery = array_filter(array_map('absint', explode(',', (string)get_post_meta($product_id, '_product_image_gallery', true))));
                    if (!in_array($id, $gallery, true)) $gallery[] = $id;
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery));
                }
            } else {
                $errors = (array)get_post_meta($product_id, '_psb_image_errors', true);
                $errors[] = $url . ' — ' . $id->get_error_message();
                update_post_meta($product_id, '_psb_image_errors', array_slice($errors, -10));
            }
        }

        $index++;
        if ($index < count($urls)) {
            $item['index'] = $index;
            $queue[] = $item;
        }
        self::save_image_queue($queue);
        if ($queue && !self::live_mode_active()) self::schedule_action(self::IMAGE_HOOK, array(), 4);
        elseif (!$queue && !self::live_mode_active()) self::schedule_action(self::DEFERRED_AUDIT_HOOK, array(), 10);
    }

    private static function product_current_brand($id) {
        if(taxonomy_exists('product_brand')){$names=wp_get_object_terms($id,'product_brand',array('fields'=>'names'));if($names&&!is_wp_error($names))return $names[0];}
        return (string)get_post_meta($id,'_psb_brand',true);
    }

    private static function product_category_names($id) {
        $terms=wp_get_object_terms($id,'product_cat',array('fields'=>'names'));return (!$terms||is_wp_error($terms))?'':implode(', ',$terms);
    }

    private static function update_product_prices($product,$regular,$sale='') {
        if(!$product)return;
        if($regular!=='')$product->set_regular_price((string)$regular);
        $product->set_sale_price($sale!==''?(string)$sale:'');
        $product->save();
        if($product->is_type('variable'))foreach($product->get_children() as $vid){$v=wc_get_product($vid);if(!$v)continue;if($regular!=='')$v->set_regular_price((string)$regular);$v->set_sale_price($sale!==''?(string)$sale:'');$v->save();}
    }

    public static function catalog_save() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_catalog_save');
        $rows=isset($_POST['products'])&&is_array($_POST['products'])?wp_unslash($_POST['products']):array();
        foreach($rows as $id=>$row){
            $id=absint($id);$p=wc_get_product($id);if(!$p)continue;
            if(isset($row['name']))$p->set_name(sanitize_text_field($row['name']));
            if(isset($row['description']))$p->set_description(wp_kses_post($row['description']));
            $regular=isset($row['regular'])?wc_format_decimal($row['regular']):$p->get_regular_price();
            $sale=isset($row['sale'])?wc_format_decimal($row['sale']):$p->get_sale_price();
            $p->set_stock_status(!empty($row['instock'])?'instock':'outofstock');
            $p->set_featured(!empty($row['featured']));
            $p->save();self::update_product_prices(wc_get_product($id),$regular,$sale);
            if(!$p->get_image_id()&&!empty($row['image_url']))self::import_images($id,array(esc_url_raw($row['image_url'])),$p->get_name());
            $brand=isset($row['brand'])?sanitize_text_field($row['brand']):'';self::assign_brand($id,$brand);
            if(isset($row['categories'])){$names=array_filter(array_map('trim',explode(',',$row['categories'])));$tids=array();foreach($names as $name){$tid=self::ensure_category(sanitize_text_field($name));if($tid)$tids[]=$tid;}if($tids)wp_set_object_terms($id,$tids,'product_cat',false);}
            update_post_meta($id,'_psb_bestseller',!empty($row['bestseller'])?1:0);
            self::audit_product($id);
        }
        $redirect_view=isset($_POST['view'])?sanitize_key(wp_unslash($_POST['view'])):'all';
        wp_safe_redirect(admin_url('admin.php?page=psb-catalog&saved=1&view='.$redirect_view));exit;
    }

    public static function catalog_audit_now() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_catalog_audit');self::audit_batch();wp_safe_redirect(admin_url('admin.php?page=psb-catalog&audit=1'));exit;
    }

    public static function quality_repair() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_quality_repair');@set_time_limit(120);
        $ids=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>500,'meta_key'=>self::META_SOURCE));
        $checked=0;$quarantined=0;$fixed=0;
        foreach($ids as $id){
            $p=wc_get_product($id);if(!$p)continue;$checked++;
            $source=(string)get_post_meta($id,self::META_SOURCE,true);$url=(string)get_post_meta($id,self::META_SOURCE_URL,true);
            $bad=!self::source_url_is_valid($source,$url)||!self::title_is_valid($p->get_name());
            if($bad){update_post_meta($id,'_psb_quality_quarantined',1);wp_update_post(array('ID'=>$id,'post_status'=>'draft'));$quarantined++;self::audit_product($id);continue;}
            $path=json_decode((string)get_post_meta($id,self::META_CATEGORY_PATH,true),true);$rel=self::relevance_reason_from_text($p->get_name(),self::product_category_names($id),is_array($path)?$path:array());
            if($rel){self::quarantine_irrelevant_product($id,$rel);$quarantined++;self::audit_product($id);continue;}
            if((float)$p->get_price()>0 && $p->get_status()==='draft' && !get_post_meta($id,'_psb_quality_quarantined',true)){wp_update_post(array('ID'=>$id,'post_status'=>'publish'));$fixed++;}
            if($p->is_type('variable') && (float)$p->get_price()<=0){
                $src=(float)get_post_meta($id,self::META_SOURCE_PRICE,true);
                if($src>0){$vals=self::pricing_values_from_raw(self::source_price_raw_from_product($id),$p->get_category_ids());foreach($p->get_children() as $vid){$v=wc_get_product($vid);if(!$v)continue;$v->set_regular_price($vals['regular']);$v->set_sale_price($vals['sale']);$v->save();}WC_Product_Variable::sync($id);$fixed++;}
            }
            self::audit_product($id);
        }
        wp_safe_redirect(admin_url('admin.php?page=psb-catalog&quality_checked='.$checked.'&quality_fixed='.$fixed.'&quality_quarantined='.$quarantined));exit;
    }

    public static function cleanup_irrelevant() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_cleanup_irrelevant');@set_time_limit(180);
        $ids=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>self::META_SOURCE));
        $checked=0;$hidden=0;
        foreach($ids as $id){
            $checked++; $p=wc_get_product($id); if(!$p)continue;
            $path=json_decode((string)get_post_meta($id,self::META_CATEGORY_PATH,true),true);
            $reason=self::relevance_reason_from_text($p->get_name(),self::product_category_names($id),is_array($path)?$path:array());
            if(!$reason)continue;
            if(self::quarantine_irrelevant_product($id,$reason))$hidden++;
            self::audit_product($id);
        }
        wp_safe_redirect(admin_url('admin.php?page=psb-catalog&irrelevant_checked='.$checked.'&irrelevant_hidden='.$hidden.'&view=irrelevant'));exit;
    }

    public static function reprocess_catalog() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_reprocess_catalog');
        @set_time_limit(90);
        $ids=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>500,'meta_key'=>self::META_SOURCE));$done=0;
        foreach($ids as $id){$p=wc_get_product($id);if(!$p)continue;$src=(string)get_post_meta($id,self::META_SOURCE,true);if($src)update_post_meta($id,self::META_SUPPLIER,self::supplier_label($src));$brand=self::infer_brand($p->get_name(),self::product_current_brand($id));self::assign_brand($id,$brand);$current=self::product_category_names($id);$cats=self::classify_categories($p->get_name(),$current);if($cats)$p->set_category_ids(array_values(array_unique(array_merge($p->get_category_ids(),$cats))));$p->save();self::audit_product($id);$done++;}
        wp_safe_redirect(admin_url('admin.php?page=psb-catalog&reprocessed='.$done));exit;
    }

    public static function pricing_save() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_pricing_save');
        $posted=isset($_POST['rules'])&&is_array($_POST['rules'])?wp_unslash($_POST['rules']):array();$rules=array();
        foreach($posted as $id=>$row){$id=absint($id);$markup=isset($row['markup'])?(float)wc_format_decimal($row['markup']):0;$promo=isset($row['promo'])?(float)wc_format_decimal($row['promo']):0;$markup=max(-95,min(500,$markup));$promo=max(0,min(95,$promo));if($markup!=0||$promo!=0)$rules[$id]=array('markup'=>$markup,'promo'=>$promo);}
        update_option(self::PRICING_KEY,$rules,false);wp_safe_redirect(admin_url('admin.php?page=psb-pricing&saved=1'));exit;
    }

    public static function pricing_apply() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');check_admin_referer('psb_pricing_apply');@set_time_limit(120);
        $ids=get_posts(array('post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>self::META_SOURCE_PRICE));$done=0;
        foreach($ids as $id){$source=(float)get_post_meta($id,self::META_SOURCE_PRICE,true);if($source<=0)continue;$p=wc_get_product($id);if(!$p)continue;$vals=self::pricing_values_from_raw(self::source_price_raw_from_product($id),$p->get_category_ids());self::update_product_prices($p,$vals['regular'],$vals['sale']);$done++;}
        wp_safe_redirect(admin_url('admin.php?page=psb-pricing&applied='.$done));exit;
    }

    public static function catalog_page() {
        if(!current_user_can('manage_woocommerce'))return;
        $view=isset($_GET['view'])?sanitize_key($_GET['view']):'all';$page=max(1,isset($_GET['paged'])?absint($_GET['paged']):1);$per=50;$supplier_filter=isset($_GET['supplier'])?sanitize_text_field(wp_unslash($_GET['supplier'])):'';
        $meta_query=array();if($view==='issues')$meta_query[]=array('key'=>'_psb_has_issues','value'=>'1');if($view==='unbranded')$meta_query[]=array('key'=>'_psb_needs_brand','value'=>'1');if($view==='irrelevant')$meta_query[]=array('key'=>self::META_IRRELEVANT,'value'=>'1');if($supplier_filter)$meta_query[]=array('key'=>self::META_SOURCE,'value'=>$supplier_filter,'compare'=>'=');
        $q=new WP_Query(array('post_type'=>'product','post_status'=>array('publish','draft','private'),'posts_per_page'=>$per,'paged'=>$page,'orderby'=>'modified','order'=>'DESC','meta_query'=>$meta_query));
        echo '<div class="wrap"><h1>Каталог & Проблемни продукти</h1><p>Бърза редакция без отваряне на всеки продукт. Име, описание, липсваща снимка, цена, промо, производител, категории, наличност и merchandising се редактират директно от таблицата. Проверява цена, описание, снимка, производител, дубликат на продукт и дублирана основна снимка.</p>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Промените са запазени.</p></div>';if(isset($_GET['audit']))echo '<div class="notice notice-success"><p>Проверката е изпълнена.</p></div>';if(isset($_GET['reprocessed']))echo '<div class="notice notice-success"><p>Автоматично са преработени '.absint($_GET['reprocessed']).' продукта.</p></div>';if(isset($_GET['quality_checked']))echo '<div class="notice notice-success"><p>Quality Guard: проверени '.absint($_GET['quality_checked']).', поправени '.absint($_GET['quality_fixed']??0).', скрити като чернова '.absint($_GET['quality_quarantined']??0).' проблемни продукта.</p></div>';if(isset($_GET['irrelevant_checked']))echo '<div class="notice notice-success"><p>Филтър за релевантност: проверени '.absint($_GET['irrelevant_checked']).', скрити от магазина '.absint($_GET['irrelevant_hidden']??0).' неподходящи продукта.</p></div>';
        $base=admin_url('admin.php?page=psb-catalog');echo '<p class="nav-tab-wrapper"><a class="nav-tab '.($view==='all'?'nav-tab-active':'').'" href="'.esc_url($base.'&view=all').'">Всички</a><a class="nav-tab '.($view==='issues'?'nav-tab-active':'').'" href="'.esc_url($base.'&view=issues').'">Проблемни</a><a class="nav-tab '.($view==='irrelevant'?'nav-tab-active':'').'" href="'.esc_url($base.'&view=irrelevant').'">Неподходящи</a><a class="nav-tab '.($view==='unbranded'?'nav-tab-active':'').'" href="'.esc_url($base.'&view=unbranded').'">Без производител</a></p>';
        echo '<p><label><strong>Доставчик:</strong> <select onchange="window.location=this.value"><option value="'.esc_url($base.'&view='.$view).'">Всички доставчици</option>';foreach(array('mma.bg','szfighters.com','kmsport.bg','leaderfitness.net') as $src){$url=add_query_arg(array('view'=>$view,'supplier'=>$src),$base);echo '<option value="'.esc_url($url).'" '.selected($supplier_filter,$src,false).'>'.esc_html(self::supplier_label($src)).'</option>';}echo '</select></label></p>';
        echo '<div style="display:flex;gap:8px;margin:14px 0"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('psb_catalog_audit');echo '<input type="hidden" name="action" value="psb_catalog_audit"><button class="button">Провери последните 120</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('psb_reprocess_catalog');echo '<input type="hidden" name="action" value="psb_reprocess_catalog"><button class="button button-primary">Авто категории + производители (до 500)</button></form></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:10px 0 16px">';wp_nonce_field('psb_quality_repair');echo '<input type="hidden" name="action" value="psb_quality_repair"><button class="button button-primary">Quality Guard — провери и поправи до 500 продукта</button><span style="margin-left:8px;color:#666">Скрива само продукти с невалиден source URL/име и поправя variable продукти с липсваща цена.</span></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:10px 0 16px">';wp_nonce_field('psb_cleanup_irrelevant');echo '<input type="hidden" name="action" value="psb_cleanup_irrelevant"><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Почисти неподходящите продукти от магазина</button><span style="margin-left:8px;color:#666">Скрива като Draft хранителни добавки, аминокиселини, протеини, BCAA, креатин, продукти за готвене и други очевидно несвързани артикули. Нищо не се изтрива.</span></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('psb_catalog_save');echo '<input type="hidden" name="action" value="psb_catalog_save"><input type="hidden" name="view" value="'.esc_attr($view).'"><div style="overflow:auto"><table class="widefat striped"><thead><tr><th style="min-width:260px">Продукт</th><th style="min-width:150px">Доставчик / източник</th><th style="min-width:300px">Описание</th><th style="min-width:220px">Снимка URL при липса</th><th style="width:100px">Цена</th><th style="width:100px">Промо</th><th style="min-width:150px">Производител</th><th style="min-width:260px">Категории</th><th>Наличен</th><th>Featured</th><th>Най-продаван</th><th style="min-width:200px">Проблеми</th></tr></thead><tbody>';
        foreach($q->posts as $post){$p=wc_get_product($post->ID);if(!$p)continue;$issues=get_post_meta($post->ID,'_psb_issues',true);if(!is_array($issues))$issues=array();echo '<tr><td><input style="width:100%;font-weight:600" name="products['.$post->ID.'][name]" value="'.esc_attr($p->get_name()).'"><small style="display:block;margin-top:5px">#'.$post->ID.' · '.esc_html(get_post_meta($post->ID,self::META_SOURCE,true)).'</small></td><td><strong>'.esc_html(self::product_supplier_label($post->ID)).'</strong><small style="display:block;margin-top:5px">'.(($src_url=get_post_meta($post->ID,self::META_SOURCE_URL,true))?'<a href="'.esc_url($src_url).'" target="_blank" rel="noopener">'.esc_html(get_post_meta($post->ID,self::META_SOURCE,true)).' ↗</a>':esc_html(get_post_meta($post->ID,self::META_SOURCE,true))).'</small></td><td><textarea rows="4" style="width:100%" name="products['.$post->ID.'][description]">'.esc_textarea($p->get_description()).'</textarea></td><td>'.($p->get_image_id()?'<span style="color:#2d7a3d">Има снимка</span>':'<input type="url" style="width:100%" name="products['.$post->ID.'][image_url]" placeholder="https://...">').'</td><td><input style="width:90px" type="number" step="0.01" name="products['.$post->ID.'][regular]" value="'.esc_attr($p->get_regular_price()).'"></td><td><input style="width:90px" type="number" step="0.01" name="products['.$post->ID.'][sale]" value="'.esc_attr($p->get_sale_price()).'"></td><td><input style="width:100%" name="products['.$post->ID.'][brand]" value="'.esc_attr(self::product_current_brand($post->ID)).'"></td><td><input style="width:100%" name="products['.$post->ID.'][categories]" value="'.esc_attr(self::product_category_names($post->ID)).'"></td><td><input type="checkbox" name="products['.$post->ID.'][instock]" value="1" '.checked($p->is_in_stock(),true,false).'></td><td><input type="checkbox" name="products['.$post->ID.'][featured]" value="1" '.checked($p->is_featured(),true,false).'></td><td><input type="checkbox" name="products['.$post->ID.'][bestseller]" value="1" '.checked((bool)get_post_meta($post->ID,'_psb_bestseller',true),true,false).'></td><td>';
            if($issues)foreach($issues as $issue)echo '<span style="display:inline-block;background:#fff1f0;color:#9f1c15;border:1px solid #ffd5d2;border-radius:999px;padding:3px 7px;margin:2px;font-size:11px">'.esc_html($issue).'</span>';else echo '<span style="color:#2d7a3d">OK</span>';echo '</td></tr>';}
        echo '</tbody></table></div><p><button class="button button-primary">Запази видимите продукти</button></p></form>';
        $total=max(1,(int)$q->max_num_pages);if($total>1)echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links(array('base'=>add_query_arg('paged','%#%',$base.'&view='.$view),'format'=>'','current'=>$page,'total'=>$total)).'</div></div>';echo '</div>';
    }

    public static function pricing_page() {
        if(!current_user_can('manage_woocommerce'))return;$rules=get_option(self::PRICING_KEY,array());if(!is_array($rules))$rules=array();$terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'number'=>300,'orderby'=>'name','order'=>'ASC'));if(is_wp_error($terms))$terms=array();
        echo '<div class="wrap"><h1>Цени & Промоции по категория</h1><p><strong>Надценка</strong> променя продажната цена спрямо източника. <strong>Промо %</strong> прави реална WooCommerce sale цена върху текущата редовна цена.</p><div class="notice notice-warning inline"><p>Не използвай полето за надценка само за да показваш изкуствено по-голяма отстъпка. Промоциите трябва да отговарят на реалната ценова история.</p></div>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Правилата са запазени.</p></div>';if(isset($_GET['applied']))echo '<div class="notice notice-success"><p>Цените са приложени върху '.absint($_GET['applied']).' импортнати продукта.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('psb_pricing_save');echo '<input type="hidden" name="action" value="psb_pricing_save"><table class="widefat striped" style="max-width:900px"><thead><tr><th>Категория</th><th>Продукти</th><th>Надценка %</th><th>Реална промоция %</th></tr></thead><tbody>';foreach($terms as $term){$r=$rules[$term->term_id]??array('markup'=>0,'promo'=>0);echo '<tr><td><strong>'.esc_html($term->name).'</strong></td><td>'.intval($term->count).'</td><td><input type="number" step="0.01" name="rules['.$term->term_id.'][markup]" value="'.esc_attr($r['markup']).'"></td><td><input type="number" min="0" max="95" step="0.01" name="rules['.$term->term_id.'][promo]" value="'.esc_attr($r['promo']).'"></td></tr>';}echo '</tbody></table><p><button class="button button-primary">Запази правилата</button></p></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('psb_pricing_apply');echo '<input type="hidden" name="action" value="psb_pricing_apply"><button class="button">Приложи правилата върху всички импортнати продукти</button></form></div>';
    }

    private static function bulk_delete_query_args($scope, $order, $limit) {
        $scope = in_array($scope, array('imported','all'), true) ? $scope : 'imported';
        $order = $order === 'oldest' ? 'ASC' : 'DESC';
        $args = array(
            'post_type'              => 'product',
            'post_status'            => array('publish','draft','pending','private','future'),
            'fields'                 => 'ids',
            'posts_per_page'         => max(1, min(50, (int)$limit)),
            'orderby'                => 'date',
            'order'                  => $order,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        if ($scope === 'imported') {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array('key' => self::META_SOURCE_URL, 'compare' => 'EXISTS'),
                array('key' => self::META_SOURCE, 'compare' => 'EXISTS'),
                array('key' => self::META_SOURCE_KEY, 'compare' => 'EXISTS'),
            );
        }
        return $args;
    }

    public static function bulk_delete_products_ajax() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(array('message'=>'Нямаш права за тази операция.'), 403);
        check_ajax_referer('psb_bulk_delete_products', 'nonce');

        // Never delete while an active importer is creating products in parallel.
        if (self::live_mode_active()) {
            wp_send_json_error(array('message'=>'В момента Product Sync качва продукти. Спри/изчакай синхронизацията и после стартирай bulk изтриването.'), 409);
        }

        $remaining = isset($_POST['remaining']) ? max(0, absint($_POST['remaining'])) : 0;
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'imported';
        $order = isset($_POST['order']) ? sanitize_key(wp_unslash($_POST['order'])) : 'newest';
        $permanent = !empty($_POST['permanent']);
        if (!$remaining) wp_send_json_success(array('deleted'=>0,'remaining'=>0,'done'=>true));

        // Trash can safely be a little larger. Permanent WC deletion is heavier.
        $batch = min($remaining, $permanent ? 10 : 35);
        $ids = get_posts(self::bulk_delete_query_args($scope, $order, $batch));
        if (!$ids) {
            wp_send_json_success(array(
                'deleted'=>0,
                'remaining'=>$remaining,
                'done'=>true,
                'exhausted'=>true,
                'message'=>'Няма повече продукти, които отговарят на избрания обхват.'
            ));
        }

        @set_time_limit(35);
        $deleted = 0;
        $failed = 0;
        $failed_ids = array();
        foreach ($ids as $product_id) {
            $product_id = absint($product_id);
            if (!$product_id) continue;
            try {
                $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
                if ($product) {
                    $result = $product->delete($permanent);
                    if ($result) $deleted++; else { $failed++; $failed_ids[]=$product_id; }
                } else {
                    $result = $permanent ? wp_delete_post($product_id, true) : wp_trash_post($product_id);
                    if ($result) $deleted++; else { $failed++; $failed_ids[]=$product_id; }
                }
            } catch (Throwable $e) {
                $failed++; $failed_ids[]=$product_id;
            }
        }

        // Clear product/cache transients once per batch, not once per item.
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients();
        clean_post_cache(0);

        $next_remaining = max(0, $remaining - $deleted);
        wp_send_json_success(array(
            'deleted'     => $deleted,
            'failed'      => $failed,
            'failed_ids'  => array_slice($failed_ids, 0, 10),
            'remaining'   => $next_remaining,
            'done'        => $next_remaining <= 0,
            'batch_size'  => $batch,
            'permanent'   => $permanent ? 1 : 0,
        ));
    }

    public static function admin_menu() {
        add_submenu_page('woocommerce','Product Sync','Product Sync','manage_woocommerce','product-sync-bridge',array(__CLASS__,'admin_page'));
        add_submenu_page('woocommerce','Каталог & Проблеми','Каталог & Проблеми','manage_woocommerce','psb-catalog',array(__CLASS__,'catalog_page'));
        add_submenu_page('woocommerce','Цени & Промоции','Цени & Промоции','manage_woocommerce','psb-pricing',array(__CLASS__,'pricing_page'));
    }

    public static function admin_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::settings();
        $last = get_option(self::LAST_SYNC_KEY, array());
        $next = wp_next_scheduled(self::CRON_HOOK);
        $meta = get_option(self::QUEUE_META_KEY, array()); if(!is_array($meta)) $meta=array();
        $dstate = get_option(self::DISCOVERY_STATE_KEY, array()); if(!is_array($dstate)) $dstate=array();
        $runstats = self::get_run_stats();
        $display_discovered = max((int)($runstats['discovered']??0),(int)($last['discovered']??0),(int)($meta['discovered']??0),(int)($dstate['discovered']??0));
        $display_known = max((int)($runstats['already_known']??0),(int)($last['already_known']??0),(int)($meta['already_known']??0),(int)($dstate['already_known']??0));
        $display_sources=array(); foreach((array)($runstats['sources']??array()) as $src=>$row)$display_sources[$src]=(int)($row['discovered']??0);
        if(!$display_sources)$display_sources = !empty($last['sources'])&&is_array($last['sources'])?$last['sources']:(!empty($meta['sources'])&&is_array($meta['sources'])?$meta['sources']:(is_array($dstate['sources']??null)?$dstate['sources']:array()));
        $display_run_created = max((int)($runstats['created']??0),(int)($last['run_created']??0),(int)($meta['run_created']??0));
        $display_run_merged = max((int)($runstats['merged']??0),(int)($last['run_merged']??0),(int)($meta['run_merged']??0));
        $display_run_duplicates = max((int)($runstats['duplicates']??0),(int)($last['run_duplicates']??0),(int)($meta['run_duplicates']??0));
        $display_run_ignored = max((int)($runstats['ignored']??0),(int)($last['run_ignored']??0),(int)($meta['run_ignored']??0));
        $display_covered = max((int)($last['covered_urls']??0), $display_known+$display_run_created+$display_run_merged+$display_run_duplicates+$display_run_ignored);
        $display_new_found = max((int)($last['new_found']??0),(int)($meta['new_found']??0),count(self::get_queue()));
        $existing_queue_count = count(self::get_queue());
        $can_resume_existing_queue = $existing_queue_count > 0 && (empty($dstate) || !empty($meta['discovery_complete']));
        ?>
        <div class="wrap">
            <h1>Product Sync Bridge</h1>
            <p><strong>MMA.bg + SZ Fighters + KMSPORT + LeaderFitness → Vercel Robot → WooCommerce</strong></p>
            <p>Първо се сканира всеки доставчик на малки части и се прави пълно първоначално качване на ВСИЧКИ уникални продукти, които липсват в Kickbox.bg. След като опашката стане 0, автоматичните проверки качват само новопоявилите се продукти. Всеки URL от доставчик трябва да бъде отчетен. Хранителни добавки, спортно хранене, продукти за готвене и други очевидно несвързани артикули се маркират като неподходящи и не се публикуват в Kickbox.bg. Точният source URL/source ID се блокира като дубликат; ако същият реален продукт се намери при друг доставчик по SKU или brand+title, той се обединява към съществуващия продукт и новият доставчик се записва, вместо URL-ът да се губи.</p>
            <div class="notice notice-info inline"><p><strong>Важно за MMA.bg:</strong> общият магазин съдържа и огромен каталог хранителни добавки. V23 не ги брои като продукти за Kickbox.bg. Robot V13 чете директно трите релевантни големи секции: „Бойни спортове и MMA“, „Спортни облекла и дрехи“ и „Фитнес аксесоари“, всички техни страници, а после Relevance Guard премахва случайно кръстосано добавени добавки/храни.</p></div>

            <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>Настройките са запазени.</p></div><?php endif; ?>
            <?php if (isset($_GET['started'])): ?><div class="notice notice-success"><p>HYPER качването е стартирано. Оставен таб = максимална скорост; при затваряне watchdog-ът ще продължи по-бавно и безопасно във фонов режим.</p></div><?php endif; ?>
            <?php if (isset($_GET['queue_reset'])): ?><div class="notice notice-success"><p>Опашката е изчистена. Следващото стартиране ще сканира наново всички източници.</p></div><?php endif; ?>
            <?php if (isset($_GET['full_reconcile'])): ?><div class="notice notice-success"><p><strong>Пълната синхронизация е стартирана.</strong> Всеки продукт от четирите сайта ще бъде проверен отново.</p></div><?php endif; ?>

            <?php if (isset($_GET['live_done'])): ?><div class="notice notice-success"><p><strong>Пълната синхронизация приключи.</strong> Прегледай статуса по-долу.</p></div><?php endif; ?>
            <section id="psb-bulk-delete" style="max-width:900px;margin:20px 0;padding:18px 20px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;border-radius:4px">
                <h2 style="margin:0 0 8px">Bulk изтриване на продукти</h2>
                <p style="margin-top:0">Въведи точния брой, който искаш да махнеш. Операцията върви автоматично на малки бързи групи, за да не претоварва хостинга.</p>
                <div style="display:grid;grid-template-columns:160px minmax(200px,1fr) minmax(180px,1fr);gap:10px;align-items:end;max-width:760px">
                    <label><strong>Брой продукти</strong><br><input id="psb-delete-count" type="number" min="1" step="1" value="100" style="width:100%;margin-top:5px"></label>
                    <label><strong>Кои продукти</strong><br><select id="psb-delete-scope" style="width:100%;margin-top:5px"><option value="imported">Само импортнати от Product Sync</option><option value="all">ВСИЧКИ WooCommerce продукти</option></select></label>
                    <label><strong>Ред</strong><br><select id="psb-delete-order" style="width:100%;margin-top:5px"><option value="newest">Най-новите първо</option><option value="oldest">Най-старите първо</option></select></label>
                </div>
                <label style="display:block;margin:12px 0"><input id="psb-delete-permanent" type="checkbox" value="1"> <strong>Изтрий окончателно</strong> вместо да ги преместиш в кошчето</label>
                <p><button type="button" id="psb-delete-start" class="button button-secondary" style="border-color:#d63638;color:#b32d2e">ИЗТРИЙ ВЪВЕДЕНИЯ БРОЙ</button></p>
                <div id="psb-delete-progress-wrap" style="display:none;max-width:760px">
                    <div style="height:16px;background:#eef0f2;border-radius:999px;overflow:hidden"><div id="psb-delete-progress" style="height:100%;width:0;background:#d63638;transition:width .2s"></div></div>
                    <p id="psb-delete-status" style="font-weight:700"></p>
                </div>
            </section>
            <script>
            jQuery(function($){
                const nonce=<?php echo wp_json_encode(wp_create_nonce('psb_bulk_delete_products')); ?>;
                const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                const $btn=$('#psb-delete-start'),$wrap=$('#psb-delete-progress-wrap'),$bar=$('#psb-delete-progress'),$status=$('#psb-delete-status');
                let running=false;
                function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
                function textErr(e){
                    try{return e?.responseJSON?.data?.message||e?.responseJSON?.message||e?.message||'Временна грешка.';}catch(_){return 'Временна грешка.';}
                }
                $btn.on('click',async function(){
                    if(running) return;
                    const requested=Math.max(1,parseInt($('#psb-delete-count').val()||'0',10));
                    const scope=$('#psb-delete-scope').val();
                    const order=$('#psb-delete-order').val();
                    const permanent=$('#psb-delete-permanent').is(':checked')?1:0;
                    const warning=permanent
                        ? 'Ще изтриеш ОКОНЧАТЕЛНО до '+requested+' продукта. Това не може да се върне. Продължаваш ли?'
                        : 'Ще преместиш до '+requested+' продукта в кошчето. Продължаваш ли?';
                    if(!window.confirm(warning)) return;
                    running=true;$btn.prop('disabled',true);$wrap.show();$bar.css('width','0%');
                    let remaining=requested,deletedTotal=0,failedTotal=0,attempt=0;
                    while(remaining>0){
                        try{
                            const r=await $.ajax({url:ajax,method:'POST',timeout:70000,data:{action:'psb_bulk_delete_products',nonce,remaining,scope,order,permanent}});
                            if(!r.success) throw new Error(r?.data?.message||'Грешка');
                            const d=r.data||{};
                            deletedTotal+=Number(d.deleted||0);failedTotal+=Number(d.failed||0);remaining=Number(d.remaining||0);
                            const pct=Math.min(100,Math.round((deletedTotal/requested)*100));$bar.css('width',pct+'%');
                            $status.text('Изтрити: '+deletedTotal+' от '+requested+' · остават: '+remaining+(failedTotal?' · неуспешни: '+failedTotal:''));
                            attempt=0;
                            if(d.done||d.exhausted) break;
                            await sleep(permanent?350:120);
                        }catch(e){
                            attempt++;
                            if(attempt>=6){$status.text('Спряно: '+textErr(e)+' Изтрити до момента: '+deletedTotal+'.');break;}
                            const wait=Math.min(10000,1000*attempt*attempt);
                            $status.text('Хостингът е натоварен. Чакам '+Math.round(wait/1000)+' сек. и продължавам…');
                            await sleep(wait);
                        }
                    }
                    if(remaining<=0){$bar.css('width','100%');$status.text('Готово — изтрити '+deletedTotal+' продукта.'+(failedTotal?' Неуспешни: '+failedTotal+'.':''));}
                    else if(deletedTotal<requested && $status.text().indexOf('Спряно:')!==0){$status.text('Готово — намерени и изтрити '+deletedTotal+' продукта от заявени '+requested+'. Няма повече продукти в избрания обхват.');}
                    running=false;$btn.prop('disabled',false);
                });
            });
            </script>
            <?php if ($existing_queue_count > 0 && !self::live_mode_active() && !isset($_GET['live_full'])): ?>
                <p><a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=product-sync-bridge&live_full=1&resume_queue=1')); ?>">ПРОДЪЛЖИ ТЕКУЩИТЕ <?php echo (int)$existing_queue_count; ?> ПРОДУКТА — HYPER</a></p>
                <p><strong>Не сканира отначало.</strong> Продължава директно текущата опашка. Един worker качва продуктите, снимките са отделен финален етап, а watchdog пази процеса при затворен таб.</p>
            <?php endif; ?>
            <?php $psb_show_live = isset($_GET['live_full']) || self::live_mode_active(); if ($psb_show_live): ?>
                <div id="psb-live-sync" style="max-width:900px;background:#fff;border:2px solid #2271b1;padding:18px;margin:16px 0">
                    <h2 style="margin-top:0">Пълно качване — работи директно сега</h2>
                    <p id="psb-live-text"><strong>1/3:</strong> Стартирам сканирането…</p>
                    <div style="height:18px;background:#eef0f2;border-radius:20px;overflow:hidden"><div id="psb-live-bar" style="height:100%;width:2%;background:#2271b1;transition:width .25s"></div></div>
                    <p id="psb-live-count" style="font-weight:700">Можеш да оставиш таба отворен за максимална скорост. Ако го затвориш, watchdog-ът ще продължи безопасно във фонов режим.</p>
                    <pre id="psb-live-log" style="max-height:180px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;padding:10px"></pre>
                    <button type="button" id="psb-live-resume" class="button button-primary" style="display:none;margin-top:10px">Продължи от същото място</button>
                </div>
                <script>
                jQuery(function($){
                    const nonce=<?php echo wp_json_encode(wp_create_nonce('psb_live_sync')); ?>;
                    const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    const $text=$('#psb-live-text'),$bar=$('#psb-live-bar'),$count=$('#psb-live-count'),$log=$('#psb-live-log'),$resume=$('#psb-live-resume');
                    let total=0,initialQueue=0,steps=0,running=false;
                    let liveBatch=24,goodStreak=0; let imageBatch=1,imageGoodStreak=0;
                    const stagePlan=['mma.bg','szfighters.com','kmsport.bg','leaderfitness.net'];
                    let stageIndex=Math.max(0,stagePlan.indexOf(<?php echo wp_json_encode((string)get_option(self::ACTIVE_SOURCE_KEY,'mma.bg')); ?>));
                    let stageNeedsReset=<?php echo (!empty($_GET['full_reconcile']) && empty($dstate) && !self::get_queue()) ? 'true' : 'false'; ?>;
                    let resumeExistingQueue=<?php echo $can_resume_existing_queue ? 'true' : 'false'; ?>;
                    let existingQueueCount=<?php echo (int)$existing_queue_count; ?>;
                    function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
                    function setStatus(id,value){ const el=document.getElementById(id); if(el) el.textContent=String(value); }
                    function msg(s){$log.text((s||'')+'\n'+$log.text()).scrollTop(0);}
                    function errText(e){
                        try{
                            const j=e&&e.responseJSON?e.responseJSON:null;
                            const d=j&&j.data?j.data:null;
                            if(d&&Array.isArray(d.messages)&&d.messages.length) return d.messages.join('\n');
                            if(d&&typeof d.message==='string') return d.message;
                            if(j&&typeof j.message==='string') return j.message;
                            if(e&&typeof e.message==='string') return e.message;
                            if(e&&typeof e.responseText==='string'&&e.responseText.trim()) return e.responseText.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,500);
                            if(typeof e==='string') return e;
                            return 'Неизвестна временна грешка при AJAX заявката.';
                        }catch(_){ return 'Неизвестна временна грешка при AJAX заявката.'; }
                    }
                    async function call(action,extra={}){
                        let lastErr=null;
                        const isScan=action==='psb_live_seed';
                        const maxAttempts=isScan?20:(action==='psb_live_step'?1:4);
                        for(let attempt=1;attempt<=maxAttempts;attempt++){
                            try{
                                return await $.ajax({url:ajax,method:'POST',timeout:isScan?70000:150000,data:Object.assign({action,nonce},extra)});
                            }catch(e){
                                lastErr=e;
                                const status=Number(e&&e.status||0);
                                if(status===401||status===403) throw e;
                                const delay=isScan
                                  ? Math.min(30000, attempt<=5 ? 1500*attempt : 10000 + (attempt-5)*2000)
                                  : Math.min(12000,1000*attempt*attempt);
                                msg('Временна грешка ('+attempt+'/'+maxAttempts+'): '+errText(e)+' — изчаквам '+Math.round(delay/1000)+' сек. и продължавам от същото място.');
                                $text.html('<strong>Пауза:</strong> хостингът е натоварен. Продължавам автоматично…');
                                await sleep(delay);
                            }
                        }
                        throw lastErr||new Error('AJAX заявката не успя след многократни опити.');
                    }
                    function update(d){
                        total=Number(d.discovered||total||0); const remain=Number(d.queue_remaining||0); if(!initialQueue) initialQueue=Math.max(remain,1);
                        const done=Math.max(0,initialQueue-remain); const pct=Math.max(3,Math.min(100,Math.round(done/initialQueue*100)));
                        const covered=Number(d.covered_urls||0), created=Number(d.run_created||0), merged=Number(d.run_merged||0), ignored=Number(d.run_ignored||0), unresolved=Number(d.unresolved_urls||0);
                        $bar.css('width',pct+'%'); $count.text('Намерени: '+total+' · Покрити: '+covered+' · Остават: '+remain+' · Качени: '+created+' · Обединени: '+merged+' · Неподходящи: '+ignored);
                        setStatus('psb-stat-discovered',total);
                        setStatus('psb-stat-known',Number(d.already_known||0));
                        setStatus('psb-stat-covered',covered+' / '+total);
                        setStatus('psb-stat-unresolved',unresolved);
                        setStatus('psb-stat-created',created);
                        setStatus('psb-stat-merged',merged);
                        setStatus('psb-stat-ignored',ignored);
                        setStatus('psb-stat-queue',remain);
                        if(d.stage_pending!==undefined) setStatus('psb-stat-stage',Number(d.stage_pending||0));
                        setStatus('psb-stat-images',Number(d.image_queue_remaining||0));
                        if(Number(d.woo_total||0)>0) setStatus('psb-stat-woo',Number(d.woo_total));
                        if(d.sources&&typeof d.sources==='object'){ const parts=[]; Object.keys(d.sources).forEach(k=>parts.push(k+': '+Number(d.sources[k]||0))); setStatus('psb-stat-sources',parts.join(' | ')); }
                        if(Array.isArray(d.messages)&&d.messages.length) msg(d.messages.slice(0,3).join('\n'));
                    }
                    async function run(){
                        if(running) return;
                        running=true; $resume.hide();
                        try{
                            while(stageIndex<stagePlan.length){
                                const stageSource=stagePlan[stageIndex];
                                let remain=0;

                                // V23 continues the already-built queue immediately. No rescan,
                                // no loss of the 2,000+ products already waiting.
                                if(resumeExistingQueue){
                                    remain=existingQueueCount;
                                    initialQueue=Math.max(remain,1);
                                    $text.html('<strong>Етап '+(stageIndex+1)+'/4 — '+stageSource+':</strong> продължавам директно текущата опашка · HYPER '+liveBatch+' продукта/заявка');
                                    $count.text('Остават '+remain+' продукта. Снимките са спрени временно, за да не товарят хостинга.');
                                    resumeExistingQueue=false;
                                } else {
                                    let seed=null,firstSeed=true,seedGuard=0;
                                    $text.html('<strong>Етап '+(stageIndex+1)+'/4:</strong> сканирам '+stageSource+'…');
                                    initialQueue=0;
                                    while(seedGuard<5000){
                                        seed=await call('psb_live_seed',{reset:(firstSeed&&stageNeedsReset)?1:0,source:stageSource});
                                        firstSeed=false; stageNeedsReset=false;
                                        if(!seed.success) throw new Error(errText({responseJSON:seed}));
                                        update(seed.data);
                                        const src=String(seed.data.discovery_source||stageSource);
                                        const cur=String(seed.data.discovery_cursor||'0');
                                        const curLabel=String(seed.data.discovery_cursor_label||cur);
                                        const units=Number(seed.data.discovery_total_units||0);
                                        const found=Number(seed.data.discovered||0);
                                        $text.html('<strong>Етап '+(stageIndex+1)+'/4 — '+src+':</strong> сканирам · '+curLabel+(src==='mma.bg'?'':(units?' от '+units:'')));
                                        $count.text('Намерени '+found+' уникални URL-а · за качване/проверка: '+Number(seed.data.queue_remaining||0));
                                        const scanPct=Math.max(2,Math.min(22,Math.round(units?Math.min(1,Number(cur||0)/Math.max(1,units))*22:6)));
                                        $bar.css('width',scanPct+'%');
                                        if(seed.data.discovery_done) break;
                                        seedGuard++; await sleep(120);
                                    }
                                    if(!seed||!seed.data.discovery_done) throw new Error('Сканирането на '+stageSource+' не успя да завърши.');
                                    remain=Number(seed.data.queue_remaining||0);
                                    initialQueue=Math.max(remain,1);
                                }

                                // Products first. One HTTP request at a time. No image worker, no cron worker.
                                $text.html('<strong>Етап '+(stageIndex+1)+'/4 — '+stageSource+':</strong> HYPER · '+liveBatch+' продукта/заявка');
                                let consecutiveErrors=0;
                                while(remain>0){
                                    const startedAt=Date.now();
                                    try{
                                        const r=await call('psb_live_step',{batch:liveBatch});
                                        if(!r.success) throw new Error(errText({responseJSON:r}));
                                        update(r.data);
                                        remain=Number(r.data.queue_remaining||0);
                                        steps++;
                                        const secs=(Date.now()-startedAt)/1000;
                                        consecutiveErrors=0;
                                        if(Number(r.data.busy||0)){
                                            await sleep(1200);
                                            continue;
                                        }
                                        const serverRecommended=Number(r.data.recommended_batch||0);
                                        if(serverRecommended>0){
                                            const next=Math.max(2,Math.min(30,serverRecommended));
                                            if(next!==liveBatch) msg('HYPER настройва групата: '+liveBatch+' → '+next+'.');
                                            liveBatch=next;
                                        }else if(secs<5){
                                            goodStreak++;
                                            if(goodStreak>=2 && liveBatch<30){ liveBatch=Math.min(30,liveBatch+2); goodStreak=0; }
                                        }else if(secs>12){
                                            goodStreak=0;
                                            liveBatch=Math.max(2,liveBatch-2);
                                        }else goodStreak=0;
                                        const doneNow=Number(r.data.processed_in_request||0);
                                        const guard=Number(r.data.time_budget_hit||0)?' · server guard':'';
                                        $text.html('<strong>Етап '+(stageIndex+1)+'/4 — '+stageSource+':</strong> HYPER '+liveBatch+' · обработени '+doneNow+' · остават '+remain+' · '+secs.toFixed(1)+' сек.'+guard);
                                        if(remain>0) await sleep(secs<4?120:(secs<9?300:900));
                                    }catch(e){
                                        consecutiveErrors++;
                                        goodStreak=0;
                                        liveBatch=Math.max(2,Math.floor(liveBatch/2));
                                        const pause=Math.min(25000,4000+consecutiveErrors*3500);
                                        msg('Хостингът върна грешка → намалявам групата на '+liveBatch+' и чакам '+Math.round(pause/1000)+' сек. Нищо не се губи.');
                                        $text.html('<strong>Автоматична пауза:</strong> намалих HYPER на '+liveBatch+'. Продължавам сам след '+Math.round(pause/1000)+' сек.');
                                        await sleep(pause);
                                    }
                                }

                                msg('ГОТОВИ ПРОДУКТИ: '+stageSource+'. Снимките остават за самия край, за да пазим хостинга.');
                                stageIndex++;
                                existingQueueCount=0;
                                if(stageIndex<stagePlan.length){stageNeedsReset=true;await sleep(900);}
                            }

                            // Only after all suppliers are imported do we download images, one job at a time.
                            $text.html('<strong>Финал:</strong> всички продукти са създадени. Довършвам снимките адаптивно, без да товаря хостинга…');
                            let imageRemaining=Number(document.getElementById('psb-stat-images')?.textContent||1),imageGuard=0,imageErrors=0;
                            if(!imageRemaining) imageRemaining=1;
                            while(imageRemaining>0 && imageGuard<30000){
                                try{
                                    const ir=await call('psb_live_image_step',{batch:imageBatch});
                                    if(!ir.success) throw new Error(errText({responseJSON:ir}));
                                    imageRemaining=Number(ir.data.remaining||0);
                                    const imageSecs=Number(ir.data.duration_seconds||0);
                                    const imageDone=Number(ir.data.processed||0);
                                    if(imageSecs>0 && imageSecs<5 && imageDone>=imageBatch){ imageGoodStreak++; if(imageGoodStreak>=2){ imageBatch=2; imageGoodStreak=0; } }
                                    else if(imageSecs>10){ imageBatch=1; imageGoodStreak=0; }
                                    setStatus('psb-stat-images',imageRemaining);
                                    $count.text('Всички продукти са качени · снимки остават: '+imageRemaining+' · batch '+imageBatch);
                                    imageErrors=0; imageGuard++;
                                    if(imageRemaining>0) await sleep(imageSecs<4?250:700);
                                }catch(e){
                                    imageErrors++; imageBatch=1; imageGoodStreak=0;
                                    const pause=Math.min(25000,4000+imageErrors*3000);
                                    msg('Временна грешка при снимка. Чакам '+Math.round(pause/1000)+' сек. и продължавам.');
                                    await sleep(pause);
                                }
                            }
                            $bar.css('width','100%');
                            $text.html('<strong>Готово.</strong> Четирите доставчика са обработени с един HYPER worker, без паралелно натоварване на хостинга.');
                            $count.text('Пълната синхронизация приключи. Неподходящите продукти не са публикувани.');
                            try{await call('psb_live_abort');}catch(_e){}
                            await sleep(700);
                            window.location=<?php echo wp_json_encode(admin_url('admin.php?page=product-sync-bridge&live_done=1')); ?>;
                        }catch(e){
                            const reason=errText(e);
                            running=false;
                            $text.html('<strong style="color:#b32d2e">Временно спрян:</strong> '+reason);
                            $count.text('Нищо не е загубено. Продължаваме от същия доставчик, cursor и опашка.');
                            msg(reason+'\nСъстоянието е запазено. Натисни „Продължи от същото място“.');
                            $resume.show();
                        }
                    }
                    $resume.on('click',function(){ run(); });
                    run();
                });
                </script>
            <?php endif; ?>

            <div style="max-width:900px;background:#f6fbff;border:1px solid #9ec5e5;border-left:4px solid #2271b1;padding:16px;margin:16px 0">
                <strong>V26: VPS ENGINE — crawler-ът е на VPS, WordPress само приема и записва готовите продукти</strong>
                <p style="margin-bottom:0">Редът е MMA.bg → SZ Fighters → KMSPORT → LeaderFitness. HYPER BUFFER взима до 120 продуктови страници наведнъж във Vercel и ги пази в локален буфер. Единствен WooCommerce worker ги записва адаптивно, без паралелно претоварване. Ако табът се затвори или браузърът спре, watchdog-ът възстановява текущата опашка с безопасен background worker. Relevance Guard остава активен и не публикува неподходящи продукти.</p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;padding:20px;border:1px solid #dcdcde">
                <?php wp_nonce_field('psb_save_settings'); ?>
                <input type="hidden" name="action" value="psb_save_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="robot_url">Robot / VPS URL</label></th>
                        <td><input name="robot_url" id="robot_url" type="url" class="regular-text" value="<?php echo esc_attr($s['robot_url']); ?>" placeholder="http://127.0.0.1:8787"></td>
                    </tr>
                    <tr>
                        <th><label for="sync_secret">SYNC_SECRET</label></th>
                        <td><input name="sync_secret" id="sync_secret" type="text" class="regular-text" value="<?php echo esc_attr($s['sync_secret']); ?>"><p class="description">За VPS режима сложи силна тайна и същата стойност в /opt/kickbox-sync/.env като SYNC_SECRET.</p></td>
                    </tr>
                    <tr>
                        <th>Автоматично сканиране</th>
                        <td><label><input name="enabled" type="checkbox" value="1" <?php checked(!empty($s['enabled'])); ?>> Включено</label></td>
                    </tr>
                    <tr>
                        <th><label for="interval">Проверка</label></th>
                        <td><select name="interval" id="interval">
                            <option value="psb_5_minutes" <?php selected($s['interval'], 'psb_5_minutes'); ?>>На всеки 5 минути</option>
                            <option value="psb_30_minutes" <?php selected($s['interval'], 'psb_30_minutes'); ?>>На всеки 30 минути</option>
                            <option value="hourly" <?php selected($s['interval'], 'hourly'); ?>>На всеки 1 час</option>
                            <option value="twicedaily" <?php selected($s['interval'], 'twicedaily'); ?>>2 пъти дневно</option>
                            <option value="daily" <?php selected($s['interval'], 'daily'); ?>>1 път дневно</option>
                        </select></td>
                    </tr>
                    <tr>
                        <th><label for="batch_size">Продукти на една стъпка</label></th>
                        <td><input name="batch_size" id="batch_size" type="number" min="1" max="30" value="<?php echo esc_attr($s['batch_size']); ?>"><p class="description">HYPER MATERIALIZE започва с 24 продукта на заявка, може да стигне до 30 и сам намалява при бавен отговор, висока памет или 503. MMA.bg се сканира директно през 3-те големи релевантни секции по до 250 продукта на страница; Robot V13 обработва по няколко страници на заявка. Снимките се отлагат до края, за да не товарят хостинга докато се създават продукти.</p></td>
                    </tr>
                    <tr>
                        <th><label for="product_status">Нов продукт</label></th>
                        <td><select name="product_status" id="product_status">
                            <option value="publish" <?php selected($s['product_status'], 'publish'); ?>>Публикувай директно</option>
                            <option value="draft" <?php selected($s['product_status'], 'draft'); ?>>Запази като чернова</option>
                        </select></td>
                    </tr>
                </table>
                <?php submit_button('Запази настройките'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                <?php wp_nonce_field('psb_run_sync'); ?>
                <input type="hidden" name="action" value="psb_run_sync">
                <?php submit_button('КАЧИ ВСИЧКО ПО ЕТАПИ — TURBO', 'primary'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <?php wp_nonce_field('psb_reset_queue'); ?>
                <input type="hidden" name="action" value="psb_reset_queue">
                <?php submit_button('Сканирай всички източници наново', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;padding:14px;background:#fff;border-left:4px solid #d63638;max-width:870px">
                <?php wp_nonce_field('psb_force_reconcile'); ?>
                <input type="hidden" name="action" value="psb_force_reconcile">
                <p style="margin-top:0"><strong>Пълна синхронизация на целия каталог</strong><br>Работи по етапи: MMA.bg → SZ Fighters → KMSPORT → LeaderFitness. Всеки източник се сканира, качва и довършва преди следващия. При MMA.bg се обхождат всички страници в релевантните категории за бойни спортове, спортно облекло и тренировки; хранителните добавки и несвързаните артикули не се публикуват.</p>
                <?php submit_button('КАЧИ ВСИЧКИ УНИКАЛНИ ПРОДУКТИ', 'primary', 'submit', false); ?>
            </form>

            <h2>Статус</h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td><strong>Автоматично</strong></td><td><?php echo !empty($s['enabled']) ? 'Включено' : 'Изключено'; ?></td></tr>
                    <tr><td><strong>Следваща WP-Cron проверка</strong></td><td><?php echo $next ? esc_html(wp_date('d.m.Y H:i:s', $next)) : 'Няма насрочена'; ?></td></tr>
                    <tr><td><strong>Последна проверка</strong></td><td><?php echo !empty($last['time']) ? esc_html($last['time']) : 'Още няма'; ?></td></tr>
                    <tr><td><strong>Намерени URL-и</strong></td><td><span id="psb-stat-discovered"><?php echo (int)$display_discovered; ?></span></td></tr>
                    <?php if (!empty($display_sources) && is_array($display_sources)): ?>
                    <tr><td><strong>По източници</strong></td><td id="psb-stat-sources"><?php $parts=array(); foreach($display_sources as $src=>$cnt){$parts[]=esc_html($src).': '.(int)$cnt;} echo implode(' | ', $parts); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($runstats['catalog_estimates']['mma.bg'])): ?>
                    <tr><td><strong>MMA.bg позиции в релевантните 3 секции</strong></td><td><strong><?php echo (int)$runstats['catalog_estimates']['mma.bg']; ?></strong> преди премахване на повторения и неподходящи артикули</td></tr>
                    <?php endif; ?>
                    <tr><td><strong>Вече съществуват</strong></td><td><span id="psb-stat-known"><?php echo (int)$display_known; ?></span></td></tr>
                    <tr><td><strong>Покрити source URL-и</strong></td><td><span id="psb-stat-covered"><strong><?php echo (int)$display_covered; ?></strong> / <?php echo (int)$display_discovered; ?></span></td></tr>
                    <tr><td><strong>Неразрешени / недостъпни URL-и</strong></td><td><span id="psb-stat-unresolved"><?php echo isset($last['unresolved_urls']) ? (int)$last['unresolved_urls'] : count(self::get_failed()); ?></span></td></tr>
                    <tr><td><strong>Качени общо в текущата пълна синхронизация</strong></td><td><span id="psb-stat-created"><?php echo (int)$display_run_created; ?></span></td></tr>
                    <tr><td><strong>Обединени дубликати общо</strong></td><td><span id="psb-stat-merged"><?php echo (int)$display_run_merged; ?></span></td></tr>
                    <tr><td><strong>Пропуснати като неподходящи за Kickbox.bg</strong></td><td><span id="psb-stat-ignored"><?php echo (int)$display_run_ignored; ?></span></td></tr>
                    <tr><td><strong>Общо продукти в WooCommerce</strong></td><td><span id="psb-stat-woo"><?php echo (int) self::count_imported_products(); ?></span></td></tr>
                    <tr><td><strong>Липсващи за качване при старта</strong></td><td><?php echo (int)$display_new_found; ?></td></tr>
                    <tr><td><strong>Остават продукти в опашката</strong></td><td><span id="psb-stat-queue"><?php echo count(self::get_queue()) + self::stage_pending_count(); ?></span></td></tr>
                    <tr><td><strong>HYPER buffer (вече изтеглени от Vercel)</strong></td><td><span id="psb-stat-stage"><?php echo self::stage_pending_count(); ?></span></td></tr>
                    <tr><td><strong>Продукти със снимки в опашката</strong></td><td><span id="psb-stat-images"><?php echo count(self::get_image_queue()); ?></span></td></tr>
                    <tr><td><strong>Качени в тази стъпка</strong></td><td><?php echo isset($last['created']) ? (int) $last['created'] : 0; ?></td></tr>
                    <tr><td><strong>Качени без цена / като изчерпани</strong></td><td><?php echo isset($last['created_no_price']) ? (int) $last['created_no_price'] : 0; ?></td></tr>
                    <tr><td><strong>Обединени с вече съществуващ продукт</strong></td><td><?php echo isset($last['merged']) ? (int) $last['merged'] : 0; ?></td></tr>
                    <tr><td><strong>Точни дубликати / вече покрити URL-и</strong></td><td><?php echo isset($last['duplicates']) ? (int) $last['duplicates'] : 0; ?></td></tr>
                    <tr><td><strong>Грешки</strong></td><td><?php echo isset($last['errors']) ? (int) $last['errors'] : 0; ?></td></tr>
                </tbody>
            </table>

            <?php if (!empty($last['messages'])): ?>
                <h3>Последни съобщения</h3>
                <pre style="max-width:900px;white-space:pre-wrap;background:#fff;padding:15px;border:1px solid #dcdcde"><?php echo esc_html(implode("\n", array_slice($last['messages'], 0, 20))); ?></pre>
            <?php endif; ?>

            <p><em>При пълно качване страницата използва малки AJAX заявки и не зависи от WP-Cron/Action Scheduler. MMA.bg се сканира през трите големи релевантни секции (бойни спортове, спортно облекло и фитнес аксесоари) по няколко страници наведнъж, а останалите източници се четат на големи URL групи; HYPER използва адаптивни малки групи и не пуска фонови workers паралелно, а снимките се довършват чак накрая адаптивно по 1–2 задачи на заявка. Ако табът бъде затворен, watchdog-ът възстановява процеса с един безопасен background worker. При временен 503 процесът изчаква и продължава от същото място. Фоновият scheduler остава само като резервен механизъм. Пълната синхронизация следи покритието на source URL-ите, а не просто общия брой WooCommerce продукти. Всеки URL без продукт/връзка пак влиза в опашката. Повторение се обединява само при надеждно съвпадение по source ID, SKU или марка + точно име. Продуктите с цена 0,00 се качват като изчерпани без цена. Очевидно неподходящите продукти се отчитат като покрити, но не се създават/публикуват.</em></p>
        </div>
        <?php
    }

    /** VPS PUSH API + persistent WP-CLI workers. */
    public static function vps_rest_authorized($request) {
        $settings = self::settings();
        $secret = trim((string)($settings['sync_secret'] ?? ''));
        $given = trim((string)$request->get_header('x-sync-key'));
        if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
            return new WP_Error('psb_forbidden', 'Invalid SYNC_SECRET.', array('status'=>401));
        }
        return true;
    }

    public static function register_vps_routes() {
        register_rest_route(self::VPS_REST_NAMESPACE, '/vps/stage', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'vps_rest_stage'),
            'permission_callback' => array(__CLASS__, 'vps_rest_authorized'),
        ));
        register_rest_route(self::VPS_REST_NAMESPACE, '/vps/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'vps_rest_status'),
            'permission_callback' => array(__CLASS__, 'vps_rest_authorized'),
        ));
    }

    public static function vps_rest_stage($request) {
        $payload = $request->get_json_params();
        $products = is_array($payload) && !empty($payload['products']) && is_array($payload['products']) ? $payload['products'] : array();
        if (count($products) > 500) $products = array_slice($products, 0, 500);
        $added = self::stage_products($products);
        return rest_ensure_response(array(
            'ok'=>true,
            'accepted'=>(int)$added,
            'stage_pending'=>self::stage_pending_count(),
            'image_pending'=>count(self::get_image_queue()),
        ));
    }

    public static function vps_rest_status($request = null) {
        $counts = wp_count_posts('product');
        return rest_ensure_response(array(
            'ok'=>true,
            'stage_pending'=>self::stage_pending_count(),
            'url_queue'=>count(self::get_queue()),
            'image_pending'=>count(self::get_image_queue()),
            'products_publish'=>isset($counts->publish)?(int)$counts->publish:0,
            'products_draft'=>isset($counts->draft)?(int)$counts->draft:0,
            'last_sync'=>get_option(self::LAST_SYNC_KEY,array()),
        ));
    }

    private static function claim_stage_rows_cli($limit, $worker_token) {
        self::ensure_stage_table();
        global $wpdb;
        $table = self::stage_table_name();
        $limit = max(1, min(self::CLI_MAX_BATCH, (int)$limit));
        $worker_token = sanitize_key($worker_token);
        if ($worker_token === '') $worker_token = 'worker_'.wp_generate_password(10,false,false);
        $claim_lock = 'psb_stage_claim_v26';
        $got = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $claim_lock));
        if ($got !== 1) return array();
        try {
            $stale = wp_date('Y-m-d H:i:s', current_time('timestamp') - 600);
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET worker_token='', locked_at=NULL WHERE worker_token<>'' AND locked_at IS NOT NULL AND locked_at < %s", $stale));
            $ids = (array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE worker_token='' ORDER BY id ASC LIMIT %d", $limit));
            $ids = array_values(array_filter(array_map('absint',$ids)));
            if (!$ids) return array();
            $now = current_time('mysql');
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET worker_token=%s, locked_at=%s WHERE id IN (".implode(',',$ids).")", $worker_token, $now));
            return (array)$wpdb->get_results($wpdb->prepare("SELECT id,payload,attempts FROM {$table} WHERE worker_token=%s ORDER BY id ASC", $worker_token), ARRAY_A);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $claim_lock));
        }
    }

    private static function release_stage_claim_cli($id) {
        $id = absint($id); if (!$id) return;
        global $wpdb; $table=self::stage_table_name();
        $wpdb->update($table,array('worker_token'=>'','locked_at'=>null,'updated_at'=>current_time('mysql')),array('id'=>$id),array('%s','%s','%s'),array('%d'));
    }

    private static function cli_product_lock_key(array $raw) {
        $title = sanitize_text_field((string)($raw['title'] ?? ''));
        $brand = sanitize_text_field((string)($raw['brand'] ?? ''));
        $fp = ($title !== '') ? self::fingerprint($brand,$title) : '';
        if ($fp === '') $fp = sanitize_text_field((string)($raw['source'] ?? '')).'|'.self::normalize_source_url((string)($raw['sourceUrl'] ?? ''));
        return 'psb_product_'.substr(hash('sha256',$fp),0,36);
    }

    public static function cli_drain_stage($batch = 80, $seconds = 50, $worker = '') {
        if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) throw new Exception('WooCommerce не е активен.');
        $batch=max(1,min(self::CLI_MAX_BATCH,(int)$batch));
        $seconds=max(5,min(3600,(int)$seconds));
        $worker=sanitize_key($worker ?: ('w'.getmypid()));
        $started=microtime(true); $deadline=$started+$seconds;
        $summary=array('worker'=>$worker,'created'=>0,'created_no_price'=>0,'merged'=>0,'duplicates'=>0,'ignored'=>0,'errors'=>0,'processed'=>0,'loops'=>0);
        global $wpdb;

        while (microtime(true) < $deadline) {
            $rows=self::claim_stage_rows_cli($batch,$worker);
            if (!$rows) break;
            $summary['loops']++;
            $done_ids=array();
            self::begin_bulk_mode();
            wp_defer_term_counting(true);
            try {
                foreach ($rows as $row) {
                    if (microtime(true) >= $deadline) { self::release_stage_claim_cli($row['id']??0); continue; }
                    $raw=json_decode((string)($row['payload']??''),true);
                    if (!is_array($raw)) { $done_ids[]=absint($row['id']??0); continue; }
                    $lock=self::cli_product_lock_key($raw);
                    $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,8)',$lock));
                    if ($locked !== 1) { self::release_stage_claim_cli($row['id']??0); continue; }
                    try {
                        $result=self::import_one($raw);
                        $status=(string)($result['status']??'');
                        self::run_stats_record_import_status($status,(string)($raw['source']??''));
                        self::clear_failed_for_item(array('source'=>$raw['source']??'','url'=>$raw['sourceUrl']??''));
                        if($status==='created')$summary['created']++;
                        elseif($status==='created_no_price'){ $summary['created']++;$summary['created_no_price']++; }
                        elseif($status==='merged')$summary['merged']++;
                        elseif($status==='duplicate'||$status==='refreshed')$summary['duplicates']++;
                        elseif($status==='ignored')$summary['ignored']++;
                        $summary['processed']++;
                        $done_ids[]=absint($row['id']??0);
                    } catch (Throwable $e) {
                        $summary['errors']++;
                        self::stage_retry_or_fail($row,$e->getMessage());
                    } finally {
                        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
                    }
                }
            } finally {
                wp_defer_term_counting(false);
                $flush_lock='psb_bulk_flush_v26';
                $flush_got=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,15)',$flush_lock));
                try { self::end_bulk_mode(); }
                finally { if($flush_got===1)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$flush_lock)); }
            }
            self::delete_stage_ids($done_ids);
            if (self::memory_pressure_high()) break;
        }
        $summary['duration_seconds']=round(microtime(true)-$started,2);
        $summary['rate_per_second']=$summary['duration_seconds']>0?round($summary['processed']/$summary['duration_seconds'],2):0;
        $summary['stage_pending']=self::stage_pending_count();
        $summary['image_pending']=count(self::get_image_queue());
        return $summary;
    }

    public static function cli_drain_images($batch = 2, $seconds = 50) {
        $batch=max(1,min(8,(int)$batch)); $seconds=max(5,min(3600,(int)$seconds));
        $started=microtime(true);$done=0;
        while(microtime(true)-$started<$seconds){
            $before=count(self::get_image_queue()); if(!$before)break;
            for($i=0;$i<$batch;$i++){ if(!self::get_image_queue())break; self::image_worker(true); $done++; if(microtime(true)-$started>=$seconds)break; }
            if(count(self::get_image_queue()) >= $before && $done>0) usleep(250000);
        }
        return array('processed_jobs'=>$done,'image_pending'=>count(self::get_image_queue()),'duration_seconds'=>round(microtime(true)-$started,2));
    }

    public static function cli_status_array() {
        $counts=wp_count_posts('product');
        return array('stage_pending'=>self::stage_pending_count(),'image_pending'=>count(self::get_image_queue()),'url_queue'=>count(self::get_queue()),'products_publish'=>isset($counts->publish)?(int)$counts->publish:0);
    }

}

Product_Sync_Bridge_V26::init();

if (defined('WP_CLI') && WP_CLI) {
    class Product_Sync_Bridge_V26_CLI {
        public function drain($args,$assoc_args) {
            $batch=isset($assoc_args['batch'])?(int)$assoc_args['batch']:80;
            $seconds=isset($assoc_args['seconds'])?(int)$assoc_args['seconds']:50;
            $worker=isset($assoc_args['worker'])?sanitize_key($assoc_args['worker']):'';
            $r=Product_Sync_Bridge_V26::cli_drain_stage($batch,$seconds,$worker);
            WP_CLI::log(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        public function images($args,$assoc_args) {
            $batch=isset($assoc_args['batch'])?(int)$assoc_args['batch']:2;
            $seconds=isset($assoc_args['seconds'])?(int)$assoc_args['seconds']:50;
            $r=Product_Sync_Bridge_V26::cli_drain_images($batch,$seconds);
            WP_CLI::log(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        public function status($args,$assoc_args) {
            WP_CLI::log(wp_json_encode(Product_Sync_Bridge_V26::cli_status_array(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
    WP_CLI::add_command('kickbox-sync','Product_Sync_Bridge_V26_CLI');
}

