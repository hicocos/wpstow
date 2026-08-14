# WPStow 1.5.0

WPStow 是面向 WordPress 的云端媒体插件，支持 OneImg API、S3 兼容对象存储（包括 Cloudflare R2）、WebDAV 和 FTP/FTPS。

项目主页：<https://github.com/hicocos/wpstow>

插件在 WordPress 后台提供统一的“WPStow 设置”页面，配置保存在 `wpstow_setting` option 中。

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

## 当前站点的 R2 路径示例

```text
Bucket: sakura
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

- 图片：OneImg、S3/R2、WebDAV、FTP/FTPS 或仅本地
- 视频：S3/R2、WebDAV、FTP/FTPS 或仅本地
- 音频：S3/R2、WebDAV、FTP/FTPS 或仅本地
- 其他：S3/R2、WebDAV、FTP/FTPS 或仅本地，包括 PDF、压缩包和文档等附件

“当前配置服务”只控制下方显示哪一组连接参数，不会改变分类路由。切换服务后，其他服务已经保存的凭据会继续保留。附件上传成功后会记录实际使用的存储源，之后即使修改分类路由，已有附件仍从原存储源读取和删除。

## OneImg API 配置

- Endpoint：OneImg 站点根地址，例如 `https://img.example.com`，不要附加 `/api`
- API Token：OneImg 后台生成的 API Token；插件通过 `Authorization: oneimg_token=...` 调用接口
- OneImg 仅接收图片，因此不会出现在视频、音频和其他文件的存储选项中
- WordPress 原图和每个缩略图会分别成为 OneImg 图片记录，删除附件时按 OneImg 图片 ID 同步清理
- OneImg 返回的图片地址由浏览器直接访问，不经过 WordPress/PHP 图片代理
- OneImg 可能按服务端策略把 PNG/JPEG 转码为 WebP；代理读取时会采用远端返回的实际 MIME 类型

配置后先点击“测试连接”，确认 API 鉴权和目标存储源，再执行“上传自检”。

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

## 备份与升级

升级前备份插件目录和 WordPress 数据库。设置保存在 `wpstow_setting` option 中；附件状态保存在 `_wpstow_uploaded`、`_wpstow_cloud_key`、`_wpstow_storage_type` 和 `_wpstow_storage_manifest` 等 post meta 中。

从旧版本升级时，若尚未保存新选项，`keep_local` 与 `cloud_fallback_local` 会安全地默认为 `yes`，`media_url_mode` 会默认为 `cloud`，保持原来的云端链接行为。如确实只需要云端副本，可在“存储配置”中显式关闭双保留。

旧版单一存储配置会自动成为四类路由的默认值。若旧存储源是 OneImg，图片继续使用 OneImg，视频、音频和其他文件默认保持仅本地，与旧版行为一致。

从 VeMedia 更名升级时，插件会自动复制旧设置、附件状态和 OneImg 对象映射；旧数据会保留，便于必要时回退。

## 开发与发布

提交代码前，请运行：

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
for file in static/*.js; do node --check "$file"; done
```

推送 `v*` 标签后，GitHub Actions 会自动生成只包含生产文件的 `wpstow-<version>.zip` 并创建 Release。

## 许可证

[MIT](LICENSE)
