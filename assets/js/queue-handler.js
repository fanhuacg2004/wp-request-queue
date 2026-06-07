/**
 * WP Request Queue - 前端队列处理
 */

(function() {
    'use strict';

    // 队列处理器对象
    const WPRQQueueHandler = {
        // 配置
        config: null,
        
        // 定时器
        checkTimer: null,
        
        // 进度
        progress: 0,
        
        // 重试次数
        retryCount: 0,
        
        // 最大重试次数
        maxRetries: 30,
        
        // 状态元素缓存
        elements: {},

        /**
         * 初始化
         */
        init: function(config) {
            this.config = config;
            this.cacheElements();
            this.startPolling();
            this.startProgressAnimation();
            this.bindEvents();
        },

        /**
         * 缓存DOM元素
         */
        cacheElements: function() {
            this.elements = {
                position: document.getElementById('wprq-position'),
                waitTime: document.getElementById('wprq-wait-time'),
                progress: document.getElementById('wprq-progress'),
                progressPercent: document.getElementById('wprq-progress-percent')
            };
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            // 页面可见性变化时重新开始轮询
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.checkStatus();
                }
            });

            // 网络恢复时重新检查
            window.addEventListener('online', () => {
                this.retryCount = 0;
                this.checkStatus();
            });

            // 网络断开时暂停轮询
            window.addEventListener('offline', () => {
                this.stopPolling();
                this.showMessage('网络连接已断开，请检查网络...', 'warning');
            });
        },

        /**
         * 开始轮询
         */
        startPolling: function() {
            // 立即检查一次
            this.checkStatus();
            
            // 设置定时轮询
            this.checkTimer = setInterval(() => {
                this.checkStatus();
            }, this.config.checkInterval || 2000);
        },

        /**
         * 停止轮询
         */
        stopPolling: function() {
            if (this.checkTimer) {
                clearInterval(this.checkTimer);
                this.checkTimer = null;
            }
        },

        /**
         * 检查状态
         */
        checkStatus: function() {
            // 检查重试次数
            if (this.retryCount >= this.maxRetries) {
                this.stopPolling();
                this.showMessage('服务器响应超时，请刷新页面重试', 'error');
                return;
            }

            // 构建请求数据
            const data = new FormData();
            data.append('action', 'wprq_check_status');
            data.append('visitor_id', this.config.visitorId);
            data.append('queue_token', this.config.queueToken);
            data.append('nonce', this.config.nonce);

            // 发送请求
            fetch(this.config.ajaxUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(result => {
                this.retryCount = 0; // 重置重试次数
                
                if (result.success) {
                    this.handleStatusUpdate(result.data);
                } else {
                    console.error('Queue check failed:', result.data);
                }
            })
            .catch(error => {
                this.retryCount++;
                console.error('Queue check error:', error);
                
                // 显示网络错误提示（仅在连续失败时）
                if (this.retryCount >= 5) {
                    this.showMessage('网络连接不稳定，正在重试...', 'warning');
                }
            });
        },

        /**
         * 处理状态更新
         */
        handleStatusUpdate: function(data) {
            switch (data.status) {
                case 'whitelisted':
                case 'completed':
                case 'ready':
                    this.handleCompleted();
                    break;
                    
                case 'waiting':
                    this.handleWaiting(data);
                    break;
                    
                case 'processing':
                    this.handleProcessing(data);
                    break;
                    
                default:
                    // 未知状态，继续轮询
                    break;
            }
        },

        /**
         * 处理等待状态
         */
        handleWaiting: function(data) {
            // 更新位置
            if (this.elements.position) {
                this.elements.position.textContent = data.position || '-';
            }
            
            // 更新等待时间
            if (this.elements.waitTime) {
                const waitTime = data.estimated_time || 0;
                this.elements.waitTime.textContent = waitTime > 0 ? waitTime + '秒' : '计算中...';
            }
            
            // 更新进度
            if (data.position) {
                this.updateProgress(data.position);
            }
        },

        /**
         * 处理处理中状态
         */
        handleProcessing: function(data) {
            // 更新UI显示处理中状态
            if (this.elements.position) {
                this.elements.position.textContent = '处理中';
            }
            
            if (this.elements.waitTime) {
                this.elements.waitTime.textContent = '即将完成';
            }
            
            // 设置进度接近完成
            this.setProgress(90);
        },

        /**
         * 处理完成状态
         */
        handleCompleted: function() {
            // 停止轮询
            this.stopPolling();
            
            // 设置进度为100%
            this.setProgress(100);
            
            // 显示成功消息
            this.showMessage('处理完成，正在跳转...', 'success');
            
            // 延迟后刷新页面
            setTimeout(() => {
                window.location.reload();
            }, 500);
        },

        /**
         * 更新进度
         */
        updateProgress: function(position) {
            if (!position || position <= 0) {
                return;
            }
            
            // 根据位置计算进度（假设最大队列为100）
            const maxQueue = 100;
            const progress = Math.min(95, Math.max(5, 100 - (position / maxQueue * 100)));
            this.setProgress(progress);
        },

        /**
         * 设置进度
         */
        setProgress: function(percent) {
            this.progress = Math.min(100, Math.max(0, percent));
            
            if (this.elements.progress) {
                this.elements.progress.style.width = this.progress + '%';
            }
            
            if (this.elements.progressPercent) {
                this.elements.progressPercent.textContent = Math.round(this.progress);
            }
        },

        /**
         * 开始进度动画
         */
        startProgressAnimation: function() {
            // 初始进度
            this.setProgress(10);
            
            // 缓慢增长动画
            const animateProgress = () => {
                if (this.progress < 90 && this.checkTimer) {
                    const increment = Math.random() * 2;
                    this.setProgress(this.progress + increment);
                }
                requestAnimationFrame(animateProgress);
            };
            
            requestAnimationFrame(animateProgress);
        },

        /**
         * 显示消息
         */
        showMessage: function(message, type) {
            // 移除现有消息
            const existingMessage = document.querySelector('.wprq-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            // 创建消息元素
            const messageEl = document.createElement('div');
            messageEl.className = 'wprq-message wprq-message-' + (type || 'info');
            messageEl.textContent = message;
            
            // 样式
            messageEl.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                z-index: 10000;
                animation: wprq-message-in 0.3s ease-out;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            `;
            
            // 根据类型设置颜色
            switch (type) {
                case 'success':
                    messageEl.style.background = '#d4edda';
                    messageEl.style.color = '#155724';
                    messageEl.style.border = '1px solid #c3e6cb';
                    break;
                case 'error':
                    messageEl.style.background = '#f8d7da';
                    messageEl.style.color = '#721c24';
                    messageEl.style.border = '1px solid #f5c6cb';
                    break;
                case 'warning':
                    messageEl.style.background = '#fff3cd';
                    messageEl.style.color = '#856404';
                    messageEl.style.border = '1px solid #ffeaa7';
                    break;
                default:
                    messageEl.style.background = '#cce5ff';
                    messageEl.style.color = '#004085';
                    messageEl.style.border = '1px solid #b8daff';
            }
            
            // 添加到页面
            document.body.appendChild(messageEl);
            
            // 3秒后自动移除
            setTimeout(() => {
                if (messageEl.parentNode) {
                    messageEl.style.animation = 'wprq-message-out 0.3s ease-in';
                    setTimeout(() => messageEl.remove(), 300);
                }
            }, 3000);
        }
    };

    // 添加消息动画样式
    const style = document.createElement('style');
    style.textContent = `
        @keyframes wprq-message-in {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        @keyframes wprq-message-out {
            from {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
            to {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }
        }
    `;
    document.head.appendChild(style);

    // 初始化
    if (typeof wprqConfig !== 'undefined') {
        document.addEventListener('DOMContentLoaded', function() {
            WPRQQueueHandler.init(wprqConfig);
        });
    }
})();
