<?php
/**
 * CC攻击防御类
 */
defined('ABSPATH') || exit;

class WPRQ_CC_Defense {
    
    /**
     * 单例实例
     */
    private static $instance = null;
    
    /**
     * 数据库表名
     */
    private $table_name;
    
    /**
     * 被封禁的IP缓存
     */
    private $blocked_ips_cache = array();
    
    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'request_stats';
        
        // 定期清理旧统计数据
        add_action('wprq_cleanup_queue', array($this, 'cleanup_old_stats'));
        
        // 定期清理封禁IP缓存
        add_action('wprq_cleanup_queue', array($this, 'clear_blocked_cache'));
    }
    
    /**
     * 记录请求
     */
    public function record_request($visitor_id) {
        global $wpdb;
        
        $ip_address = WPRQ_Request_Queue::get_instance()->get_client_ip();
        
        $wpdb->insert(
            $this->table_name,
            array(
                'visitor_id'   => $visitor_id,
                'ip_address'   => $ip_address,
                'request_time' => current_time('mysql'),
                'request_url'  => isset($_SERVER['REQUEST_URI']) ? sanitize_url($_SERVER['REQUEST_URI']) : '/'
            )
        );
    }
    
    /**
     * 检测是否为攻击
     */
    public function is_attack($visitor_id) {
        global $wpdb;
        
        $ip_address = WPRQ_Request_Queue::get_instance()->get_client_ip();
        
        // 检查IP是否已被封禁
        if ($this->is_ip_blocked($ip_address)) {
            return true;
        }
        
        // 检查IP级别 - 每分钟最大请求数
        $max_requests_per_minute = get_option('wprq_max_requests_per_minute', 100);
        $ip_request_count = $this->get_ip_request_count($ip_address, 60);
        
        if ($ip_request_count > $max_requests_per_minute) {
            $this->block_ip($ip_address, '每分钟请求次数超限: ' . $ip_request_count);
            return true;
        }
        
        // 检查访客级别 - 每秒最大请求数
        $max_requests_per_second = get_option('wprq_max_requests_per_second', 10);
        $visitor_request_count = $this->get_visitor_request_count($visitor_id, 1);
        
        if ($visitor_request_count > $max_requests_per_second) {
            return true;
        }
        
        // 检查异常模式（短时间内大量不同URL请求）
        $unique_urls = $this->get_unique_url_count($visitor_id, 10);
        if ($unique_urls > 20) {
            $this->block_ip($ip_address, '短时间内请求大量不同URL: ' . $unique_urls);
            return true;
        }
        
        // 检查是否为可疑User-Agent
        if ($this->is_suspicious_user_agent()) {
            $this->block_ip($ip_address, '可疑User-Agent');
            return true;
        }
        
        // 检查是否有异常的请求头
        if ($this->has_suspicious_headers()) {
            $this->block_ip($ip_address, '可疑请求头');
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取IP请求次数
     */
    public function get_ip_request_count($ip_address, $time_window = 60) {
        global $wpdb;
        
        $cache_key = 'wprq_ip_count_' . md5($ip_address) . '_' . $time_window;
        $cached = wp_cache_get($cache_key, 'wprq');
        
        if (false !== $cached) {
            return $cached;
        }
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE ip_address = %s AND request_time > DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $ip_address,
                $time_window
            )
        );
        
        $count = intval($count);
        wp_cache_set($cache_key, $count, 'wprq', 10);
        
        return $count;
    }
    
    /**
     * 获取访客请求次数
     */
    public function get_visitor_request_count($visitor_id, $time_window = 1) {
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE visitor_id = %s AND request_time > DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $visitor_id,
                $time_window
            )
        );
        
        return intval($count);
    }
    
    /**
     * 获取唯一URL数量
     */
    public function get_unique_url_count($visitor_id, $time_window = 10) {
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT request_url) FROM {$this->table_name} WHERE visitor_id = %s AND request_time > DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $visitor_id,
                $time_window
            )
        );
        
        return intval($count);
    }
    
    /**
     * 检查是否为可疑User-Agent
     */
    public function is_suspicious_user_agent() {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return true; // 无User-Agent视为可疑
        }
        
        $user_agent = strtolower(sanitize_text_field($_SERVER['HTTP_USER_AGENT']));
        
        // 空User-Agent
        if (empty($user_agent)) {
            return true;
        }
        
        // 可疑的User-Agent关键词
        $suspicious_patterns = array(
            'curl',
            'wget',
            'python',
            'java/',
            'perl',
            'ruby',
            'go-http',
            'php',
            'scrapy',
            'httpclient',
            'okhttp',
            'libwww',
            'urllib',
            'mechanize',
            'phantom',
            'selenium',
            'headless'
        );
        
        foreach ($suspicious_patterns as $pattern) {
            if (strpos($user_agent, $pattern) !== false) {
                // 但允许搜索引擎爬虫
                $allowed_bots = array('googlebot', 'bingbot', 'baiduspider', 'yandexbot');
                foreach ($allowed_bots as $bot) {
                    if (strpos($user_agent, $bot) !== false) {
                        return false;
                    }
                }
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否有可疑请求头
     */
    public function has_suspicious_headers() {
        // 检查是否有Referer（某些攻击会缺少Referer）
        // 注意：这不是绝对可靠的检测方法
        
        // 检查Accept头
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = strtolower(sanitize_text_field($_SERVER['HTTP_ACCEPT']));
            if ($accept === '*/*' && !isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                return true; // 可疑：接受所有类型但没有语言头
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否被封禁
     */
    public function is_ip_blocked($ip_address) {
        // 检查缓存
        if (isset($this->blocked_ips_cache[$ip_address])) {
            return true;
        }
        
        // 检查WordPress选项
        $blocked_ips = get_option('wprq_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        if (isset($blocked_ips[$ip_address])) {
            $expire_time = $blocked_ips[$ip_address];
            if (time() < $expire_time) {
                $this->blocked_ips_cache[$ip_address] = true;
                return true;
            } else {
                // 过期了，移除
                unset($blocked_ips[$ip_address]);
                update_option('wprq_blocked_ips', $blocked_ips);
            }
        }
        
        return false;
    }
    
    /**
     * 封禁IP
     */
    public function block_ip($ip_address, $reason = '') {
        // 检查是否为白名单IP
        $whitelist_ips = get_option('wprq_whitelist_ips', array());
        if (!is_array($whitelist_ips)) {
            $whitelist_ips = array();
        }
        
        if (in_array($ip_address, $whitelist_ips)) {
            return false;
        }
        
        // 封禁时长（默认1小时）
        $block_duration = apply_filters('wprq_block_duration', 3600);
        
        $blocked_ips = get_option('wprq_blocked_ips', array());
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        $blocked_ips[$ip_address] = time() + $block_duration;
        update_option('wprq_blocked_ips', $blocked_ips);
        
        // 更新缓存
        $this->blocked_ips_cache[$ip_address] = true;
        
        // 记录日志
        $this->log_block($ip_address, $reason);
        
        return true;
    }
    
    /**
     * 解除IP封禁
     */
    public function unblock_ip($ip_address) {
        $blocked_ips = get_option('wprq_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        if (isset($blocked_ips[$ip_address])) {
            unset($blocked_ips[$ip_address]);
            update_option('wprq_blocked_ips', $blocked_ips);
            
            // 清除缓存
            unset($this->blocked_ips_cache[$ip_address]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取被封禁的IP列表
     */
    public function get_blocked_ips() {
        $blocked_ips = get_option('wprq_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            return array();
        }
        
        // 清理过期的封禁
        $now = time();
        $active_blocks = array();
        
        foreach ($blocked_ips as $ip => $expire_time) {
            if ($now < $expire_time) {
                $active_blocks[$ip] = array(
                    'ip'         => $ip,
                    'expire_at'  => date('Y-m-d H:i:s', $expire_time),
                    'remaining'  => $expire_time - $now
                );
            }
        }
        
        return $active_blocks;
    }
    
    /**
     * 清除封禁缓存
     */
    public function clear_blocked_cache() {
        $this->blocked_ips_cache = array();
    }
    
    /**
     * 记录封禁日志
     */
    public function log_block($ip_address, $reason) {
        $log_entry = array(
            'time'    => current_time('mysql'),
            'ip'      => $ip_address,
            'reason'  => $reason,
            'url'     => isset($_SERVER['REQUEST_URI']) ? sanitize_url($_SERVER['REQUEST_URI']) : '/',
            'ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
        );
        
        // 获取现有日志
        $logs = get_option('wprq_block_logs', array());
        if (!is_array($logs)) {
            $logs = array();
        }
        
        // 添加新日志
        array_unshift($logs, $log_entry);
        
        // 只保留最近1000条日志
        $logs = array_slice($logs, 0, 1000);
        
        update_option('wprq_block_logs', $logs);
    }
    
    /**
     * 获取封禁日志
     */
    public function get_block_logs($limit = 100) {
        $logs = get_option('wprq_block_logs', array());
        
        if (!is_array($logs)) {
            return array();
        }
        
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * 清理旧统计数据
     */
    public function cleanup_old_stats() {
        global $wpdb;
        
        // 删除7天前的统计记录
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE request_time < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
    }
    
    /**
     * 获取攻击统计
     */
    public function get_attack_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(DISTINCT ip_address) as unique_ips,
                COUNT(*) as total_requests,
                SUM(CASE WHEN request_time > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as last_hour,
                SUM(CASE WHEN request_time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as last_day
            FROM {$this->table_name}"
        );
        
        return $stats;
    }
    
    /**
     * 获取最活跃的IP
     */
    public function get_top_ips($limit = 10, $time_window = 3600) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ip_address, COUNT(*) as request_count 
                FROM {$this->table_name} 
                WHERE request_time > DATE_SUB(NOW(), INTERVAL %d SECOND) 
                GROUP BY ip_address 
                ORDER BY request_count DESC 
                LIMIT %d",
                $time_window,
                $limit
            )
        );
    }
    
    /**
     * 添加IP到白名单
     */
    public function add_ip_to_whitelist($ip_address) {
        $whitelist_ips = get_option('wprq_whitelist_ips', array());
        
        if (!is_array($whitelist_ips)) {
            $whitelist_ips = array();
        }
        
        if (!in_array($ip_address, $whitelist_ips)) {
            $whitelist_ips[] = $ip_address;
            update_option('wprq_whitelist_ips', $whitelist_ips);
        }
        
        // 同时解除封禁
        $this->unblock_ip($ip_address);
        
        return true;
    }
    
    /**
     * 从白名单移除IP
     */
    public function remove_ip_from_whitelist($ip_address) {
        $whitelist_ips = get_option('wprq_whitelist_ips', array());
        
        if (!is_array($whitelist_ips)) {
            return false;
        }
        
        $index = array_search($ip_address, $whitelist_ips);
        if ($index !== false) {
            unset($whitelist_ips[$index]);
            $whitelist_ips = array_values($whitelist_ips);
            update_option('wprq_whitelist_ips', $whitelist_ips);
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取白名单IP列表
     */
    public function get_whitelist_ips() {
        $whitelist_ips = get_option('wprq_whitelist_ips', array());
        
        if (!is_array($whitelist_ips)) {
            return array();
        }
        
        return $whitelist_ips;
    }
    
    /**
     * 重置统计数据
     */
    public function reset_stats() {
        global $wpdb;
        
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        delete_option('wprq_blocked_ips');
        delete_option('wprq_block_logs');
        
        $this->blocked_ips_cache = array();
        
        return true;
    }
    
    /**
     * 创建数据库表
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'request_stats';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            request_time DATETIME NOT NULL,
            request_url VARCHAR(2048) DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_visitor_time (visitor_id, request_time),
            KEY idx_ip_time (ip_address, request_time),
            KEY idx_request_time (request_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
