/**
 * WP Request Queue - 后台脚本
 */

(function($) {
    'use strict';

    // 后台管理对象
    const WPRQAdmin = {
        // 配置
        config: null,

        /**
         * 初始化
         */
        init: function() {
            this.config = typeof wprqAdmin !== 'undefined' ? wprqAdmin : {};
            this.bindEvents();
            this.initTooltips();
            this.initConfirmations();
            this.autoRefreshMonitor();
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            // 表单提交验证
            $('form').on('submit', this.handleFormSubmit.bind(this));

            // 实时搜索过滤
            $('#wprq-search').on('input', this.handleSearch.bind(this));

            // 批量操作
            $('#wprq-select-all').on('change', this.handleSelectAll.bind(this));
            $('.wprq-batch-action').on('click', this.handleBatchAction.bind(this));

            // 刷新按钮
            $('.wprq-refresh').on('click', this.handleRefresh.bind(this));

            // IP地址验证
            $('input[name="ip_address"]').on('blur', this.validateIPAddress.bind(this));

            // 数字输入验证
            $('input[type="number"]').on('change', this.validateNumberInput.bind(this));
        },

        /**
         * 初始化工具提示
         */
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                $(this).addClass('wprq-tooltip');
            });
        },

        /**
         * 初始化确认对话框
         */
        initConfirmations: function() {
            $('[data-confirm]').on('click', function(e) {
                const message = $(this).data('confirm') || '确定执行此操作？';
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            });
        },

        /**
         * 自动刷新监控页面
         */
        autoRefreshMonitor: function() {
            // 仅在监控页面启用自动刷新
            if (window.location.href.indexOf('wp-request-queue-monitor') === -1) {
                return;
            }

            // 每30秒刷新一次
            setInterval(function() {
                // 使用AJAX刷新统计数据
                $.ajax({
                    url: this.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wprq_get_stats',
                        nonce: this.config.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            this.updateStats(response.data);
                        }
                    }.bind(this)
                });
            }.bind(this), 30000);
        },

        /**
         * 更新统计数据
         */
        updateStats: function(stats) {
            if (stats.queue) {
                $('.wprq-stat-card').each(function() {
                    const $card = $(this);
                    const label = $card.find('h3').text().trim();
                    const $number = $card.find('.wprq-stat-number');

                    switch (label) {
                        case '等待中':
                            $number.text(stats.queue.waiting || 0);
                            break;
                        case '处理中':
                            $number.text(stats.queue.processing || 0);
                            break;
                        case '已完成':
                            $number.text(stats.queue.completed || 0);
                            break;
                        case '已过期':
                            $number.text(stats.queue.expired || 0);
                            break;
                    }
                });
            }
        },

        /**
         * 处理表单提交
         */
        handleFormSubmit: function(e) {
            const $form = $(e.target);
            const action = $form.find('input[name="wprq_action"]').val();

            // 验证必填字段
            let isValid = true;
            $form.find('[required]').each(function() {
                if (!$(this).val()) {
                    isValid = false;
                    $(this).addClass('error');
                    $(this).after('<span class="wprq-error">此字段为必填项</span>');
                } else {
                    $(this).removeClass('error');
                    $(this).next('.wprq-error').remove();
                }
            });

            if (!isValid) {
                e.preventDefault();
                return false;
            }

            // 显示加载状态
            const $submitBtn = $form.find('[type="submit"]');
            const originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).html('<span class="wprq-loading"></span> 处理中...');

            // 恢复按钮状态（防止页面未跳转时按钮一直loading）
            setTimeout(function() {
                $submitBtn.prop('disabled', false).text(originalText);
            }, 5000);
        },

        /**
         * 处理搜索
         */
        handleSearch: function(e) {
            const searchTerm = $(e.target).val().toLowerCase();
            
            $('table tbody tr').each(function() {
                const $row = $(this);
                const text = $row.text().toLowerCase();
                
                if (text.indexOf(searchTerm) === -1) {
                    $row.hide();
                } else {
                    $row.show();
                }
            });
        },

        /**
         * 处理全选
         */
        handleSelectAll: function(e) {
            const isChecked = $(e.target).is(':checked');
            $('input[name="selected[]"]').prop('checked', isChecked);
        },

        /**
         * 处理批量操作
         */
        handleBatchAction: function(e) {
            e.preventDefault();
            
            const action = $(e.target).data('action');
            const selected = $('input[name="selected[]"]:checked').map(function() {
                return $(this).val();
            }).get();

            if (selected.length === 0) {
                alert('请至少选择一项');
                return;
            }

            const message = '确定要对选中的 ' + selected.length + ' 项执行此操作？';
            if (!confirm(message)) {
                return;
            }

            // 执行批量操作
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wprq_batch_action',
                    batch_action: action,
                    items: selected,
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showNotice('操作成功', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        this.showNotice('操作失败: ' + (response.data || '未知错误'), 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showNotice('网络错误，请重试', 'error');
                }.bind(this)
            });
        },

        /**
         * 处理刷新
         */
        handleRefresh: function(e) {
            e.preventDefault();
            location.reload();
        },

        /**
         * 验证IP地址
         */
        validateIPAddress: function(e) {
            const $input = $(e.target);
            const ip = $input.val().trim();
            
            if (ip && !this.isValidIP(ip)) {
                $input.addClass('error');
                $input.next('.wprq-error').remove();
                $input.after('<span class="wprq-error" style="color: #dc3545; font-size: 12px;">请输入有效的IP地址</span>');
                return false;
            } else {
                $input.removeClass('error');
                $input.next('.wprq-error').remove();
                return true;
            }
        },

        /**
         * 验证IP地址格式
         */
        isValidIP: function(ip) {
            const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
            const ipv6Regex = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
            
            if (ipv4Regex.test(ip)) {
                const parts = ip.split('.');
                return parts.every(function(part) {
                    const num = parseInt(part, 10);
                    return num >= 0 && num <= 255;
                });
            }
            
            return ipv6Regex.test(ip);
        },

        /**
         * 验证数字输入
         */
        validateNumberInput: function(e) {
            const $input = $(e.target);
            const value = parseInt($input.val(), 10);
            const min = parseInt($input.attr('min'), 10);
            const max = parseInt($input.attr('max'), 10);
            
            if (isNaN(value)) {
                $input.val($input.data('default') || 0);
                return;
            }
            
            if (!isNaN(min) && value < min) {
                $input.val(min);
            }
            
            if (!isNaN(max) && value > max) {
                $input.val(max);
            }
        },

        /**
         * 显示通知
         */
        showNotice: function(message, type) {
            // 移除现有通知
            $('.wprq-admin-notice').remove();

            // 创建通知元素
            const $notice = $('<div class="notice wprq-admin-notice is-dismissible"></div>');
            
            switch (type) {
                case 'success':
                    $notice.addClass('notice-success');
                    break;
                case 'error':
                    $notice.addClass('notice-error');
                    break;
                case 'warning':
                    $notice.addClass('notice-warning');
                    break;
                default:
                    $notice.addClass('notice-info');
            }

            $notice.html('<p>' + message + '</p>');

            // 添加到页面
            $('.wrap h1').first().after($notice);

            // 3秒后自动消失
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * 格式化数字
         */
        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        /**
         * 格式化时间
         */
        formatTime: function(seconds) {
            if (seconds < 60) {
                return seconds + '秒';
            } else if (seconds < 3600) {
                return Math.floor(seconds / 60) + '分钟';
            } else {
                return Math.floor(seconds / 3600) + '小时';
            }
        }
    };

    // 页面加载完成后初始化
    $(document).ready(function() {
        WPRQAdmin.init();
    });

})(jQuery);
