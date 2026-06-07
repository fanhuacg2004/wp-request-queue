<?php
/**
 * 核心队列管理类
 */
defined('ABSPATH') || exit;

class WPRQ_Request_Queue {
    
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
        $this->table_name = $wpdb->prefix . 'request_queue';
        
        // 核心钩子：在WordPress初始化时检查请求
        add_action('init', array($this, 'check_request'), 1);
        
        // 定时清理过期队列
        add_action('wprq_cleanup_queue', array($this, 'cleanup_expired'));
        
        // 定时处理队列
        add_action('wprq_process_queue', array($this, 'process_queue'));
        
        // 添加处理队列的定时任务
        if (!wp_next_scheduled('wprq_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'wprq_process_queue');
        }
    }
    
    /**
     * 检查请求
     */
    public function check_request() {
        // 检查是否启用队列
        if (!get_option('wprq_enable_queue', 1)) {
            return;
        }
        
        // 排除cron请求和插件自身状态轮询。
        if (wp_doing_cron() || $this->is_own_ajax_request()) {
            return;
        }

        // 已登录后台用户不进入队列，登录前入口仍需保护。
        if (is_admin() && is_user_logged_in()) {
            return;
        }
        
        // 排除登录页面和注册页面
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
            if (strpos($request_uri, 'wp-login.php') !== false || 
                strpos($request_uri, 'wp-cron.php') !== false) {
                return;
            }
        }
        
        // 排除静态资源
        if ($this->is_static_resource()) {
            return;
        }
        
        $visitor_id = $this->get_visitor_id();
        
        // 检查白名单
        $whitelist = WPRQ_Whitelist_Manager::get_instance();
        if ($whitelist->is_whitelisted($visitor_id)) {
            // 白名单用户检查请求频率
            if ($whitelist->check_rate_limit($visitor_id)) {
                // 更新最后访问时间
                $whitelist->update_last_access($visitor_id);
                return; // 正常访问
            } else {
                // 超过阈值，移出白名单
                $whitelist->remove_from_whitelist($visitor_id);
            }
        }
        
        // CC攻击检测
        $cc_defense = WPRQ_CC_Defense::get_instance();
        if ($cc_defense->is_attack($visitor_id)) {
            $this->block_request();
            exit;
        }
        
        // 记录请求统计
        $cc_defense->record_request($visitor_id);
        
        // 检查队列状态
        $queue_status = $this->get_queue_status($visitor_id);
        
