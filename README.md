# WPStow 2.0.0

WPStow 是面向 WordPress 的云端媒体插件，支持聚合图床（Superbed）、OneImg API、S3 兼容对象存储、Cloudflare R2、WebDAV 和 FTP/FTPS。

项目主页：<https://github.com/hicocos/wpstow>

插件兼容标准 WordPress 主题，并在后台提供统一的“WPStow 设置”页面，配置保存在 `wpstow_setting` option 中。设置页使用 CSF；主题或其他插件已提供 CSF 时直接复用，否则加载 WPStow 内置版本，界面与配置结构保持一致。

## 2.0.0 更新要点

- 将通用 S3 与 Cloudflare R2 拆分为独立后端，并按附件记录实际存储目标，避免切换配置后访问或删除新桶中的同名对象。
- 新增 S3/R2 浏览器直传、Multipart 分片上传、失败自动回退服务器流式上传，以及 R2 私有桶预签名 302 直连。
- 新增云端删除持久重试队列，并加固 AJAX 权限、nonce、对象 Key、外链图片 SSRF、凭据回显和本地文件删除边界。
- 内置 Codestar Framework 更新至 2.3.1，并兼容复用 Panda/Zibll 主题提供的 CSF。

## 工作流程

```text
S3/R2 浏览器直传（本地副本设为“上传后删除”，且文件不需要服务器处理）
  → WordPress 生成短期签名，不接触文件内容
  → 小文件直接 PUT；超过 16 MiB 使用 Multipart、3 路并发和分片重试
  → WordPress HEAD 校验对象大小和 MIME，并抽样读取真实内容、校验图片尺寸后登记媒体附件
  → 直传失败：原文件自动交回 WordPress，进入下方服务器兜底路径

服务器上传（直传兜底、需要处理的媒体和其他存储后端）
  → WordPress 临时文件
  → 图片/视频处理（可选）
  → WordPress 生成元数据与缩略图
  → 按图片/视频/音频/其他分类选择存储源
  → WPStow 以文件流上传主文件和全部缩略图（选择“仅本地”时跳过）
  → 全部成功：登记云端状态；按设置保留本地副本（1.2 默认保留）
  → 任一失败：保留本地文件并显示可重试错误
```

## S3/R2 浏览器直传

浏览器直传只对通用 S3 和 Cloudflare R2 生效。启用条件为“本地副本”选择“上传后删除”，并且该文件不需要服务器生成缩略图、压缩或加水印。图片需要同时启用“原图单文件模式”；开启任一服务器处理能力时会自动使用原上传链路。

- 小于或等于 16 MiB：一次同时签署 `Content-Type` 的预签名 `PUT`
- 大于 16 MiB：S3 Multipart Upload，分片动态为 8 MiB～1 GiB，默认 3 路并发
- 每个分片指数退避重试最多 4 次；签名过期会刷新该分片 URL
- 上传会话最长 6 小时；取消、关闭页面或会话超时会尝试终止 Multipart
- 完成时由服务器验证用户权限、nonce、对象 Key、UploadId、大小和 MIME，并抽样读取真实内容
- 图片格式与尺寸以云端对象的实际字节为准，不信任浏览器上报值
- 任何阶段最终失败，浏览器都会把原始 `File` 交回 WordPress 原生上传器；服务器兜底上传使用流式 cURL，不把整个大文件读入 PHP 内存

Bucket 必须允许 WordPress 站点 Origin 跨域 `PUT`，并暴露 `ETag`，否则浏览器会自动回退服务器上传。示例规则：

