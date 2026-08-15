# WPStow 1.9.0

WPStow 是面向 WordPress 的云端媒体插件，支持聚合图床（Superbed）、OneImg API、S3 兼容对象存储（包括 Cloudflare R2）、WebDAV 和 FTP/FTPS。

项目主页：<https://github.com/hicocos/wpstow>

插件兼容标准 WordPress 主题，并在后台提供统一的“WPStow 设置”页面，配置保存在 `wpstow_setting` option 中。设置页使用 CSF；主题或其他插件已提供 CSF 时直接复用，否则加载 WPStow 内置版本，界面与配置结构保持一致。

## 工作流程

```text
浏览器上传
  → WordPress 临时文件
  → 图片/视频处理（可选）
  → WordPress 生成元数据与缩略图
  → 按图片/视频/音频/其他分类选择存储源
  → WPStow 上传主文件和全部缩略图（选择“仅本地”时跳过）
  → 全部成功：登记云端状态；按设置保留本地副本（1.2 默认保留）
  → 任一失败：保留本地文件并显示可重试错误
```

## R2 路径示例

```text
Object Key: 2026/08/example.jpg
```

私有桶代理 URL：

```text
https://example.com/wp-admin/admin-ajax.php?action=wpstow_proxy&file=2026%2F08%2Fexample.jpg&attachment_id=123
```

这里的 `admin-ajax.php` 只是 WordPress 代理入口，图片不在 `wp-admin` 目录。服务器会签名读取 R2 对象并返回给浏览器。

配置公开 CDN 或 R2 自定义域名后，可以填写“自定义访问 URL”，媒体将直接使用：

```text
https://img.example.com/2026/08/example.jpg
```

## 环境要求

- WordPress 6.0+
- PHP 7.4+
- PHP cURL、JSON
- 推荐 Fileinfo
- FTP 后端需要 PHP FTP 扩展
- 视频处理需要 `ffmpeg`、`ffprobe`，并允许 PHP 使用 `exec`

## Cloudflare R2 配置

- Endpoint：R2 S3 API Endpoint，不包含 Bucket 名
- Access Key / Secret Key：R2 API Token 的 S3 凭据
- Bucket：目标桶名称
- Region：`auto`
- 路径样式：通常使用虚拟主机样式
- 自定义访问 URL：可选；填 R2 自定义域名或 CDN 根地址

配置后先点击“测试连接”，再启用自动转存并保存。

## 分类存储路由

“存储配置”可以为四类附件分别选择存储源：

- 图片：聚合图床、OneImg、S3/R2、WebDAV、FTP/FTPS 或仅本地
- 视频：S3/R2、WebDAV、FTP/FTPS 或仅本地
- 音频：S3/R2、WebDAV、FTP/FTPS 或仅本地
- 其他：S3/R2、WebDAV、FTP/FTPS 或仅本地，包括 PDF、压缩包和文档等附件

“当前配置服务”只控制下方显示哪一组连接参数，不会改变分类路由。切换服务后，其他服务已经保存的凭据会继续保留。附件上传成功后会记录实际使用的存储源，之后即使修改分类路由，已有附件仍从原存储源读取和删除。

## 媒体库统一接管

“媒体接管”页面可以按全部、图片、视频、音频或其他文件扫描现有媒体库，并将附件归类为：

- 已接管
- 可接管或上次失败
- 正在处理
- 仅本地路由
- 源文件缺失
- 配置不可用

扫描采用只读分页，不会上传或修改附件。点击“接管可处理项”后会创建服务器持久任务；任务游标、统计、重试时间和租约均保存在独立数据库表中，浏览器刷新或关闭不会丢失进度。后台可以暂停、继续或取消任务，正在上传的附件会先安全结束。

队列由 WordPress Cron 驱动，附件始终串行处理；一次工作器调用在 20 秒预算内最多连续处理 3 个附件，大文件不会并发挤占服务器资源。单个附件失败会按 60 秒、300 秒退避，最多尝试 3 次；连续失败后记录错误并继续下一项。每分钟看门狗会恢复因 PHP 进程中断或租约超时而搁置的任务；工作器自身连续异常 3 次时会自动暂停，避免无限调度。低流量站点建议配置系统 Cron 定期请求 `wp-cron.php`，确保任务不依赖前台访问触发。

批量接管与媒体库单文件处理共用附件级锁，避免重复上传。上传仍遵守完整事务：主文件和全部缩略图均成功后才写入接管状态；任一对象失败都会回滚本轮远端对象并保留本地文件。若配置为“上传后删除”本地副本，执行前会再次确认。

## 原图单文件模式

在“图片处理”中启用“原图单文件模式”后，新上传图片不会生成 WordPress 的 `thumbnail`、`medium`、`large`、主题/插件自定义尺寸，也不会产生 `-scaled`、`-rotated` 或自动格式转换文件。附件元数据仍会保留原图宽高等信息，媒体库和前台在请求缩略尺寸时会回退使用原图。

