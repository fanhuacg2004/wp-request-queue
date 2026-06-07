<?php
/**
 * 白名单管理类
 */
defined('ABSPATH') || exit;

class WPRQ_Whitelist_Manager {
    
    /**
     * 单例实例
     */
    private static $instance = null;
    
    /**
     * 数据库表名
     */
    private $table_name;
    
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
        $this->table_name = $wpdb->prefix . 'request_whitelist';
        
        // 定期清理过期白名单
        add_action('wprq_cleanup_queue', array($this, 'cleanup_expired'));
    }
    
    /**
     * 检查是否在白名单中
     */
    public function is_whitelisted($visitor_id) {
        global $wpdb;
        
        $cache_key = 'wprq_whitelist_' . $visitor_id;
        $cached = wp_cache_get($cache_key, 'wprq');
        
        if (false !== $cached) {
            return $cached;
        }
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE visitor_id = %s AND is_active = 1",
                $visitor_id
            )
        );
        
        $is_whitelisted = !empty($result);
        wp_cache_set($cache_key, $is_whitelisted, 'wprq', 300);
        
        return $is_whitelisted;
    }
    
    /**
     * 添加到白名单
     */
    public function add_to_whitelist($visitor_id) {
        global $wpdb;
        
        $ip_address = WPRQ_Request_Queue::get_instance()->get_client_ip();
        $now = current_time('mysql');
        
        // 检查是否已存在
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, request_count FROM {$this->table_name} WHERE visitor_id = %s",
                $visitor_id
            )
        );
        
        if ($existing) {
            // 更新现有记录
            $wpdb->update(
                $this->table_name,
                array(
                    'last_access'       => $now,
                    'last_request_time' => $now,
                    'is_active'         => 1,
                    'request_count'     => 1,
                    'ip_address'        => $ip_address
                ),
                array('id' => $existing->id)
            );
        } else {
            // 插入新记录
            $wpdb->insert(
                $this->table_name,
                array(
                    'visitor_id'        => $visitor_id,
                    'ip_address'        => $ip_address,
                    'first_access'      => $now,
                    'last_access'       => $now,
                    'last_request_time' => $now,
                    'request_count'     => 1,
                    'is_active'         => 1
                )
            );
        }
        
        // 清除缓存
        $cache_key = 'wprq_whitelist_' . $visitor_id;
        wp_cache_delete($cache_key, 'wprq');
    }
    
    /**
     * 从白名单移除
     */
    public function remove_from_whitelist($visitor_id) {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            array(
                'is_active' => 0,
                'request_count' => 0
            ),
            array(
                'visitor_id' => $visitor_id
            )
        );
        
        // 清除缓存
        $cache_key = 'wprq_whitelist_' . $visitor_id;
        wp_cache_delete($cache_key, 'wprq');
    }
    
    /**
     * 检查请求频率限制
     */
    public function check_rate_limit($visitor_id) {
        global $wpdb;
        
        $threshold = get_option('wprq_whitelist_threshold', 30);
        
        $now = current_time('mysql');

        // 新时间窗口内重置计数。
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET request_count = 0, last_request_time = %s WHERE visitor_id = %s AND is_active = 1 AND last_request_time < DATE_SUB(%s, INTERVAL 1 SECOND)",
                $now,
                $visitor_id,
                $now
            )
        );

        // 原子增加计数，只有未超过阈值时才放行。
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET request_count = request_count + 1, last_request_time = %s WHERE visitor_id = %s AND is_active = 1 AND request_count < %d",
                $now,
                $visitor_id,
                $threshold
            )
        );

        return $updated > 0;
    }
    
    /**
     * 更新最后访问时间
     */
    public function update_last_access($visitor_id) {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET last_access = %s WHERE visitor_id = %s",
                current_time('mysql'),
                $visitor_id
            )
        );
    }
    
    /**
     * 清理过期白名单
     */
    public function cleanup_expired() {
        global $wpdb;
        
        // 获取过期时间设置（默认7天）
        $expire_days = apply_filters('wprq_whitelist_expire_days', 7);
        
        // 将长期不活跃的白名单标记为非活跃
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET is_active = 0 WHERE is_active = 1 AND last_access < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $expire_days
            )
        );
        
        // 删除30天前的非活跃记录
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE is_active = 0 AND last_access < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
    
    /**
     * 获取白名单统计
     */
    public function get_whitelist_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN last_access > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as active_last_hour
            FROM {$this->table_name}"
        );
        
        return $stats;
    }
    
    /**
     * 获取白名单列表
     */
    public function get_whitelist_list($page = 1, $per_page = 20) {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE is_active = 1 ORDER BY last_access DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_active = 1"
        );
        
        return array(
            'items' => $results,
            'total' => intval($total),
            'pages' => ceil($total / $per_page)
        );
    }
    
    /**
     * 手动添加白名单
     */
    public function manual_add($visitor_id, $ip_address = '') {
        if (empty($ip_address)) {
            $ip_address = '0.0.0.0';
        }
        
        $now = current_time('mysql');
        
        global $wpdb;
        
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE visitor_id = %s",
                $visitor_id
            )
        );
        
        if ($existing) {
            $wpdb->update(
                $this->table_name,
                array(
                    'is_active'  => 1,
                    'ip_address' => $ip_address
                ),
                array('id' => $existing)
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                array(
                    'visitor_id'        => $visitor_id,
                    'ip_address'        => $ip_address,
                    'first_access'      => $now,
                    'last_access'       => $now,
                    'last_request_time' => $now,
                    'request_count'     => 0,
                    'is_active'         => 1
                )
            );
        }
        
        // 清除缓存
        $cache_key = 'wprq_whitelist_' . $visitor_id;
        wp_cache_delete($cache_key, 'wprq');
        
        return true;
    }
    
    /**
     * 手动移除白名单
     */
    public function manual_remove($visitor_id) {
        global $wpdb;
        
        $wpdb->delete(
            $this->table_name,
            array('visitor_id' => $visitor_id)
        );
        
        // 清除缓存
        $cache_key = 'wprq_whitelist_' . $visitor_id;
        wp_cache_delete($cache_key, 'wprq');
        
        return true;
    }
    
    /**
     * 创建数据库表
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'request_whitelist';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            first_access DATETIME NOT NULL,
            last_access DATETIME NOT NULL,
            request_count INT UNSIGNED DEFAULT 0,
            last_request_time DATETIME NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY idx_visitor_id (visitor_id),
            KEY idx_is_active (is_active),
            KEY idx_last_access (last_access)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
