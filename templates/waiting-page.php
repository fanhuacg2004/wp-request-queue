<?php
/**
 * 等待页面模板
 */
defined('ABSPATH') || exit;

// 获取网站信息
$site_name = get_bloginfo('name');
$site_url = home_url();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($site_name); ?> - 请稍候</title>
    <link rel="stylesheet" href="<?php echo esc_url(WPRQ_PLUGIN_URL . 'assets/css/waiting-page.css'); ?>">
    <style>
        :root {
            --wprq-primary: #667eea;
            --wprq-secondary: #764ba2;
            --wprq-text: #333;
            --wprq-text-light: #666;
            --wprq-bg: #f5f5f5;
            --wprq-card-bg: #ffffff;
            --wprq-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
    <div class="wprq-waiting-container">
        <?php if (!empty($custom_html)): ?>
            <?php echo wp_kses_post($custom_html); ?>
        <?php else: ?>
            <div class="wprq-default-content">
                <!-- 加载动画 -->
                <div class="wprq-spinner-container">
                    <div class="wprq-spinner"></div>
                    <div class="wprq-spinner-inner"></div>
                </div>
                
                <!-- 网站名称 -->
                <h1 class="wprq-site-name"><?php echo esc_html($site_name); ?></h1>
                
                <!-- 状态信息 -->
                <div class="wprq-status-message">
                    <p class="wprq-main-text">网站正在处理中...</p>
                    <p class="wprq-sub-text">请耐心等待，我们将尽快为您加载页面</p>
                </div>
                
                <!-- 队列信息 -->
                <div class="wprq-queue-info">
                    <div class="wprq-info-item">
                        <span class="wprq-info-label">队列位置</span>
                        <span class="wprq-info-value" id="wprq-position"><?php echo esc_html($position); ?></span>
                    </div>
                    <div class="wprq-info-divider"></div>
                    <div class="wprq-info-item">
                        <span class="wprq-info-label">预计等待</span>
                        <span class="wprq-info-value" id="wprq-wait-time"><?php echo esc_html($wait_time); ?>秒</span>
                    </div>
                </div>
                
                <!-- 进度条 -->
                <div class="wprq-progress-container">
                    <div class="wprq-progress-bar">
                        <div class="wprq-progress-fill" id="wprq-progress"></div>
                        <div class="wprq-progress-glow"></div>
                    </div>
                    <div class="wprq-progress-text">
                        <span id="wprq-progress-percent">0</span>% 完成
                    </div>
                </div>
                
                <!-- 提示信息 -->
                <div class="wprq-tips">
                    <p>💡 页面将自动刷新，无需手动操作</p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- 底部信息 -->
        <div class="wprq-footer">
            <p>由 <a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_name); ?></a> 提供服务</p>
        </div>
    </div>
    
    <script>
        var wprqConfig = <?php echo wp_json_encode(array(
            'visitorId'     => $visitor_id,
            'queueToken'    => $queue_token,
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('wprq_queue_nonce'),
            'checkInterval' => 2000,
            'siteName'      => $site_name,
            'siteUrl'       => $site_url,
        )); ?>;
    </script>
    <script src="<?php echo esc_url(WPRQ_PLUGIN_URL . 'assets/js/queue-handler.js'); ?>"></script>
</body>
</html>
