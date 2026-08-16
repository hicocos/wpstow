<?php

spl_autoload_register(function ($class) {
    $prefix = 'WPStow\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    if (
        $relative_class === ''
        || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $relative_class)
    ) {
        return;
    }

    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    $resolved = realpath($file);
    $base = trailingslashit(wp_normalize_path(realpath($base_dir)));
    if ($resolved !== false && strpos(wp_normalize_path($resolved), $base) === 0) {
        require $resolved;
    }
});
