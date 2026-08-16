<?php
namespace WPStow;

if (!defined('ABSPATH')) exit;

use WPStow\Utils;

class Update
{
    private $plugin_file;
    private $plugin_slug;
    private $plugin_dir;
    private $github_user = 'hicocos';
    private $github_repo = 'wpstow';

    public function __construct($plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_dir = dirname($this->plugin_slug);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_update_folder'], 10, 4);
    }

    private function get_repo_info()
    {
        $url = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $args = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ];
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            Utils::writeLog('[Error] GitHub API 请求失败: ' . $response->get_error_message());
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    public function check_for_update($transient)
    {
        if (empty($transient->checked)) return $transient;

        $plugin_data = get_plugin_data($this->plugin_file);
        $current_version = $plugin_data['Version'];
        $repo_info = $this->get_repo_info();

        if (!$repo_info || !isset($repo_info->tag_name)) return $transient;

        $latest_version = ltrim($repo_info->tag_name, 'v');

        if (version_compare($latest_version, $current_version, '>')) {
            $transient->response[$this->plugin_slug] = (object)[
                'slug' => $this->plugin_dir,
                'plugin' => $this->plugin_slug,
                'new_version' => $latest_version,
                'url' => $repo_info->html_url,
                'package' => $repo_info->zipball_url,
            ];
            Utils::writeLog("[Update] 有新版本可用: $latest_version");
        }

        return $transient;
    }

    public function plugin_info($res, $action, $args)
    {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_dir) {
            return $res;
        }

        $repo_info = $this->get_repo_info();
        if (!$repo_info) return $res;

        return (object)[
            'name' => 'WPStow',
            'slug' => $this->plugin_dir,
            'version' => ltrim($repo_info->tag_name, 'v'),
            'author' => '<a href="https://moepick.com/">MoePick</a>',
            'homepage' => $repo_info->html_url,
            'short_description' => '将 WordPress 媒体上传到云端存储。',
            'sections' => [
                'description' => $repo_info->body ?: '暂无描述。',
            ],
            'download_link' => $repo_info->zipball_url,
        ];
    }

    public function fix_update_folder($source, $remote_source, $upgrader, $hook_extra)
    {
        if (($hook_extra['plugin'] ?? '') !== $this->plugin_slug) {
            return $source;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return new \WP_Error('filesystem_unavailable', '插件更新失败，无法初始化 WordPress 文件系统');
        }
        global $wp_filesystem;

        if (!$wp_filesystem) {
            return new \WP_Error('filesystem_unavailable', '插件更新失败，WordPress 文件系统不可用');
        }

        Utils::writeLog("[Upgrade] 源路径: $source");
        Utils::writeLog("[Upgrade] 解压路径: $remote_source");

        if (strpos($source, $this->github_user . '-' . $this->github_repo) !== false) {
            $correct = trailingslashit($remote_source) . $this->plugin_dir . '/';

            if ($wp_filesystem->is_dir($correct)) {
                $wp_filesystem->delete($correct, true);
                Utils::writeLog("[Upgrade] 旧目录已删除: $correct");
            }

            if ($wp_filesystem->move($source, $correct)) {
                Utils::writeLog("[Upgrade] 移动成功: $source -> $correct");
                return $correct;
            } else {
                Utils::writeLog("[Error] 插件目录移动失败");
                return new \WP_Error('move_failed', '插件更新失败，无法移动目录');
            }
        }

        return $source;
    }
}
