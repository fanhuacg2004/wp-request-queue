=== WP Request Queue ===
Contributors: wp-request-queue
Tags: security, queue, cc-attack, rate-limit, protection
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

高并发请求队列管理和CC攻击防御插件，保护网站免受恶意流量攻击。

== Description ==

WP Request Queue 是一款功能强大的WordPress安全插件，专门用于处理高并发请求和防御CC攻击。通过智能队列管理和白名单机制，有效保护您的网站免受恶意流量冲击。

= 主要功能 =

* **请求队列管理** - 对高并发请求进行排队处理，确保服务器稳定运行
* **白名单机制** - 正常访客自动加入白名单，无需重复排队
* **CC攻击防御** - 智能检测并阻止恶意请求
* **IP封禁管理** - 自动封禁异常IP，支持手动管理
* **实时监控** - 后台可视化监控队列状态和攻击情况
* **自定义等待页面** - 可自定义用户等待时看到的页面内容
* **响应式设计** - 等待页面完美适配各种设备

= 工作原理 =

1. 用户访问网站时，插件会检查请求频率
2. 正常访客首次访问成功后自动加入白名单
3. 白名单用户可直接访问，无需排队
4. 如果白名单用户请求过于频繁，将被移出白名单
5. 非白名单用户将进入队列等待
6. 恶意请求将被自动识别并阻止

= 适用场景 =

* 电商网站秒杀活动
* 限时抢购页面
* 高流量博客
* 需要防CC攻击的网站
* 任何需要流量控制的场景

== Installation ==

1. 下载插件并解压到 `/wp-content/plugins/wp-request-queue` 目录
2. 在WordPress后台"插件"页面激活插件
3. 前往"设置 > 请求队列"配置插件参数
4. 根据需要自定义等待页面内容

== Frequently Asked Questions ==

= 插件会影响网站速度吗？=

插件经过优化，对正常访客几乎无影响。白名单机制确保正常用户无需重复排队。

= 如何自定义等待页面？=

在后台设置页面，您可以自定义等待页面的HTML内容。留空则使用默认的加载动画页面。

= 被封禁的IP如何解封？=

在"IP封禁管理"页面，您可以查看所有被封禁的IP，并手动解封。

= 插件支持多语言吗？=

是的，插件支持国际化。语言文件位于 `languages` 目录。

== Screenshots ==

1. 后台设置页面
2. 队列监控界面
3. 白名单管理
4. IP封禁管理
5. 攻击日志
6. 等待页面效果

== Changelog ==

= 1.0.0 =
* 首次发布
* 实现请求队列管理
* 实现白名单机制
* 实现CC攻击防御
* 实现后台监控界面
* 实现自定义等待页面

== Upgrade Notice ==

= 1.0.0 =
首次发布，建议所有用户安装。

== Arbitrary section ==

= 技术支持 =

如有问题或建议，请访问 [GitHub](https://github.com/wp-request-queue) 提交issue。

= 捐赠支持 =

如果您觉得这个插件对您有帮助，欢迎[捐赠支持](https://github.com/wp-request-queue/donate)我们的开发工作。
