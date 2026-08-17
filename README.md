# WPStow

WPStow 是一款 WordPress 云端媒体存储插件，可将图片、视频、音频和其他附件按类型转存到不同的远程存储服务，同时保留 WordPress 媒体库的原有使用方式。

- 项目主页：<https://github.com/hicocos/wpstow>
- 版本发布：<https://github.com/hicocos/wpstow/releases>
- 问题反馈：<https://github.com/hicocos/wpstow/issues>

## 功能特性

- 支持聚合图床（Superbed）、OneImg API、S3 兼容对象存储、Cloudflare R2、WebDAV 和 FTP/FTPS
- 图片、视频、音频和其他附件可分别选择存储服务，也可仅保留在本地
- 支持 S3/R2 浏览器直传，大文件自动使用 Multipart 分片上传
- 支持 R2 私有桶预签名访问，无需开放 Bucket 公共访问权限
- 支持统一转换 WebP（质量可调）、文字或图片水印、外链图片本地化和原图单文件模式
- 支持通过 FFmpeg 压缩视频、限制分辨率和添加视频水印
- 接管 WordPress 媒体 URL、缩略图、`srcset`、REST API 和编辑器媒体地址
- 支持扫描并批量接管媒体库中的现有附件
- 上传失败时保留本地文件，云端删除失败时通过后台队列继续重试
- 通过 GitHub Releases 检测新版本，并支持 WordPress 后台一键更新

## 支持的存储服务

| 存储服务 | 图片 | 视频/音频/其他 | 浏览器直传 | 私有访问 |
| --- | :---: | :---: | :---: | :---: |
| 聚合图床 | 是 | 否 | 否 | 由服务商决定 |
| OneImg API | 是 | 否 | 否 | 由服务商决定 |
| S3 兼容存储 | 是 | 是 | 是 | 支持预签名 |
| Cloudflare R2 | 是 | 是 | 是 | 支持预签名 302 |
| WebDAV | 是 | 是 | 否 | 通过 WordPress 代理 |
| FTP/FTPS | 是 | 是 | 否 | 通过 WordPress 代理 |

## 环境要求

- WordPress 6.0 或更高版本
- PHP 7.4 或更高版本
- PHP cURL 与 JSON 扩展
- 推荐启用 PHP Fileinfo 扩展
- FTP/FTPS 需要 PHP FTP 扩展
- 视频处理需要 `ffmpeg`、`ffprobe`，并允许 PHP 使用 `exec`

## 安装

1. 从 [Releases](https://github.com/hicocos/wpstow/releases) 下载最新的插件压缩包。
2. 在 WordPress 后台进入“插件 → 安装插件 → 上传插件”。
3. 上传压缩包并启用 WPStow。
4. 打开“WPStow 设置”完成存储配置。

也可以将插件目录上传到 `wp-content/plugins/wpstow/`，然后在 WordPress 后台启用。

## 基本配置

1. 在“存储配置”中为图片、视频、音频和其他附件选择存储服务。
2. 填写所选服务的连接信息并点击“测试连接”。
3. 根据需要选择保留本地副本或在上传成功后删除本地文件。
4. 启用“自动转存新上传”并保存设置。
5. 上传一个测试文件，确认媒体地址和远端对象均正常。

“当前配置服务”只决定后台显示哪组连接参数，不会改变已经保存的分类路由。附件上传成功后会记录实际使用的存储服务，后续切换配置不会改变已有附件的归属。

## S3 与 Cloudflare R2

S3 和 R2 使用独立配置。Endpoint 应填写 S3 API 地址，不要包含 Bucket 名称；R2 的 Region 使用 `auto`。

浏览器直传在以下条件下自动启用：

- 存储服务为 S3 或 Cloudflare R2
- 本地副本设置为“上传后删除”
- 文件不需要服务器生成缩略图、WebP 转换或添加水印

Bucket CORS 需要允许 WordPress 站点来源使用 `PUT`，并暴露 `ETag`。不满足直传条件或直传失败时，插件会自动回退到 WordPress 服务器流式上传。

R2 私有桶可以将“公开访问 URL”留空。媒体请求会先到达 WPStow 的固定地址，再通过 `302` 跳转到短期预签名 URL；文件内容由 R2 直接返回，不经过本站 PHP。

## 媒体库接管

“媒体接管”可扫描已有媒体附件，并按文件类型筛选可处理项目。批量任务保存在数据库中，刷新或关闭后台页面后仍可继续执行。

任务会串行处理附件，并对临时失败自动重试。只有主文件和必需的衍生文件全部上传成功后，插件才会提交云端状态并按设置处理本地副本。

## 插件更新

WPStow 通过 GitHub Releases 获取稳定版本：

- 在“插件”页面的 WPStow 操作项中点击“检查更新”
- 在“WPStow 设置 → 插件更新”中查看当前版本并手动检查
- 检测到新版本后，可使用 WordPress 的“立即更新”或自动更新功能

每个可安装版本都应在 GitHub Release 中附带插件 ZIP 文件。完整版本变化请查看 [Releases](https://github.com/hicocos/wpstow/releases)，README 不维护单个版本的更新日志。

## 安全与数据保护

- 设置保存和后台操作均进行权限与 nonce 校验
- HTTPS 请求验证 TLS 证书和主机名
- 日志不会记录密码、Secret Key、API Token、Authorization 或请求签名
- 只有远端上传完整成功后，才会按设置删除本地文件
- 云端对象删除失败会进入持久重试队列
- S3/R2 凭据应遵循最小权限原则，仅授权目标 Bucket 所需操作

建议在生产环境中定期备份 WordPress 数据库和媒体文件，并为云端存储凭据制定轮换计划。

## 常见问题

### 配置完成后为什么仍使用本地文件？

确认“自动转存新上传”已经启用。“测试连接”成功只表示凭据可用，不会自动开启媒体转存。

### 上传失败会删除本地文件吗？

不会。主文件或任一必需文件上传失败时，本地文件会被保留，并记录可重试的错误。

### 为什么 R2 私有媒体地址包含 `admin-ajax.php`？

这是固定的链接解析入口。WPStow 验证附件与对象的对应关系后返回 `302`，浏览器随后直接从 R2 获取文件。

### FFmpeg 已安装但后台显示不可用怎么办？

请确认网站实际使用的 PHP-FPM 环境允许 `exec`，并且 `ffmpeg` 和 `ffprobe` 对该环境可执行。CLI PHP 与 PHP-FPM 可能使用不同的配置。

## 开发与发布

提交代码前可运行以下检查：

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
for file in static/*.js; do node --check "$file"; done
```

推送符合 `v*` 格式的 Git 标签后，GitHub Actions 会构建插件 ZIP 并创建 Release。

贡献代码前请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)。

## 作者

**梅零落**

## 许可证

WPStow 自有代码基于 [MIT License](LICENSE) 发布。内置 Codestar Framework 使用其目录中声明的许可证。