        if ($queue_status === 'waiting' || $queue_status === 'processing') {
            // 显示等待页面
            $this->show_waiting_page($visitor_id);
            exit;
        } elseif ($queue_status === 'completed') {
            // 已完成，允许访问并加入白名单
            $whitelist->add_to_whitelist($visitor_id);
            return;
        } else {
            // 新请求，加入队列
            $queue_id = $this->add_to_queue($visitor_id);
            
            // 尝试消耗当前秒令牌并立即放行。
            if ($this->process_single_request($visitor_id)) {
                $whitelist->add_to_whitelist($visitor_id);
                return;
            } else {
                // 需要等待
                $this->show_waiting_page($visitor_id);
                exit;
            }
        }
    }
    
    /**
     * 添加到队列
     */
    public function add_to_queue($visitor_id) {
        global $wpdb;
        
        // 检查是否已有队列记录
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE visitor_id = %s AND status IN ('waiting', 'processing')",
                $visitor_id
            )
        );
        
        if ($existing) {
            return $existing;
        }
        
        $data = array(
            'visitor_id'   => $visitor_id,
            'ip_address'   => $this->get_client_ip(),
            'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'request_url'  => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/',
            'queue_token'  => wp_generate_password(32, false, false),
            'status'       => 'waiting',
            'created_at'   => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
            'expires_at'   => date('Y-m-d H:i:s', time() + get_option('wprq_queue_timeout', 300))
        );
        
        $wpdb->insert($this->table_name, $data);
        
        return $wpdb->insert_id;
    }
    
    /**
     * 处理队列
     */
    public function process_queue() {
        global $wpdb;
        
        // 获取等待中的请求（按先进先出顺序）
        $waiting_requests = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE status = 'waiting' AND expires_at > %s ORDER BY created_at ASC LIMIT %d",
                current_time('mysql'),
                max(1, absint(get_option('wprq_requests_per_second', 10)))
            )
        );
        
        foreach ($waiting_requests as $request) {
            if (!$this->consume_rate_slot()) {
                break;
            }

            $wpdb->update(
                $this->table_name,
                array(
                    'status'     => 'completed',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $request->id)
            );
        }
    }
    
    /**
     * 处理单个请求
     */
    public function process_single_request($visitor_id) {
        global $wpdb;
        
        if (!$this->consume_rate_slot()) {
            return false;
        }

        return (bool) $wpdb->update(
            $this->table_name,
            array(
                'status'     => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array(
                'visitor_id' => $visitor_id,
                'status'     => 'waiting'
            )
        );
    }
    
    /**
     * 完成请求
     */
    public function complete_request($visitor_id) {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            array(
                'status'     => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array(
                'visitor_id' => $visitor_id,
                'status'     => 'processing'
            )
        );
        
        // 继续处理队列
        $this->process_queue();
    }
    
    /**
     * 获取队列状态
     */
    public function get_queue_status($visitor_id) {
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$this->table_name} WHERE visitor_id = %s AND status != 'expired' ORDER BY created_at DESC LIMIT 1",
                $visitor_id
            )
        );
    }

    /**
     * 获取队列token
     */
    public function get_queue_token($visitor_id) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT queue_token FROM {$this->table_name} WHERE visitor_id = %s AND status != 'expired' ORDER BY created_at DESC LIMIT 1",
                $visitor_id
            )
        );
    }

    /**
     * 校验队列token
     */
    public function verify_queue_token($visitor_id, $queue_token) {
        if (empty($visitor_id) || empty($queue_token)) {
            return false;
        }

        $stored_token = $this->get_queue_token($visitor_id);

        return !empty($stored_token) && hash_equals($stored_token, $queue_token);
    }
    
    /**
     * 获取队列位置
     */
    public function get_queue_position($visitor_id) {
        global $wpdb;
        
        $position = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'waiting' AND created_at < (SELECT created_at FROM {$this->table_name} WHERE visitor_id = %s AND status = 'waiting' LIMIT 1)",
                $visitor_id
            )
        );
        
        return intval($position) + 1;
    }
    
    /**
     * 获取预计等待时间
     */
    public function get_estimated_wait_time($visitor_id) {
        $position = $this->get_queue_position($visitor_id);
        $requests_per_second = get_option('wprq_requests_per_second', 10);
        
        if ($requests_per_second <= 0) {
            $requests_per_second = 1;
        }
        
        return ceil($position / $requests_per_second);
    }
    
    /**
     * 显示等待页面
     */
    public function show_waiting_page($visitor_id) {
        $position = $this->get_queue_position($visitor_id);
        $wait_time = $this->get_estimated_wait_time($visitor_id);
        $custom_html = get_option('wprq_waiting_page_html', '');
        $queue_token = $this->get_queue_token($visitor_id);
        
        // 定义常量，用于前端资源加载
        define('WPRQ_SHOWING_WAITING_PAGE', true);
        
        // 加载等待页面模板
        include WPRQ_PLUGIN_DIR . 'templates/waiting-page.php';
    }
    
    /**
     * 检查是否可以立即处理
     */
    public function can_process_immediately() {
        $limit = max(1, absint(get_option('wprq_requests_per_second', 10)));
        $window = absint(get_option('wprq_rate_window', 0));
        $count = absint(get_option('wprq_rate_count', 0));

        return $window !== time() || $count < $limit;
    }

    /**
     * 消耗当前秒的放行令牌。
     */
    private function consume_rate_slot() {
        global $wpdb;

        $limit = max(1, absint(get_option('wprq_requests_per_second', 10)));
        $lock_name = $wpdb->prefix . 'wprq_rate_slot';
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name));

        if ((string) $locked !== '1') {
            return false;
        }

        try {
            $current_second = time();
            $window = absint(get_option('wprq_rate_window', 0));
            $count = absint(get_option('wprq_rate_count', 0));

            if ($window !== $current_second) {
                update_option('wprq_rate_window', $current_second, false);
                update_option('wprq_rate_count', 1, false);
                return true;
            }

            if ($count >= $limit) {
                return false;
            }

            update_option('wprq_rate_count', $count + 1, false);
            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
    
    /**
     * 清理过期队列
     */
    public function cleanup_expired() {
        global $wpdb;
        
        // 将过期的请求标记为expired
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET status = 'expired' WHERE status IN ('waiting', 'processing') AND expires_at < %s",
                current_time('mysql')
            )
        );
        
        // 删除30天前的记录
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
    
    /**
     * 获取访客唯一标识
     */
    public function get_visitor_id() {
        $cookie_name = 'wprq_visitor_token';

        if (!empty($_COOKIE[$cookie_name]) && preg_match('/^[A-Za-z0-9]{32,128}$/', wp_unslash($_COOKIE[$cookie_name]))) {
            return hash('sha256', wp_unslash($_COOKIE[$cookie_name]));
        }

        $token = wp_generate_password(64, false, false);
        $_COOKIE[$cookie_name] = $token;

        if (!headers_sent()) {
            $cookie_options = array(
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            );

            if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
                $cookie_options['domain'] = COOKIE_DOMAIN;
            }

            setcookie($cookie_name, $token, $cookie_options);
        }

        return hash('sha256', $token);
    }
    
    /**
     * 获取客户端IP
     */
    public function get_client_ip() {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';

        if (!filter_var($remote_addr, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }

        if (!$this->is_trusted_proxy($remote_addr)) {
            return $remote_addr;
        }

        $proxy_headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR');

        foreach ($proxy_headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($_SERVER[$header]));
            $ip = trim(explode(',', $value)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $remote_addr;
    }

    /**
     * 判断REMOTE_ADDR是否为可信代理。
     */
    private function is_trusted_proxy($ip) {
        $trusted_proxies = get_option('wprq_trusted_proxies', array());

        if (!is_array($trusted_proxies)) {
            $trusted_proxies = array_filter(array_map('trim', explode("\n", (string) $trusted_proxies)));
        }

        foreach ($trusted_proxies as $trusted_proxy) {
            if ($this->ip_matches_cidr($ip, $trusted_proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查IP是否匹配CIDR或单个IP。
     */
    private function ip_matches_cidr($ip, $cidr) {
        $cidr = trim($cidr);

        if ($cidr === '') {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            return hash_equals($ip, $cidr);
        }

        list($subnet, $mask) = explode('/', $cidr, 2);

        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $mask = absint($mask);
        if ($mask < 0 || $mask > 32) {
            return false;
        }

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - $mask);

        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * 是否插件自身AJAX状态请求。
     */
    private function is_own_ajax_request() {
        return wp_doing_ajax()
            && isset($_REQUEST['action'])
            && sanitize_key(wp_unslash($_REQUEST['action'])) === 'wprq_check_status';
    }
    
    /**
     * 检查是否为静态资源
     */
    public function is_static_resource() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        
        $request_uri = strtolower(sanitize_text_field($_SERVER['REQUEST_URI']));
        $extensions = array(
            '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico',
            '.woff', '.woff2', '.ttf', '.eot', '.mp4', '.webm', '.ogg',
            '.mp3', '.wav', '.pdf', '.doc', '.docx', '.xls', '.xlsx',
            '.zip', '.rar', '.7z', '.tar', '.gz'
        );
        
        foreach ($extensions as $ext) {
            if (substr($request_uri, -strlen($ext)) === $ext) {
                return true;
            }
        }
        
        // 检查是否为WordPress核心静态资源路径
        $static_paths = array('/wp-includes/', '/wp-content/uploads/', '/wp-content/plugins/', '/wp-content/themes/');
        foreach ($static_paths as $path) {
            if (strpos($request_uri, $path) !== false && preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/i', $request_uri)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否为搜索引擎爬虫
     */
    public function is_search_engine_bot() {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        
        $user_agent = strtolower(sanitize_text_field($_SERVER['HTTP_USER_AGENT']));
        $bots = array(
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'sogou', 'exabot', 'facebot', 'ia_archiver',
            'semrushbot', 'ahrefsbot', 'dotbot', 'mj12bot', 'blexbot'
        );
        
        foreach ($bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 阻止请求
     */
    public function block_request() {
        status_header(429);
        header('Retry-After: 60');
        
        $message = apply_filters('wprq_block_message', '请求过于频繁，请稍后再试。');
        
        wp_die(
            esc_html($message),
            '请求被拒绝 (429)',
            array(
                'response'  => 429,
                'back_link' => true
            )
        );
    }
    
    /**
     * 获取队列统计
     */
    public function get_queue_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
            FROM {$this->table_name}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        
        return $stats;
    }
    
    /**
     * 创建数据库表
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'request_queue';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) DEFAULT '',
            request_url VARCHAR(2048) DEFAULT '',
            queue_token VARCHAR(64) DEFAULT '',
            status ENUM('waiting', 'processing', 'completed', 'expired') DEFAULT 'waiting',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_status_created (status, created_at),
            KEY idx_visitor_id (visitor_id),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
