(function ($, window) {
    'use strict';

    var cfg = window.wpstowAdmin || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl;
    var nonce = cfg.nonce || '';

    function field(name) {
        return $('[data-depend-id="' + name + '"]').first();
    }

    function value(name) {
        var $field = field(name);
        if (!$field.length) {
            return '';
        }
        if ($field.is(':radio')) {
            return $('[data-depend-id="' + name + '"]:checked').val() || '';
        }
        return $field.val() || '';
    }

    function setResult($target, message, state) {
        $target.removeClass('is-success is-error is-loading')
            .addClass(state || '')
            .text(message || '');
    }

    function messageFrom(response, fallback) {
        if (response && response.message) {
            return response.message;
        }
        if (response && response.data && response.data.message) {
            return response.data.message;
        }
        if (response && typeof response.data === 'string') {
            return response.data;
        }
        return fallback;
    }

    function connectionData() {
        var storageType = value('provider_config_type') || 's3';
        var data = {
            action: 'wpstow_test_storage_connection',
            nonce: nonce,
            storage_type: storageType
        };

        if (storageType === 'oneimg') {
            data.oneimg_endpoint = value('oneimg_endpoint');
            data.oneimg_token = value('oneimg_token_input');
        } else if (storageType === 's3') {
            data.s3_endpoint = value('s3_endpoint');
            data.s3_access_key = value('s3_access_key_input');
            data.s3_secret_key = value('s3_secret_key_input');
            data.s3_bucket = value('s3_bucket');
            data.s3_region = value('s3_region');
            data.s3_path_style = value('s3_path_style');
        } else if (storageType === 'webdav') {
            data.webdav_endpoint = value('webdav_endpoint');
            data.webdav_username = value('webdav_username');
            data.webdav_password = value('webdav_password_input');
            data.webdav_path = value('webdav_path');
        } else {
            data.ftp_host = value('ftp_host');
            data.ftp_port = value('ftp_port');
            data.ftp_username = value('ftp_username');
            data.ftp_password = value('ftp_password_input');
            data.ftp_path = value('ftp_path');
            data.ftp_passive = value('ftp_passive');
            data.ftp_ssl = value('ftp_ssl');
        }
        return data;
    }

    function missingConnectionFields(storageType) {
        var requirements = {
            oneimg: [
                ['oneimg_endpoint', '图床地址']
            ],
            s3: [
                ['s3_endpoint', 'Endpoint'],
                ['s3_bucket', 'Bucket']
            ],
            webdav: [
                ['webdav_endpoint', 'Endpoint'],
                ['webdav_username', '用户名']
            ],
            ftp: [
                ['ftp_host', '主机地址'],
                ['ftp_username', '用户名']
            ]
        };

        return (requirements[storageType] || []).filter(function (item) {
            return !String(value(item[0])).trim();
        }).map(function (item) {
            return item[1];
        });
    }

    function testConnection() {
        var $button = $('#wpstow-test-connection');
        var $result = $('#wpstow-test-result');
        var storageType = value('provider_config_type') || 's3';
        var missing = missingConnectionFields(storageType);

        if (missing.length) {
            setResult($result, '请先填写：' + missing.join('、'), 'is-error');
            return;
        }

        $button.prop('disabled', true).attr('aria-busy', 'true');
        setResult($result, '正在连接…', 'is-loading');

        $.post(ajaxUrl, connectionData(), function (response) {
            if (response && response.status) {
                setResult($result, '连接成功：' + messageFrom(response, '存储后端响应正常'), 'is-success');
            } else {
                setResult($result, '连接失败：' + messageFrom(response, '请检查配置'), 'is-error');
            }
        }).fail(function (xhr) {
            setResult($result, '请求失败：' + messageFrom(xhr.responseJSON, '请查看服务器日志'), 'is-error');
        }).always(function () {
            $button.prop('disabled', false).removeAttr('aria-busy');
        });
    }

    function debugUpload() {
        var $button = $('.wpstow-debug-upload-trigger');
        var $result = $('#wpstow-debug-result');
        $button.prop('disabled', true).attr('aria-busy', 'true');
        $result.show().text('正在创建临时对象并测试上传与删除…');

        $.post(ajaxUrl, {
            action: 'wpstow_debug_upload',
            nonce: nonce
        }, function (response) {
            var title = response && response.success ? '自检通过' : '自检失败';
            var details = response && response.data ? response.data : response;
            $result.text(title + '\n' + JSON.stringify(details || {}, null, 2));
        }).fail(function (xhr, status, error) {
            $result.text('请求失败：' + messageFrom(xhr.responseJSON, error || status || '未知错误'));
        }).always(function () {
            $button.prop('disabled', false).removeAttr('aria-busy');
        });
    }

    function clearLog() {
        if (!window.confirm('确定要清除 WPStow 当前日志及轮转文件吗？')) {
            return;
        }

        var $button = $('#wpstow-clear-log');
        var keepDisabled = false;
        $button.prop('disabled', true).attr('aria-busy', 'true');
        $.post(ajaxUrl, {
            action: 'wpstow_clear_log',
            nonce: nonce
        }, function (response) {
            if (response && response.success) {
                $('#wpstow-log-output').replaceWith('<div id="wpstow-log-output" class="wpstow-empty-log"><strong>暂无运行日志</strong><span>需要排查问题时，先开启上方“运行日志”并保存。</span></div>');
                keepDisabled = true;
                $('#wpstow-debug-result').show().text('日志已清除。');
                return;
            }
            window.alert('清除失败：' + messageFrom(response, '未知错误'));
        }).fail(function (xhr) {
            window.alert('清除失败：' + messageFrom(xhr.responseJSON, '请求未完成'));
        }).always(function () {
            $button.prop('disabled', keepDisabled).removeAttr('aria-busy');
        });
    }

    function normalizeTabSlug(valueToNormalize) {
        var normalized = String(valueToNormalize || '').replace(/^#tab=/, '');
        try {
            normalized = decodeURIComponent(normalized);
        } catch (error) {
            // Keep the original slug when the browser receives malformed encoding.
        }
        return normalized.toLowerCase();
    }

    function revealInitialSection() {
        var $root = $('.wpstow-csf');
        var $links = $root.find('.csf-nav a[data-tab-id]');
        var $sections = $root.find('.csf-section');
        var requested = normalizeTabSlug(window.location.hash);
        var $link = $links.filter(function () {
            return normalizeTabSlug($(this).attr('data-tab-id')) === requested;
        }).first();

        if (!$link.length) {
            $link = $links.first();
        }
        if (!$link.length || !$sections.length) {
            return;
        }

        var target = normalizeTabSlug($link.attr('data-tab-id'));
        var $section = $sections.filter(function () {
            return normalizeTabSlug($(this).attr('data-section-id')) === target;
        }).first();

        if (!$section.length) {
            $section = $sections.eq($links.index($link));
        }
        if (!$section.length) {
            return;
        }

        $links.removeClass('csf-active');
        $link.addClass('csf-active');
        $sections.addClass('hidden');
        $section.removeClass('hidden');

        if (typeof $section.csf_reload_script === 'function') {
            $section.csf_reload_script();
        }
    }

    function syncImageOptions() {
        var hasProcessing = value('image_compress') === 'yes' || value('image_watermark') === 'yes';
        field('keep_original').closest('.csf-field').toggle(hasProcessing);
    }

    function clearConnectionResult() {
        setResult($('#wpstow-test-result'), '', '');
    }

    $(document)
        .on('click', '#wpstow-test-connection', testConnection)
        .on('click', '.wpstow-debug-upload-trigger', debugUpload)
        .on('click', '#wpstow-clear-log', clearLog)
        .on('change input', '[data-depend-id="provider_config_type"], [data-depend-id^="oneimg_"], [data-depend-id^="s3_"], [data-depend-id^="webdav_"], [data-depend-id^="ftp_"]', clearConnectionResult)
        .on('change', '[data-depend-id="image_compress"], [data-depend-id="image_watermark"]', syncImageOptions);

    $(function () {
        // This plugin can be enqueued before a theme-bundled CSF script. Run
        // after all ready handlers so CSF cannot hide the corrected section again.
        window.setTimeout(function () {
            revealInitialSection();
            syncImageOptions();
        }, 0);
    });
})(jQuery, window);