该设置只影响启用后新上传或重新生成尺寸的图片，不会自动删除已有附件的历史缩略图。图片压缩和水印是独立功能；若启用，它们仍会修改唯一的上传文件。

## OneImg API 配置

- Endpoint：OneImg 站点根地址，例如 `https://img.example.com`，不要附加 `/api`
- API Token：OneImg 后台生成的 API Token；插件通过 `Authorization: oneimg_token=...` 调用接口
- OneImg 仅接收图片，因此不会出现在视频、音频和其他文件的存储选项中
- WordPress 原图和每个缩略图会分别成为 OneImg 图片记录，删除附件时按 OneImg 图片 ID 同步清理
- OneImg 返回的图片地址由浏览器直接访问，不经过 WordPress/PHP 图片代理
- OneImg 可能按服务端策略把 PNG/JPEG 转码为 WebP；代理读取时会采用远端返回的实际 MIME 类型

配置后先点击“测试连接”，确认 API 鉴权和目标存储源，再执行“上传自检”。

## 聚合图床配置

- API 地址：默认 `https://api.superbed.cc`，通常无需修改，不要附加 `/api/v1`
- API Key：聚合图床后台生成的 API Key；插件通过 `X-API-Key` 请求头鉴权，已保存的值不会在后台回显
- 目录 UUID：可选；留空上传到根目录，填写后上传到指定目录
- 聚合图床仅接收图片，因此不会出现在视频、音频和其他文件的存储选项中
- WordPress 原图和每个缩略图会分别上传；插件保存文件 UUID 与直链，删除附件时同步将远端文件移入回收站
- 聚合图床返回的图片地址由浏览器直接访问，不经过 WordPress/PHP 代理

配置后先点击“测试连接”，再执行“上传自检”。上传自检会创建一张临时图片并在成功后立即移入聚合图床回收站。

## 安全与数据保护

- 所有设置保存和后台 AJAX 都有权限与 nonce 校验。
- HTTPS 请求验证 TLS 证书和主机名。
- 日志不记录 Secret Key、API Token、Authorization 或签名。
- 只有完整上传成功后才删除本地文件。
- 默认使用“云端 + 本地”双副本；可在“媒体 URL 策略”中一键让已处理媒体使用云端链接或本地链接；私有代理读取云端失败时自动回退本地。
- 媒体库列表会醒目标识“未处理 / 等待处理 / 处理失败 / 已处理”，并提供单文件“立即处理”。
- S3/R2 凭据建议只授予指定 Bucket 的最小权限。
- 建议为 R2 凭据制定轮换计划。

## 故障排除

### 配置正确但仍走本地

确认“自动转存新上传”是“启用”。“测试连接成功”只证明凭据可用，不代表自动转存开关已打开。

### 显示 FFmpeg 不可用

检查网站使用的 PHP-FPM 配置，而不只是 CLI PHP：

```bash
php -r 'var_dump(function_exists("exec"));'
ffmpeg -version
ffprobe -version
```

### 私有代理较慢

私有代理会让图片流量经过 PHP。若媒体可公开访问，推荐绑定 R2 自定义域名/CDN，并填写“自定义访问 URL”。

### 对象在哪里

对象 Key 默认沿用 WordPress 相对上传路径：

```text
YYYY/MM/filename.ext
```

R2 控制台会把 `/` 前缀显示得像文件夹，但对象存储本质上保存的是完整 Key。

### 已处理媒体想切回本地链接

进入 WPStow 设置 → 存储配置 → “媒体 URL 策略”，选择“本地优先”并保存。插件会停止把仍有本地副本的已处理附件改写为云端 URL，覆盖媒体库网格/列表、编辑器插入、REST `source_url` 和 `srcset`；云端对象和处理状态仍保留，之后可一键切回“云端优先”。如果某个已处理附件已经没有本地副本，会自动继续使用云端链接，避免前台 404。

## 数据安全与备份

建议定期备份插件目录和 WordPress 数据库。设置保存在 `wpstow_setting` option 中；附件状态保存在 `_wpstow_uploaded`、`_wpstow_cloud_key`、`_wpstow_storage_type` 和 `_wpstow_storage_manifest` 等 post meta 中。持久任务保存在 `{prefix}_wpstow_media_jobs` 表中，完成或取消超过 30 天的历史任务会在新建任务时清理。批量接管使用的 `_wpstow_batch_lock`、`_wpstow_pending` 和 `_wpstow_pending_at` 是临时状态，任务结束后会自动清除，超过 15 分钟的锁会在下次接管时回收。

插件默认保留本地副本并启用云端读取失败回退。确认远端存储稳定后，可以在“存储配置”中按需调整副本与访问策略。

## 开发与发布

提交代码前，请运行：

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
for file in static/*.js; do node --check "$file"; done
```

推送 `v*` 标签后，GitHub Actions 会自动生成只包含生产文件的 `wpstow-<version>.zip` 并创建 Release。

## 许可证

WPStow 自有代码使用 [MIT](LICENSE)；内置 Codestar Framework 2.2.0 使用 [GPL-2.0](vendor/codestar-framework/LICENSE.md)。
