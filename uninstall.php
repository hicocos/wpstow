<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 默认仅移除插件自身设置；云端媒体对象和附件元数据不会自动删除，避免误删用户内容。
delete_option('wpstow_setting');
delete_option('wpstow_rewrite_rules_version');
delete_option('wpstow_legacy_migration_version');
