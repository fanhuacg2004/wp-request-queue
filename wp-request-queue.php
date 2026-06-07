<?php
/**
 * Plugin Name: WP Request Queue
 * Plugin URI: https://github.com/wp-request-queue
 * Description: 高并发请求队列管理和CC攻击防御插件，保护网站免受恶意流量攻击
 * Version: 1.0.0
 * Author: WP Request Queue
 * Author URI: https://github.com/wp-request-queue
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-request-queue
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// 插件版本
define('WPRQ_VERSION', '1.0.0');

// 插件目录路径
define('WPRQ_PLUGIN_DIR', plugin_dir_path(__FILE__));

// 插件目录URL
define('WPRQ_PLUGIN_URL', plugin_dir_url(__FILE__));

// 插件主文件
define('WPRQ_PLUGIN_FILE', __FILE__);

/**
 * 自动加载器
 */
spl_autoload_register(function ($class) {
    $prefix = 'WPRQ_';
    
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $class_name = str_replace($prefix, '', $class);
    $file = WPRQ_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * 插件初始化
 */
add_action('plugins_loaded', function() {
    // 加载文本域
    load_plugin_textdomain('wp-request-queue', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // 初始化核心组件
    WPRQ_Request_Queue::get_instance();
    WPRQ_Whitelist_Manager::get_instance();
    WPRQ_CC_Defense::get_instance();
    
    // 后台设置
    if (is_admin()) {
        WPRQ_Admin_Settings::get_instance();
    }
});

/**
 * 注册激活钩子
 */
register_activation_hook(__FILE__, function() {
    // 创建数据库表
    WPRQ_Request_Queue::create_tables();
    WPRQ_Whitelist_Manager::create_tables();
    WPRQ_CC_Defense::create_tables();
    
    // 设置默认选项
    if (false === get_option('wprq_requests_per_second')) {
        add_option('wprq_requests_per_second', 10);
    }
    if (false === get_option('wprq_whitelist_threshold')) {
        add_option('wprq_whitelist_threshold', 30);
    }
    if (false === get_option('wprq_queue_timeout')) {
        add_option('wprq_queue_timeout', 300);
    }
    if (false === get_option('wprq_max_requests_per_minute')) {
        add_option('wprq_max_requests_per_minute', 100);
    }
    if (false === get_option('wprq_max_requests_per_second')) {
        add_option('wprq_max_requests_per_second', 10);
    }
    if (false === get_option('wprq_waiting_page_html')) {
        add_option('wprq_waiting_page_html', '');
    }
    if (false === get_option('wprq_enable_queue')) {
        add_option('wprq_enable_queue', 1);
    }
    if (false === get_option('wprq_trusted_proxies')) {
        add_option('wprq_trusted_proxies', array());
    }
    if (false === get_option('wprq_rate_window')) {
        add_option('wprq_rate_window', 0, '', false);
    }
    if (false === get_option('wprq_rate_count')) {
        add_option('wprq_rate_count', 0, '', false);
    }
    
    // 添加定时清理任务
    if (!wp_next_scheduled('wprq_cleanup_queue')) {
        wp_schedule_event(time(), 'every_minute', 'wprq_cleanup_queue');
    }
    
    // 刷新重写规则
    flush_rewrite_rules();
});

/**
 * 注册停用钩子
 */
register_deactivation_hook(__FILE__, function() {
    // 清除定时任务
    wp_clear_scheduled_hook('wprq_cleanup_queue');
    
    // 刷新重写规则
    flush_rewrite_rules();
});

/**
 * 注册卸载钩子
 */
register_uninstall_hook(__FILE__, 'wprq_uninstall');

function wprq_uninstall() {
    global $wpdb;
    
    // 删除数据库表
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}request_queue");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}request_whitelist");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}request_stats");
    
    // 删除选项
    delete_option('wprq_requests_per_second');
    delete_option('wprq_whitelist_threshold');
    delete_option('wprq_queue_timeout');
    delete_option('wprq_max_requests_per_minute');
    delete_option('wprq_max_requests_per_second');
    delete_option('wprq_waiting_page_html');
    delete_option('wprq_enable_queue');
    delete_option('wprq_trusted_proxies');
    delete_option('wprq_rate_window');
    delete_option('wprq_rate_count');
}