```json
[
  {
    "AllowedOrigins": ["https://example.com"],
    "AllowedMethods": ["GET", "PUT", "POST", "DELETE", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

直传使用 S3 API Endpoint 和短期 SigV4 URL，与 Bucket 是否公开无关；Access Key 和 Secret Key 始终只保存在服务器。

## R2 私有桶访问链路

```text
Object Key: 2026/08/example.jpg
```

页面中使用的固定媒体 URL：

```text
https://example.com/wp-admin/admin-ajax.php?action=wpstow_proxy&file=2026%2F08%2Fexample.jpg&attachment_id=123
```

这里的 `admin-ajax.php` 只是固定链接解析入口，图片不在 `wp-admin` 目录。浏览器访问后，WPStow 会验证附件和对象 Key 的对应关系，生成短期 SigV4 预签名 URL，并返回 `302`：

```text
浏览器 → WPStow 固定 URL → 302 → R2 S3 API 预签名 URL → R2 返回文件
```

WordPress 只承担链接解析和签名，不读取或输出对象内容，因此 R2 桶不必开启 Public Access，文件流量也不经过本站 PHP。固定 WPStow URL 不过期；第二跳 R2 URL 默认 15 分钟过期。

如果 R2 桶本来就是公开桶，也可以填写“公开访问 URL”，媒体将直接使用：

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

- 在分类路由和“当前配置服务”中单独选择 `Cloudflare R2`，不要选择通用 S3
- Endpoint：R2 S3 API Endpoint，不包含 Bucket 名
- Access Key / Secret Key：R2 API Token 的 S3 凭据
- Bucket：目标桶名称
- Region 固定使用 `auto`，并使用路径样式请求 R2
- 临时签名有效期：默认 900 秒，可设置 60～604800 秒
- 公开访问 URL：私有桶留空；只有桶已公开时才填写 R2 自定义域名或 CDN 根地址

配置后先点击“测试连接”，再启用自动转存并保存。

从旧版本升级时，如果原 S3 Endpoint 是 `*.r2.cloudflarestorage.com`，插件会一次性把该配置、分类路由和既有附件归属迁移为独立 R2 后端。普通 S3 配置不会迁移。

## 分类存储路由

“存储配置”可以为四类附件分别选择存储源：

- 图片：聚合图床、OneImg、S3、Cloudflare R2、WebDAV、FTP/FTPS 或仅本地
- 视频：S3、Cloudflare R2、WebDAV、FTP/FTPS 或仅本地
- 音频：S3、Cloudflare R2、WebDAV、FTP/FTPS 或仅本地
- 其他：S3、Cloudflare R2、WebDAV、FTP/FTPS 或仅本地，包括 PDF、压缩包和文档等附件

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

删除媒体附件或回滚上传时，云端删除会先执行一次有限即时重试。仍失败的对象会写入独立持久队列，由 WP-Cron 按 1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时的上限退避并持续重试。队列保存存储类型、对象 Key 和非敏感存储标识，不复制 Access Key、Secret 或 API Token；如果存储目标配置发生变化，会暂停旧目标删除，避免误删新桶中的同名对象。

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
- WordPress 原图和每个缩略图会分别上传；插件保存文件 UUID 与直链，删除附件时先移入回收站并继续永久删除
- 聚合图床返回的图片地址由浏览器直接访问，不经过 WordPress/PHP 代理

配置后先点击“测试连接”，再执行“上传自检”。上传自检会创建一张临时图片并在成功后永久删除。

## 安全与数据保护

- 所有设置保存和后台 AJAX 都有权限与 nonce 校验。
- HTTPS 请求验证 TLS 证书和主机名。
- 日志不记录 Secret Key、API Token、Authorization 或签名。
- 只有完整上传成功后才删除本地文件。
- 默认使用“云端 + 本地”双副本；可在“媒体 URL 策略”中一键让已处理媒体使用云端链接或本地链接；服务器代理读取云端失败时自动回退本地。R2 预签名直连发生在浏览器端，WordPress 无法感知第二跳读取失败。
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

### R2 私有桶是否会消耗本站文件带宽

不会。R2 私有桶使用固定 WPStow URL `302` 跳转到短期预签名地址，对象内容由 R2 直接返回。符合直传条件的新文件也会由浏览器直接发送到 R2；需要缩略图、压缩或水印时才使用 WordPress 服务器上传链路。

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

WPStow 自有代码使用 [MIT](LICENSE)；内置 Codestar Framework 2.3.1 使用 [GPL-2.0](vendor/codestar-framework/LICENSE.md)。
