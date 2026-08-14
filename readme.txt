=== WPStow ===
Contributors: moepick
Tags: media, oneimg, s3, cloudflare-r2, webdav, ftp, image-optimization, ffmpeg
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: MIT
License URI: https://opensource.org/license/mit/

将 WordPress 媒体安全转存到 OneImg、S3 兼容对象存储、WebDAV 或 FTP，并支持图片优化和 FFmpeg 视频处理。

== Description ==

WPStow 在 WordPress 完成媒体处理后上传主文件和缩略图。只有全部必需对象上传成功后，才会删除本地副本；失败时会保留本地文件并记录可重试错误。

插件在 WordPress 后台提供统一的 WPStow 设置页面。

主要能力：

* S3 兼容存储：AWS S3、Cloudflare R2、MinIO 等
* OneImg API 图床（上传、直链访问、按图片 ID 删除）
* WebDAV、FTP/FTPS
* 图片、视频、音频和其他附件可分别选择存储源或仅保留本地
* 私有桶代理访问或自定义 CDN/公开域名直连
* 图片外链本地化、压缩、文字/图片水印
* FFmpeg 视频压缩、分辨率限制和视频水印
* 媒体库 URL、缩略图、srcset、REST 与编辑器适配
* 管理后台状态概览、连接测试、上传自检和脱敏日志
* 上传失败保护：未完整成功时绝不删除本地原件

== Installation ==

1. 上传插件目录到 `/wp-content/plugins/wpstow/`。
2. 在 WordPress 后台启用 WPStow。
3. 打开“WPStow 设置”。
4. 为图片、视频、音频和其他附件选择存储源。
5. 逐个选择使用中的存储服务并填写连接配置。
6. 先点击“测试连接”。
7. 将“自动转存新上传”设为“启用”，保存设置。
8. 上传测试文件，确认媒体 URL 和云端对象。

== Frequently Asked Questions ==

= 为什么配置了 S3，媒体仍在本地？ =

S3 配置和自动转存总开关是两件事。必须把“自动转存新上传”设置为“启用”。插件只在云端上传完整成功后删除本地副本。

= 为什么媒体 URL 是 admin-ajax.php？ =

未配置自定义访问 URL 时，插件使用私有桶代理：WordPress 在服务器端签名并读取对象。若桶已通过 R2 自定义域名或 CDN 公开，可填写“S3 自定义访问 URL”以让浏览器直接访问云端。

= S3 对象路径如何组成？ =

默认沿用 WordPress 的年月目录，例如 `2026/08/example.jpg`。这是对象 Key，不是服务器真实文件夹。

= FFmpeg 已安装但显示不可用怎么办？ =

确认网站所用 PHP-FPM 允许 `exec`，并检查 `ffmpeg` 和 `ffprobe` 都可执行。CLI PHP 与网站 PHP-FPM 可能读取不同配置。

= 上传失败会删除本地文件吗？ =

不会。只有主文件和全部必需缩略图都上传成功，插件才提交云端状态并删除本地副本。

== Privacy ==

WPStow 会把媒体文件发送到管理员配置的存储服务。存储凭据保存在 WordPress options 表中。插件日志不会记录密码、Secret Key、Authorization 请求头或请求签名。使用外链图片本地化时，服务器会访问文章中的外部图片 URL。

== Security Notes ==

* 建议使用 HTTPS Endpoint。
* S3/R2 凭据应遵循最小权限原则，只允许指定 Bucket 的读写删除。
* 私有桶使用代理访问；公开桶建议配置 CDN/自定义域名降低 PHP 带宽开销。
* 生产环境应限制插件日志目录的 Web 访问并定期轮换云存储凭据。

== Changelog ==

= 1.5.0 =
* 新增图片、视频、音频和其他附件四类独立存储路由，并支持仅本地。
* OneImg 仅出现在图片路由中，旧 OneImg 配置的非图片附件继续默认留在本地。
* 上传时记录附件实际使用的存储源，URL、代理读取和删除不再受当前后台选择影响。
* 存储连接配置可逐服务编辑和测试，切换服务时保留其他服务的凭据。
* 改进 OneImg 转码后的 MIME 响应，并兼容 WebDAV/FTP 年月目录自动创建。

= 1.4.0 =
* 新增 OneImg API 存储后端、隐藏 Token 配置和连接测试。
* 保存 WordPress 各图片尺寸对应的 OneImg 图片 ID 与直链，支持完整删除和失败回滚。
* OneImg 图片直链由浏览器直接访问，不经过 WordPress/PHP 代理。

= 1.3.0 =
* 后台设置统一迁移到 CSF 页面，移除旧设置页面。
* 保留原有 `wpstow_setting` 数据结构，无需迁移已有配置。
* 密钥字段不再回显，留空保存时保留原值。
* 保留连接测试、上传自检、日志清理和水印媒体选择。
* 完善设置框架可用性检查以及桌面端与移动端后台布局。

= 1.2.0 =
* 媒体库列表新增 WPStow 处理状态和单文件立即处理按钮。
* 新增本地与云端双副本选项，升级后默认保留本地文件。
* 私有云代理新增云端失败时的本地副本安全回退。
* 上传完成后记录存储对象清单，便于可靠清理。

= 1.1.0 =
* 修复 S3 自动转存总开关与上传状态语义。
* 上传失败时保留本地文件，避免部分上传造成数据丢失。
* 支持非图片附件和手动迁移的真实结果反馈。
* 修复 S3 对象 Key 编码、Endpoint 路径和非默认端口签名。
* 修复 FFmpeg 检测与 ffprobe 执行。
* 加固 nonce、权限、TLS、日志和代理路径校验。
* 增加状态概览、响应式后台界面和未保存修改提示。

= 1.0.0 =
* 初始版本。
