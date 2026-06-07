=== WP Request Queue ===
Contributors: wp-request-queue
Tags: security, queue, cc-attack, rate-limit, protection
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

== Installation ==

1. 下载插件并解压到 `/wp-content/plugins/wp-request-queue` 目录
2. 在WordPress后台"插件"页面激活插件
3. 前往"设置 > 请求队列"配置插件参数
4. 根据需要自定义等待页面内容

== Screenshots ==

1. 后台设置页面
2. 队列监控界面
3. 白名单管理
4. IP封禁管理
5. 攻击日志
6. 等待页面效果

如有问题或建议，请访问 [GitHub](https://github.com/fanhuacg2004/wp-request-queue) 提交issue。

