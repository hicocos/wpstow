<?php
namespace WPStow;

if (!defined('ABSPATH')) exit;

class Update
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/hicocos/wpstow/releases/latest';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/hicocos/wpstow';
    private const GITHUB_SOURCE_PREFIX = 'hicocos-wpstow-';

    private $plugin_slug;
    private $plugin_dir;

    public function __construct($plugin_file)
    {
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_dir = dirname($this->plugin_slug);

        add_filter('update_plugins_github.com', [$this, 'get_update'], 10, 4);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_update_folder'], 10, 4);
        add_filter('pre_site_transient_update_plugins', [$this, 'expose_update_transient'], PHP_INT_MAX, 2);

        if (is_admin()) {
            add_filter('plugin_action_links_' . $this->plugin_slug, [$this, 'action_links']);
            add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 4);
            add_action('admin_post_wpstow_check_updates', [$this, 'handle_manual_check']);
            add_action('admin_notices', [$this, 'manual_check_notice']);
        }
    }

    /**
     * Some themes suppress the complete plugin update transient by returning
     * null. In that case, expose only WPStow's cached result.
     */
    public function expose_update_transient($pre, $transient)
    {
        if ($transient !== 'update_plugins' || $pre !== null) {
            return $pre;
        }

        $stored = $this->get_stored_update_transient();
        if (!is_object($stored)) {
            return $pre;
        }

        $response = isset($stored->response[$this->plugin_slug])
            ? $stored->response[$this->plugin_slug]
            : null;
        $no_update = isset($stored->no_update[$this->plugin_slug])
            ? $stored->no_update[$this->plugin_slug]
            : null;
        if (!$response && !$no_update) {
            return $pre;
        }

        $visible = new \stdClass();
        $visible->last_checked = isset($stored->last_checked) ? (int) $stored->last_checked : time();
        $visible->checked = [
            $this->plugin_slug => isset($stored->checked[$this->plugin_slug])
                ? $stored->checked[$this->plugin_slug]
                : WPSTOW_VERSION,
        ];
        $visible->response = $response ? [$this->plugin_slug => $response] : [];
        $visible->no_update = $no_update ? [$this->plugin_slug => $no_update] : [];
        $visible->translations = [];
        return $visible;
    }

    public function action_links($links)
    {
        if (!current_user_can('update_plugins')) {
            return $links;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=wpstow_check_updates'),
            'wpstow_check_updates'
        );
        $links[] = '<a href="' . esc_url($url) . '">检查更新</a>';
        return $links;
    }

    public function row_meta($plugin_meta, $plugin_file, $plugin_data, $status)
    {
        if ($plugin_file !== $this->plugin_slug) {
            return $plugin_meta;
        }

        $homepage = !empty($plugin_data['PluginURI'])
            ? $plugin_data['PluginURI']
            : self::GITHUB_REPOSITORY_URL;
        $homepage_link = sprintf(
            '<a href="%s" aria-label="%s">访问插件主页</a>',
            esc_url($homepage),
            esc_attr('访问 WPStow 插件主页')
        );

        foreach ($plugin_meta as $index => $item) {
            if (strpos($item, 'open-plugin-details-modal') !== false) {
                $plugin_meta[$index] = $homepage_link;
                return $plugin_meta;
            }
        }

        $plugin_meta[] = $homepage_link;
        return $plugin_meta;
    }

    public function handle_manual_check()
    {
        if (!current_user_can('update_plugins')) {
            wp_die('您没有检查插件更新的权限。');
        }

        check_admin_referer('wpstow_check_updates');

        $update = $this->get_update(false, [], $this->plugin_slug, []);
        $status = 'failed';
        $version = '';

        if (is_array($update) && !empty($update['version'])) {
            $version = sanitize_text_field($update['version']);
            $status = version_compare($version, WPSTOW_VERSION, '>') ? 'available' : 'current';
            $this->store_update_result($update, $status === 'available');
        }

        $return_to = isset($_GET['return_to']) ? sanitize_key(wp_unslash($_GET['return_to'])) : '';
        $redirect_base = $return_to === 'settings'
            ? admin_url('admin.php?page=wpstow_settings')
            : admin_url('plugins.php');
        $redirect = add_query_arg(
            [
                'wpstow_update_check' => $status,
                'wpstow_latest_version' => $version,
            ],
            $redirect_base
        );
        if ($return_to === 'settings') {
            $redirect .= '#tab=' . rawurlencode('主题更新');
        }
        wp_safe_redirect($redirect);
        exit;
    }

    private function store_update_result(array $update, $is_available)
    {
        $transient = $this->get_stored_update_transient();
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        if (!isset($transient->checked) || !is_array($transient->checked)) {
            $transient->checked = [];
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $payload = (object) $update;
        $payload->id = self::GITHUB_REPOSITORY_URL;
        $payload->plugin = $this->plugin_slug;
        $payload->new_version = $payload->version;

        $transient->checked[$this->plugin_slug] = WPSTOW_VERSION;
        $transient->last_checked = time();
        if ($is_available) {
            $transient->response[$this->plugin_slug] = $payload;
            unset($transient->no_update[$this->plugin_slug]);
        } else {
            $transient->no_update[$this->plugin_slug] = $payload;
            unset($transient->response[$this->plugin_slug]);
        }

        set_site_transient('update_plugins', $transient);
    }

    private function get_stored_update_transient()
    {
        if (wp_using_ext_object_cache()) {
            return wp_cache_get('update_plugins', 'site-transient');
        }

        return get_site_option('_site_transient_update_plugins');
    }

    public function manual_check_notice()
    {
        if (!current_user_can('update_plugins') || empty($_GET['wpstow_update_check'])) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['wpstow_update_check']));
        $version = isset($_GET['wpstow_latest_version'])
            ? sanitize_text_field(wp_unslash($_GET['wpstow_latest_version']))
            : '';

        if ($status === 'available') {
            $class = 'notice notice-warning is-dismissible';
            $message = sprintf('WPStow 检测到新版本 v%s，请在插件条目下方点击“立即更新”。', $version);
        } elseif ($status === 'current') {
            $class = 'notice notice-success is-dismissible';
            $message = sprintf('WPStow 已完成检查，当前 v%s 已是最新版。', WPSTOW_VERSION);
        } else {
            $class = 'notice notice-error is-dismissible';
            $message = 'WPStow 更新检查失败，请确认服务器可以访问 GitHub 后重试。';
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Supply release data for the Update URI declared in the plugin header.
     */
    public function get_update($update, $plugin_data, $plugin_file, $locales)
    {
        if ($plugin_file !== $this->plugin_slug) {
            return $update;
        }

        $release = $this->get_repo_info();
        if (!$release) {
            return $update;
        }

        $version = $this->release_version($release);
        $package = $this->release_package($release, $version);
        if ($version === '' || $package === '') {
            return $update;
        }

        return [
            'slug' => $this->plugin_dir,
            'version' => $version,
            'url' => esc_url_raw($release['html_url'] ?? self::GITHUB_REPOSITORY_URL),
            'package' => $package,
            'requires' => '6.0',
            'tested' => '7.0',
            'requires_php' => '7.4',
        ];
    }

    public function plugin_info($result, $action, $args)
    {
        if (
            $action !== 'plugin_information'
            || empty($args->slug)
            || $args->slug !== $this->plugin_dir
        ) {
            return $result;
        }

        $release = $this->get_repo_info();
        if (!$release) {
            return $result;
        }

        $version = $this->release_version($release);
        $package = $this->release_package($release, $version);
        if ($version === '' || $package === '') {
            return $result;
        }

        return (object) [
            'name' => 'WPStow',
            'slug' => $this->plugin_dir,
            'version' => $version,
            'author' => '<a href="https://moepick.com/">梅零落</a>',
            'homepage' => esc_url_raw($release['html_url'] ?? self::GITHUB_REPOSITORY_URL),
            'requires' => '6.0',
            'tested' => '7.0',
            'requires_php' => '7.4',
            'last_updated' => sanitize_text_field($release['published_at'] ?? ''),
            'short_description' => '将 WordPress 媒体上传到云端存储。',
            'sections' => [
                'description' => nl2br(esc_html($release['body'] ?? '暂无描述。')),
            ],
            'download_link' => $package,
        ];
    }

    private function get_repo_info()
    {
        $response = wp_remote_get(self::GITHUB_API_URL, [
            'timeout' => 15,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WPStow/' . (defined('WPSTOW_VERSION') ? WPSTOW_VERSION : 'unknown'),
            ],
        ]);

        if (is_wp_error($response)) {
            Utils::writeLog('[Update] GitHub API 请求失败: ' . $response->get_error_message());
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            Utils::writeLog('[Update] GitHub API 返回异常状态: ' . $status);
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release['tag_name']) || !empty($release['draft'])) {
            Utils::writeLog('[Update] GitHub Release 数据无效');
            return false;
        }

        return $release;
    }

    private function release_version(array $release)
    {
        $version = preg_replace('/^[vV]/', '', trim((string) ($release['tag_name'] ?? '')));
        return is_string($version) ? sanitize_text_field($version) : '';
    }

    private function release_package(array $release, $version)
    {
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        $preferred_name = 'wpstow-v' . $version . '.zip';
        $fallback = '';

        foreach ($assets as $asset) {
            if (!is_array($asset) || empty($asset['browser_download_url'])) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');
            if (substr(strtolower($name), -4) !== '.zip') {
                continue;
            }

            $url = esc_url_raw($asset['browser_download_url']);
            if ($url === '') {
                continue;
            }

            if ($name === $preferred_name) {
                return $url;
            }

            if ($fallback === '') {
                $fallback = $url;
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return esc_url_raw($release['zipball_url'] ?? '');
    }

    public function fix_update_folder($source, $remote_source, $upgrader, $hook_extra)
    {
        if (($hook_extra['plugin'] ?? '') !== $this->plugin_slug) {
            return $source;
        }

        $source_name = basename(untrailingslashit($source));
        if ($source_name === $this->plugin_dir) {
            return $source;
        }

        if (strpos(strtolower($source_name), self::GITHUB_SOURCE_PREFIX) !== 0) {
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

        $correct = trailingslashit($remote_source) . $this->plugin_dir . '/';
        if ($wp_filesystem->is_dir($correct) && !$wp_filesystem->delete($correct, true)) {
            return new \WP_Error('remove_old_source_failed', '插件更新失败，无法清理临时目录');
        }

        if (!$wp_filesystem->move($source, $correct)) {
            return new \WP_Error('move_failed', '插件更新失败，无法整理插件目录');
        }

        return $correct;
    }
}