/**
 * 添加自定义定时任务间隔
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => __('每分钟', 'wp-request-queue')
    );
    
    $schedules['every_five_minutes'] = array(
        'interval' => 300,
        'display'  => __('每5分钟', 'wp-request-queue')
    );
    
    return $schedules;
});

/**
 * AJAX处理 - 检查队列状态
 */
add_action('wp_ajax_wprq_check_status', 'wprq_ajax_check_status');
add_action('wp_ajax_nopriv_wprq_check_status', 'wprq_ajax_check_status');

function wprq_ajax_check_status() {
    // 验证nonce
    check_ajax_referer('wprq_queue_nonce', 'nonce');
    
    // 验证visitor_id
    if (!isset($_POST['visitor_id']) || empty($_POST['visitor_id']) || !isset($_POST['queue_token']) || empty($_POST['queue_token'])) {
        wp_send_json_error(array('message' => '无效的访客ID'));
    }
    
    $visitor_id = sanitize_text_field(wp_unslash($_POST['visitor_id']));
    $queue_token = sanitize_text_field(wp_unslash($_POST['queue_token']));
    $queue = WPRQ_Request_Queue::get_instance();
    $whitelist = WPRQ_Whitelist_Manager::get_instance();

    if (!$queue->verify_queue_token($visitor_id, $queue_token)) {
        wp_send_json_error(array('message' => '队列令牌无效'));
    }
    
    // 检查是否在白名单中
    if ($whitelist->is_whitelisted($visitor_id)) {
        wp_send_json_success(array(
            'status'         => 'whitelisted',
            'position'       => 0,
            'estimated_time' => 0,
            'message'        => '您在白名单中，可以直接访问'
        ));
    }
    
    $status = $queue->get_queue_status($visitor_id);
    
    if ($status === 'processing') {
        wp_send_json_success(array(
            'status'         => 'processing',
            'position'       => 0,
            'estimated_time' => 0,
            'message'        => '即将放行...'
        ));
    } elseif ($status === 'waiting') {
        wp_send_json_success(array(
            'status'         => 'waiting',
            'position'       => $queue->get_queue_position($visitor_id),
            'estimated_time' => $queue->get_estimated_wait_time($visitor_id),
            'message'        => '请耐心等待...'
        ));
    } elseif ($status === 'completed') {
        wp_send_json_success(array(
            'status'         => 'completed',
            'position'       => 0,
            'estimated_time' => 0,
            'message'        => '处理完成，正在跳转...'
        ));
    } else {
        wp_send_json_success(array(
            'status'         => 'ready',
            'position'       => 0,
            'estimated_time' => 0,
            'message'        => '可以访问'
        ));
    }
}

/**
 * 前端资源加载
 */
add_action('wp_enqueue_scripts', function() {
    // 只在等待页面加载资源
    if (defined('WPRQ_SHOWING_WAITING_PAGE') && WPRQ_SHOWING_WAITING_PAGE) {
        wp_enqueue_style('wprq-waiting-page', WPRQ_PLUGIN_URL . 'assets/css/waiting-page.css', array(), WPRQ_VERSION);
        wp_enqueue_script('wprq-queue-handler', WPRQ_PLUGIN_URL . 'assets/js/queue-handler.js', array(), WPRQ_VERSION, true);
        
        wp_localize_script('wprq-queue-handler', 'wprqConfig', array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('wprq_queue_nonce'),
            'checkInterval' => 1000,
            'visitorId'     => WPRQ_Request_Queue::get_instance()->get_visitor_id()
        ));
    }
});

/**
 * 插件操作链接
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wp-request-queue')) . '">' . esc_html__('设置', 'wp-request-queue') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
