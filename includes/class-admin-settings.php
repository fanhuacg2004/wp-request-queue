<?php
/**
 * 后台设置类
 */
defined('ABSPATH') || exit;

class WPRQ_Admin_Settings {
    
    /**
     * 单例实例
     */
    private static $instance = null;
    
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
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_wprq_action', array($this, 'handle_admin_actions'));
    }
    
    /**
     * 添加后台菜单
     */
    public function add_menu_pages() {
        // 主菜单
        add_menu_page(
            '请求队列设置',
            '请求队列',
            'manage_options',
            'wp-request-queue',
            array($this, 'render_settings_page'),
            'dashicons-clock',
            100
        );
        
        // 设置子菜单
        add_submenu_page(
            'wp-request-queue',
            '基本设置',
            '基本设置',
            'manage_options',
            'wp-request-queue',
            array($this, 'render_settings_page')
        );
        
        // 队列监控子菜单
        add_submenu_page(
            'wp-request-queue',
            '队列监控',
            '队列监控',
            'manage_options',
            'wp-request-queue-monitor',
            array($this, 'render_monitor_page')
        );
        
        // 白名单管理子菜单
        add_submenu_page(
            'wp-request-queue',
            '白名单管理',
            '白名单管理',
            'manage_options',
            'wp-request-queue-whitelist',
            array($this, 'render_whitelist_page')
        );
        
        // IP封禁管理子菜单
        add_submenu_page(
            'wp-request-queue',
            'IP封禁管理',
            'IP封禁管理',
            'manage_options',
            'wp-request-queue-blocked',
            array($this, 'render_blocked_page')
        );
        
        // 攻击日志子菜单
        add_submenu_page(
            'wp-request-queue',
            '攻击日志',
            '攻击日志',
            'manage_options',
            'wp-request-queue-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * 注册设置选项
     */
    public function register_settings() {
        // 基本设置
        register_setting('wprq_settings', 'wprq_enable_queue', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1
        ));
        
        register_setting('wprq_settings', 'wprq_requests_per_second', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_requests_per_second'),
            'default'           => 10
        ));
        
        register_setting('wprq_settings', 'wprq_whitelist_threshold', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_whitelist_threshold'),
            'default'           => 30
        ));
        
        register_setting('wprq_settings', 'wprq_queue_timeout', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_queue_timeout'),
            'default'           => 300
        ));
        
        register_setting('wprq_settings', 'wprq_waiting_page_html', array(
            'type'              => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default'           => ''
        ));
        
        // CC防御设置
        register_setting('wprq_settings', 'wprq_max_requests_per_minute', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_max_requests_per_minute'),
            'default'           => 100
        ));
        
        register_setting('wprq_settings', 'wprq_max_requests_per_second', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_max_requests_per_second'),
            'default'           => 10
        ));

        register_setting('wprq_settings', 'wprq_trusted_proxies', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_trusted_proxies'),
            'default'           => array()
        ));
        
        // 添加设置区块
        $this->add_settings_sections();
        $this->add_settings_fields();
    }
    
    /**
     * 添加设置区块
     */
    private function add_settings_sections() {
        add_settings_section(
            'wprq_general_section',
            '队列设置',
            array($this, 'render_general_section'),
            'wp-request-queue'
        );
        
        add_settings_section(
            'wprq_waiting_page_section',
            '等待页面设置',
            array($this, 'render_waiting_page_section'),
            'wp-request-queue'
        );
        
        add_settings_section(
            'wprq_cc_section',
            'CC攻击防御设置',
            array($this, 'render_cc_section'),
            'wp-request-queue'
        );
    }
    
    /**
     * 添加设置字段
     */
    private function add_settings_fields() {
        // 队列设置字段
        add_settings_field(
            'wprq_enable_queue',
            '启用队列功能',
            array($this, 'render_checkbox_field'),
            'wp-request-queue',
            'wprq_general_section',
            array('name' => 'wprq_enable_queue', 'label' => '启用后将对访客请求进行队列管理')
        );
        
        add_settings_field(
            'wprq_requests_per_second',
            '每秒响应请求数',
            array($this, 'render_number_field'),
            'wp-request-queue',
            'wprq_general_section',
            array('name' => 'wprq_requests_per_second', 'min' => 1, 'max' => 1000, 'description' => '每秒同时处理的最大请求数')
        );
        
        add_settings_field(
            'wprq_whitelist_threshold',
            '白名单请求阈值',
            array($this, 'render_number_field'),
            'wp-request-queue',
            'wprq_general_section',
            array('name' => 'wprq_whitelist_threshold', 'min' => 1, 'max' => 100, 'description' => '白名单用户每秒请求超过此值将被移出白名单')
        );
        
        add_settings_field(
            'wprq_queue_timeout',
            '队列超时时间(秒)',
            array($this, 'render_number_field'),
            'wp-request-queue',
            'wprq_general_section',
            array('name' => 'wprq_queue_timeout', 'min' => 60, 'max' => 3600, 'description' => '队列等待超时时间，超时后请求将被标记为过期')
        );
        
        // 等待页面设置字段
        add_settings_field(
            'wprq_waiting_page_html',
            '自定义等待页面内容',
            array($this, 'render_editor_field'),
            'wp-request-queue',
            'wprq_waiting_page_section',
            array('name' => 'wprq_waiting_page_html', 'description' => '留空使用默认等待页面，支持HTML和CSS')
        );
        
        // CC防御设置字段
        add_settings_field(
            'wprq_max_requests_per_minute',
            '每分钟最大请求数',
            array($this, 'render_number_field'),
            'wp-request-queue',
            'wprq_cc_section',
            array('name' => 'wprq_max_requests_per_minute', 'min' => 10, 'max' => 10000, 'description' => '单个IP每分钟最大请求次数，超过将被封禁')
        );
        
        add_settings_field(
            'wprq_max_requests_per_second',
            '每秒最大请求数',
            array($this, 'render_number_field'),
            'wp-request-queue',
            'wprq_cc_section',
            array('name' => 'wprq_max_requests_per_second', 'min' => 1, 'max' => 100, 'description' => '单个访客每秒最大请求次数')
        );

        add_settings_field(
            'wprq_trusted_proxies',
            '可信代理IP/CIDR',
            array($this, 'render_textarea_list_field'),
            'wp-request-queue',
            'wprq_cc_section',
            array('name' => 'wprq_trusted_proxies', 'description' => '每行一个可信反向代理IP或IPv4 CIDR。只有REMOTE_ADDR匹配这里时，才信任X-Forwarded-For/CF-Connecting-IP。')
        );
    }
    
    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors(); ?>
            
            <div class="wprq-settings-container">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('wprq_settings');
                    do_settings_sections('wp-request-queue');
                    submit_button('保存设置');
                    ?>
                </form>
            </div>
            
            <div class="wprq-quick-stats">
                <h2>快速统计</h2>
                <?php $this->render_quick_stats(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染监控页面
     */
    public function render_monitor_page() {
        $queue = WPRQ_Request_Queue::get_instance();
        $stats = $queue->get_queue_stats();
        
        ?>
        <div class="wrap">
            <h1>队列监控</h1>
            
            <div class="wprq-monitor-dashboard">
                <div class="wprq-stat-cards">
                    <div class="wprq-stat-card">
                        <h3>等待中</h3>
                        <div class="wprq-stat-number"><?php echo esc_html($stats->waiting ?? 0); ?></div>
                    </div>
                    <div class="wprq-stat-card">
                        <h3>处理中</h3>
                        <div class="wprq-stat-number"><?php echo esc_html($stats->processing ?? 0); ?></div>
                    </div>
                    <div class="wprq-stat-card">
                        <h3>已完成</h3>
                        <div class="wprq-stat-number"><?php echo esc_html($stats->completed ?? 0); ?></div>
                    </div>
                    <div class="wprq-stat-card">
                        <h3>已过期</h3>
                        <div class="wprq-stat-number"><?php echo esc_html($stats->expired ?? 0); ?></div>
                    </div>
                </div>
                
                <div class="wprq-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wprq_action">
                        <input type="hidden" name="wprq_action" value="process_queue">
                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                        <button type="submit" class="button button-primary">手动处理队列</button>
                    </form>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="wprq_action">
                        <input type="hidden" name="wprq_action" value="cleanup_expired">
                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                        <button type="submit" class="button">清理过期队列</button>
                    </form>
                </div>
                
                <div class="wprq-queue-list">
                    <h2>当前队列</h2>
                    <?php $this->render_queue_list(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染白名单管理页面
     */
    public function render_whitelist_page() {
        $whitelist = WPRQ_Whitelist_Manager::get_instance();
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $whitelist_data = $whitelist->get_whitelist_list($page);
        $stats = $whitelist->get_whitelist_stats();
        
        ?>
        <div class="wrap">
            <h1>白名单管理</h1>
            
            <div class="wprq-stat-cards">
                <div class="wprq-stat-card">
                    <h3>总白名单</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($stats->total ?? 0); ?></div>
                </div>
                <div class="wprq-stat-card">
                    <h3>活跃用户</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($stats->active ?? 0); ?></div>
                </div>
                <div class="wprq-stat-card">
                    <h3>1小时内活跃</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($stats->active_last_hour ?? 0); ?></div>
                </div>
            </div>
            
            <div class="wprq-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wprq-add-whitelist-form">
                    <input type="hidden" name="action" value="wprq_action">
                    <input type="hidden" name="wprq_action" value="add_whitelist">
                    <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                    <label>添加访客ID到白名单:</label>
                    <input type="text" name="visitor_id" placeholder="访客ID" class="regular-text">
                    <button type="submit" class="button button-primary">添加</button>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>访客ID</th>
                        <th>IP地址</th>
                        <th>首次访问</th>
                        <th>最后访问</th>
                        <th>请求次数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($whitelist_data['items'])): ?>
                        <?php foreach ($whitelist_data['items'] as $item): ?>
                            <tr>
                                <td><?php echo esc_html(substr($item->visitor_id, 0, 16)) . '...'; ?></td>
                                <td><?php echo esc_html($item->ip_address); ?></td>
                                <td><?php echo esc_html($item->first_access); ?></td>
                                <td><?php echo esc_html($item->last_access); ?></td>
                                <td><?php echo esc_html($item->request_count); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="wprq_action">
                                        <input type="hidden" name="wprq_action" value="remove_whitelist">
                                        <input type="hidden" name="visitor_id" value="<?php echo esc_attr($item->visitor_id); ?>">
                                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('确定移除此白名单?')">移除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">暂无白名单记录</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($whitelist_data['pages'] > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(paginate_links(array(
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $whitelist_data['pages'],
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;'
                        )));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 渲染IP封禁管理页面
     */
    public function render_blocked_page() {
        $cc_defense = WPRQ_CC_Defense::get_instance();
        $blocked_ips = $cc_defense->get_blocked_ips();
        $whitelist_ips = $cc_defense->get_whitelist_ips();
        
        ?>
        <div class="wrap">
            <h1>IP封禁管理</h1>
            
            <div class="wprq-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wprq-block-ip-form">
                    <input type="hidden" name="action" value="wprq_action">
                    <input type="hidden" name="wprq_action" value="block_ip">
                    <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                    <label>封禁IP地址:</label>
                    <input type="text" name="ip_address" placeholder="IP地址" class="regular-text">
                    <button type="submit" class="button button-primary">封禁</button>
                </form>
            </div>
            
            <h2>当前封禁的IP</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>过期时间</th>
                        <th>剩余时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($blocked_ips)): ?>
                        <?php foreach ($blocked_ips as $ip => $info): ?>
                            <tr>
                                <td><?php echo esc_html($ip); ?></td>
                                <td><?php echo esc_html($info['expire_at']); ?></td>
                                <td><?php echo esc_html($this->format_time_remaining($info['remaining'])); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="wprq_action">
                                        <input type="hidden" name="wprq_action" value="unblock_ip">
                                        <input type="hidden" name="ip_address" value="<?php echo esc_attr($ip); ?>">
                                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                                        <button type="submit" class="button button-small">解除封禁</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">暂无封禁IP</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <h2>IP白名单</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wprq-add-whitelist-ip-form">
                <input type="hidden" name="action" value="wprq_action">
                <input type="hidden" name="wprq_action" value="add_whitelist_ip">
                <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                <input type="text" name="ip_address" placeholder="IP地址" class="regular-text">
                <button type="submit" class="button button-primary">添加到白名单</button>
            </form>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($whitelist_ips)): ?>
                        <?php foreach ($whitelist_ips as $ip): ?>
                            <tr>
                                <td><?php echo esc_html($ip); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="wprq_action">
                                        <input type="hidden" name="wprq_action" value="remove_whitelist_ip">
                                        <input type="hidden" name="ip_address" value="<?php echo esc_attr($ip); ?>">
                                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                                        <button type="submit" class="button button-small">移除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2">暂无白名单IP</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * 渲染攻击日志页面
     */
    public function render_logs_page() {
        $cc_defense = WPRQ_CC_Defense::get_instance();
        $logs = $cc_defense->get_block_logs(200);
        $attack_stats = $cc_defense->get_attack_stats();
        $top_ips = $cc_defense->get_top_ips(20);
        
        ?>
        <div class="wrap">
            <h1>攻击日志</h1>
            
            <div class="wprq-stat-cards">
                <div class="wprq-stat-card">
                    <h3>唯一IP数</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($attack_stats->unique_ips ?? 0); ?></div>
                </div>
                <div class="wprq-stat-card">
                    <h3>总请求数</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($attack_stats->total_requests ?? 0); ?></div>
                </div>
                <div class="wprq-stat-card">
                    <h3>最近1小时</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($attack_stats->last_hour ?? 0); ?></div>
                </div>
                <div class="wprq-stat-card">
                    <h3>最近24小时</h3>
                    <div class="wprq-stat-number"><?php echo esc_html($attack_stats->last_day ?? 0); ?></div>
                </div>
            </div>
            
            <div class="wprq-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wprq_action">
                    <input type="hidden" name="wprq_action" value="reset_stats">
                    <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                    <button type="submit" class="button" onclick="return confirm('确定重置所有统计数据?')">重置统计数据</button>
                </form>
            </div>
            
            <h2>最活跃的IP (最近1小时)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>请求次数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($top_ips)): ?>
                        <?php foreach ($top_ips as $ip): ?>
                            <tr>
                                <td><?php echo esc_html($ip->ip_address); ?></td>
                                <td><?php echo esc_html($ip->request_count); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="wprq_action">
                                        <input type="hidden" name="wprq_action" value="block_ip">
                                        <input type="hidden" name="ip_address" value="<?php echo esc_attr($ip->ip_address); ?>">
                                        <?php wp_nonce_field('wprq_admin_action', 'wprq_nonce'); ?>
                                        <button type="submit" class="button button-small">封禁</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <h2>封禁日志</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>IP地址</th>
                        <th>原因</th>
                        <th>请求URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['time']); ?></td>
                                <td><?php echo esc_html($log['ip']); ?></td>
                                <td><?php echo esc_html($log['reason']); ?></td>
                                <td><?php echo esc_html($log['url']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">暂无日志</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * 渲染队列列表
     */
    private function render_queue_list() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'request_queue';
        $items = $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE status IN ('waiting', 'processing') ORDER BY created_at ASC LIMIT 50"
        );
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>访客ID</th>
                    <th>IP地址</th>
                    <th>请求URL</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>过期时间</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo esc_html(substr($item->visitor_id, 0, 16)) . '...'; ?></td>
                            <td><?php echo esc_html($item->ip_address); ?></td>
                            <td><?php echo esc_html($item->request_url); ?></td>
                            <td>
                                <span class="wprq-status wprq-status-<?php echo esc_attr($item->status); ?>">
                                    <?php echo esc_html($item->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($item->created_at); ?></td>
                            <td><?php echo esc_html($item->expires_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">队列为空</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * 渲染快速统计
     */
    private function render_quick_stats() {
        $queue = WPRQ_Request_Queue::get_instance();
        $whitelist = WPRQ_Whitelist_Manager::get_instance();
        $cc_defense = WPRQ_CC_Defense::get_instance();
        
        $queue_stats = $queue->get_queue_stats();
        $whitelist_stats = $whitelist->get_whitelist_stats();
        $blocked_ips = $cc_defense->get_blocked_ips();
        
        ?>
        <div class="wprq-stat-cards">
            <div class="wprq-stat-card">
                <h3>队列等待</h3>
                <div class="wprq-stat-number"><?php echo esc_html($queue_stats->waiting ?? 0); ?></div>
            </div>
            <div class="wprq-stat-card">
                <h3>白名单用户</h3>
                <div class="wprq-stat-number"><?php echo esc_html($whitelist_stats->active ?? 0); ?></div>
            </div>
            <div class="wprq-stat-card">
                <h3>封禁IP数</h3>
                <div class="wprq-stat-number"><?php echo esc_html(count($blocked_ips)); ?></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 格式化剩余时间
     */
    private function format_time_remaining($seconds) {
        if ($seconds < 60) {
            return $seconds . '秒';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . '分钟';
        } else {
            return floor($seconds / 3600) . '小时';
        }
    }
    
    /**
     * 处理后台操作
     */
    public function handle_admin_actions() {
        // 验证nonce
        if (!isset($_POST['wprq_nonce']) || !wp_verify_nonce($_POST['wprq_nonce'], 'wprq_admin_action')) {
            wp_die('安全验证失败');
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $action = isset($_POST['wprq_action']) ? sanitize_text_field($_POST['wprq_action']) : '';
        
        switch ($action) {
            case 'process_queue':
                WPRQ_Request_Queue::get_instance()->process_queue();
                wp_redirect(admin_url('admin.php?page=wp-request-queue-monitor&processed=1'));
                exit;
                
            case 'cleanup_expired':
                WPRQ_Request_Queue::get_instance()->cleanup_expired();
                wp_redirect(admin_url('admin.php?page=wp-request-queue-monitor&cleaned=1'));
                exit;
                
            case 'add_whitelist':
                if (!empty($_POST['visitor_id'])) {
                    $visitor_id = sanitize_text_field($_POST['visitor_id']);
                    WPRQ_Whitelist_Manager::get_instance()->manual_add($visitor_id);
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-whitelist&added=1'));
                exit;
                
            case 'remove_whitelist':
                if (!empty($_POST['visitor_id'])) {
                    $visitor_id = sanitize_text_field($_POST['visitor_id']);
                    WPRQ_Whitelist_Manager::get_instance()->manual_remove($visitor_id);
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-whitelist&removed=1'));
                exit;
                
            case 'block_ip':
                if (!empty($_POST['ip_address'])) {
                    $ip = sanitize_text_field($_POST['ip_address']);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        WPRQ_CC_Defense::get_instance()->block_ip($ip, '手动封禁');
                    }
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-blocked&blocked=1'));
                exit;
                
            case 'unblock_ip':
                if (!empty($_POST['ip_address'])) {
                    $ip = sanitize_text_field($_POST['ip_address']);
                    WPRQ_CC_Defense::get_instance()->unblock_ip($ip);
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-blocked&unblocked=1'));
                exit;
                
            case 'add_whitelist_ip':
                if (!empty($_POST['ip_address'])) {
                    $ip = sanitize_text_field($_POST['ip_address']);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        WPRQ_CC_Defense::get_instance()->add_ip_to_whitelist($ip);
                    }
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-blocked&added=1'));
                exit;
                
            case 'remove_whitelist_ip':
                if (!empty($_POST['ip_address'])) {
                    $ip = sanitize_text_field($_POST['ip_address']);
                    WPRQ_CC_Defense::get_instance()->remove_ip_from_whitelist($ip);
                }
                wp_redirect(admin_url('admin.php?page=wp-request-queue-blocked&removed=1'));
                exit;
                
            case 'reset_stats':
                WPRQ_CC_Defense::get_instance()->reset_stats();
                wp_redirect(admin_url('admin.php?page=wp-request-queue-logs&reset=1'));
                exit;
        }
    }
    
    /**
     * 渲染设置区块描述
     */
    public function render_general_section() {
        echo '<p>配置请求队列的基本参数</p>';
    }
    
    public function render_waiting_page_section() {
        echo '<p>自定义用户在队列等待时看到的页面内容</p>';
    }
    
    public function render_cc_section() {
        echo '<p>配置CC攻击防御参数</p>';
    }
    
    /**
     * 渲染复选框字段
     */
    public function render_checkbox_field($args) {
        $value = get_option($args['name'], 0);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($args['name']); ?>" value="1" <?php checked($value, 1); ?>>
            <?php echo esc_html($args['label']); ?>
        </label>
        <?php
    }
    
    /**
     * 渲染数字字段
     */
    public function render_number_field($args) {
        $value = get_option($args['name'], $args['min']);
        ?>
        <input type="number" 
               name="<?php echo esc_attr($args['name']); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               min="<?php echo esc_attr($args['min']); ?>" 
               max="<?php echo esc_attr($args['max']); ?>" 
               class="small-text">
        <?php
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * 渲染编辑器字段
     */
    public function render_editor_field($args) {
        $value = get_option($args['name'], '');
        ?>
        <textarea name="<?php echo esc_attr($args['name']); ?>" rows="10" class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <?php
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * 渲染逐行文本字段
     */
    public function render_textarea_list_field($args) {
        $value = get_option($args['name'], array());

        if (is_array($value)) {
            $value = implode("\n", $value);
        }
        ?>
        <textarea name="<?php echo esc_attr($args['name']); ?>" rows="6" class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <?php
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * 设置项范围校验
     */
    public function sanitize_checkbox($value) {
        return empty($value) ? 0 : 1;
    }

    public function sanitize_requests_per_second($value) {
        return $this->clamp_int($value, 1, 1000);
    }

    public function sanitize_whitelist_threshold($value) {
        return $this->clamp_int($value, 1, 100);
    }

    public function sanitize_queue_timeout($value) {
        return $this->clamp_int($value, 60, 3600);
    }

    public function sanitize_max_requests_per_minute($value) {
        return $this->clamp_int($value, 10, 10000);
    }

    public function sanitize_max_requests_per_second($value) {
        return $this->clamp_int($value, 1, 100);
    }

    private function clamp_int($value, $min, $max) {
        return max($min, min($max, absint($value)));
    }

    public function sanitize_trusted_proxies($value) {
        if (is_array($value)) {
            $lines = $value;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        }

        $trusted_proxies = array();

        foreach ($lines as $line) {
            $line = trim(sanitize_text_field(wp_unslash($line)));

            if ($line === '') {
                continue;
            }

            if (strpos($line, '/') === false) {
                if (filter_var($line, FILTER_VALIDATE_IP)) {
                    $trusted_proxies[] = $line;
                }
                continue;
            }

            list($ip, $mask) = explode('/', $line, 2);
            $mask = absint($mask);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
                $trusted_proxies[] = $ip . '/' . $mask;
            }
        }

        return array_values(array_unique($trusted_proxies));
    }
    
    /**
     * 加载后台资源
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wp-request-queue') === false) {
            return;
        }
        
        wp_enqueue_style('wprq-admin-style', WPRQ_PLUGIN_URL . 'assets/css/admin-style.css', array(), WPRQ_VERSION);
        wp_enqueue_script('wprq-admin-script', WPRQ_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery'), WPRQ_VERSION, true);
        
        wp_localize_script('wprq-admin-script', 'wprqAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wprq_admin_nonce')
        ));
    }
}
