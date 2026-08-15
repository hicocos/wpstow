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
        } else if (storageType === 'superbed') {
            data.superbed_endpoint = value('superbed_endpoint');
            data.superbed_api_key = value('superbed_api_key_input');
            data.superbed_folder_id = value('superbed_folder_id');
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
            superbed: [
                ['superbed_endpoint', 'API 地址']
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

    var libraryState = {
        mode: '',
        stopped: false,
        category: 'all',
        cursor: 0,
        maxId: 0,
        total: 0,
        scanned: 0,
        counts: {},
        items: [],
        processed: 0,
        failed: 0,
        skipped: 0,
        requestErrors: 0,
        keepLocal: true,
        job: null
    };
    var queuePollTimer = null;

    function emptyLibraryCounts() {
        return {
            managed: 0,
            ready: 0,
            failed: 0,
            pending: 0,
            local: 0,
            missing: 0,
            unavailable: 0
        };
    }

    function resetLibraryState(mode) {
        libraryState.mode = mode;
        libraryState.stopped = false;
        libraryState.category = $('#wpstow-library-category').val() || 'all';
        libraryState.cursor = 0;
        libraryState.maxId = 0;
        libraryState.total = 0;
        libraryState.scanned = 0;
        libraryState.counts = emptyLibraryCounts();
        libraryState.items = [];
        libraryState.processed = 0;
        libraryState.failed = 0;
        libraryState.skipped = 0;
        libraryState.requestErrors = 0;
        libraryState.keepLocal = true;
    }

    function setLibraryBusy(busy) {
        var queueActive = !!(libraryState.job && libraryState.job.active);
        $('#wpstow-library-scan, #wpstow-library-category').prop('disabled', busy || queueActive);
        if (busy || queueActive) {
            $('#wpstow-library-process').prop('disabled', true);
        }
        $('#wpstow-library-stop').prop('hidden', !busy || libraryState.mode !== 'scan').prop('disabled', false);
        $('.wpstow-library-progress').prop('hidden', !busy && !libraryState.scanned && !libraryState.processed && !libraryState.failed);
    }

    function setLibraryNotice(message, state) {
        $('#wpstow-library-notice').attr('class', 'wpstow-library-notice' + (state ? ' is-' + state : '')).text(message || '');
    }

    function updateLibraryProgress(label, current, total) {
        var safeTotal = Math.max(1, Number(total) || 1);
        var safeCurrent = Math.min(safeTotal, Math.max(0, Number(current) || 0));
        $('.wpstow-library-progress').prop('hidden', false);
        $('#wpstow-library-progress-label').text(label);
        $('#wpstow-library-progress-count').text((Number(current) || 0) + ' / ' + (Number(total) || 0));
        $('#wpstow-library-progress-bar').attr('max', safeTotal).val(safeCurrent);
    }

    function renderLibraryCounts() {
        Object.keys(emptyLibraryCounts()).forEach(function (key) {
            $('[data-wpstow-count="' + key + '"]').text(libraryState.counts[key] || 0);
        });
    }

    function statusClass(code) {
        return ['managed', 'ready', 'failed', 'pending', 'local', 'missing', 'unavailable'].indexOf(code) !== -1 ? code : 'unavailable';
    }

    function renderLibraryItems() {
        var $body = $('#wpstow-library-items').empty();
        if (!libraryState.items.length) {
            $('.wpstow-library-table-wrap').prop('hidden', true);
            return;
        }
        libraryState.items.slice(-20).forEach(function (item) {
            var $row = $('<tr>');
            var $title = $('<span>', {text: item.title || ('附件 #' + item.id)});
            if (item.edit_url) {
                $title = $('<a>', {href: item.edit_url, text: item.title || ('附件 #' + item.id)});
            }
            $('<td>').append($title).append($('<small>', {text: 'ID ' + item.id})).appendTo($row);
            $('<td>', {text: item.mime_type || item.category || '-'}).appendTo($row);
            $('<td>').append($('<span>', {
                'class': 'wpstow-library-status is-' + statusClass(item.status),
                text: item.status_label || item.status
            })).appendTo($row);
            $('<td>', {text: item.storage_label || '-'}).appendTo($row);
            $('<td>', {text: item.message || '-'}).appendTo($row);
            $body.append($row);
        });
        $('.wpstow-library-table-wrap').prop('hidden', false);
    }

    function addLibraryItems(items) {
        (items || []).forEach(function (item) {
            var existingIndex = libraryState.items.findIndex(function (existing) { return existing.id === item.id; });
            if (existingIndex === -1) {
                libraryState.items.push(item);
            } else {
                libraryState.items[existingIndex] = item;
            }
        });
        if (libraryState.items.length > 20) {
            libraryState.items = libraryState.items.slice(-20);
        }
        renderLibraryItems();
    }

    function libraryRequest(action, data) {
        return $.ajax({
            type: 'POST',
            url: ajaxUrl,
            dataType: 'json',
            timeout: 180000,
            data: $.extend({action: action, nonce: nonce}, data || {})
        });
    }

    function finishLibraryScan(stopped) {
        libraryState.mode = '';
        setLibraryBusy(false);
        renderLibraryCounts();
        renderLibraryItems();
        var actionable = (libraryState.counts.ready || 0) + (libraryState.counts.failed || 0);
        $('#wpstow-library-process').prop('disabled', actionable === 0 || !!(libraryState.job && libraryState.job.active));
        if (stopped) {
            setLibraryNotice('扫描已停止，当前统计仅包含已扫描部分。', 'warning');
            return;
        }
        setLibraryNotice('扫描完成：共检查 ' + libraryState.scanned + ' 个附件，可接管 ' + actionable + ' 个。', actionable ? 'success' : 'neutral');
    }

    function scanLibraryPage() {
        if (libraryState.stopped) {
            finishLibraryScan(true);
            return;
        }
        libraryRequest('wpstow_scan_media_library', {
            category: libraryState.category,
            cursor: libraryState.cursor,
            max_id: libraryState.maxId
        }).done(function (response) {
            if (!response || !response.success) {
                setLibraryBusy(false);
                $('#wpstow-library-process').prop('disabled', true);
                setLibraryNotice(messageFrom(response, '扫描失败'), 'error');
                return;
            }
            var data = response.data || {};
            libraryState.cursor = Number(data.cursor) || libraryState.cursor;
            libraryState.maxId = Number(data.max_id) || libraryState.maxId;
            if (data.total !== null && typeof data.total !== 'undefined') {
                libraryState.total = Number(data.total) || 0;
            }
            libraryState.scanned += Number(data.scanned) || 0;
            libraryState.keepLocal = data.keep_local !== false;
            Object.keys(libraryState.counts).forEach(function (key) {
                libraryState.counts[key] += Number((data.counts || {})[key]) || 0;
            });
            addLibraryItems(data.items);
            renderLibraryCounts();
            updateLibraryProgress('正在扫描媒体库', libraryState.scanned, libraryState.total);
            if (data.done) {
                finishLibraryScan(false);
            } else {
                window.setTimeout(scanLibraryPage, 40);
            }
        }).fail(function (xhr) {
            setLibraryBusy(false);
            $('#wpstow-library-process').prop('disabled', true);
            setLibraryNotice(messageFrom(xhr.responseJSON, '扫描请求失败'), 'error');
        });
    }

    function startLibraryScan() {
        resetLibraryState('scan');
        renderLibraryCounts();
        renderLibraryItems();
        $('#wpstow-library-process').prop('disabled', true);
        setLibraryBusy(true);
        setLibraryNotice('正在读取附件状态…', 'loading');
        updateLibraryProgress('正在扫描媒体库', 0, 0);
        scanLibraryPage();
    }

    function queueNoticeState(job) {
        if (job.status === 'completed') {
            return job.failed ? 'warning' : 'success';
        }
        if (job.status === 'paused' || job.status === 'cancelled') {
            return 'warning';
        }
        return 'loading';
    }

    function renderQueueJob(job) {
        libraryState.job = job || null;
        var active = !!(job && job.active);
        $('.wpstow-queue-controls').prop('hidden', !active);
        $('#wpstow-queue-pause').prop('hidden', !job || !job.can_pause).prop('disabled', false);
        $('#wpstow-queue-resume').prop('hidden', !job || !job.can_resume).prop('disabled', false);
        $('#wpstow-queue-cancel').prop('hidden', !job || !job.can_cancel).prop('disabled', false);
        $('#wpstow-library-scan, #wpstow-library-category').prop('disabled', active || libraryState.mode === 'scan');
        $('#wpstow-library-process').prop('disabled', active || !((libraryState.counts.ready || 0) + (libraryState.counts.failed || 0)));

        if (!job) {
            return;
        }

        libraryState.processed = Number(job.processed) || 0;
        libraryState.failed = Number(job.failed) || 0;
        libraryState.skipped = Number(job.skipped) || 0;
        updateLibraryProgress(job.status_label + ' · 服务器持久队列', Number(job.examined) || 0, Number(job.total) || 0);
        if (job.last_item) {
            addLibraryItems([job.last_item]);
        }
        var details = '成功 ' + libraryState.processed + '，失败 ' + libraryState.failed + '，跳过 ' + libraryState.skipped + '。';
        if (job.current_attachment_id && job.current_attempt) {
            details += ' 附件 #' + job.current_attachment_id + ' 已尝试 ' + job.current_attempt + '/' + job.max_attempts + ' 次。';
        }
        setLibraryNotice((job.message ? job.message + '；' : '') + details, queueNoticeState(job));
    }

    function scheduleQueuePoll(delay) {
        window.clearTimeout(queuePollTimer);
        if (libraryState.job && libraryState.job.active) {
            queuePollTimer = window.setTimeout(pollQueueStatus, delay || 3000);
        }
    }

    function pollQueueStatus() {
        libraryRequest('wpstow_queue_status', {
            job_id: libraryState.job ? libraryState.job.id : 0
        }).done(function (response) {
            if (response && response.success) {
                renderQueueJob((response.data || {}).job || null);
            }
        }).always(function () {
            scheduleQueuePoll(3000);
        });
    }

    function startLibraryProcess() {
        var actionable = (libraryState.counts.ready || 0) + (libraryState.counts.failed || 0);
        if (!actionable) {
            setLibraryNotice('当前扫描结果中没有可接管附件。', 'warning');
            return;
        }
        var warning = '将按当前已保存的分类路由接管 ' + actionable + ' 个附件。';
        if (!libraryState.keepLocal) {
            warning += '\n\n当前设置为“上传后删除本地副本”，接管成功后本地文件会被删除。';
        }
        if (!window.confirm(warning + '\n\n是否继续？')) {
            return;
        }
        $('#wpstow-library-process').prop('disabled', true).attr('aria-busy', 'true');
        setLibraryNotice('正在创建服务器队列…', 'loading');
        libraryRequest('wpstow_queue_start', {
            category: libraryState.category,
            max_id: libraryState.maxId
        }).done(function (response) {
            var job = response && response.data ? response.data.job : null;
            if (!response || !response.success) {
                if (job) {
                    renderQueueJob(job);
                }
                setLibraryNotice(messageFrom(response, '无法创建服务器队列'), 'error');
                return;
            }
            renderQueueJob(job);
            scheduleQueuePoll(1000);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var job = response.data && response.data.job ? response.data.job : null;
            if (job) {
                renderQueueJob(job);
                scheduleQueuePoll(1000);
            } else {
                setLibraryNotice(messageFrom(response, '创建服务器队列失败'), 'error');
            }
        }).always(function () {
            $('#wpstow-library-process').removeAttr('aria-busy');
        });
    }

    function stopLibraryOperation() {
        libraryState.stopped = true;
        $('#wpstow-library-stop').prop('disabled', true);
        setLibraryNotice('正在停止扫描…', 'warning');
    }

    function controlQueue(command) {
        if (!libraryState.job || !libraryState.job.id) {
            return;
        }
        if (command === 'cancel' && !window.confirm('确定取消当前服务器接管任务吗？已接管的附件不会回滚。')) {
            return;
        }
        var $buttons = $('.wpstow-queue-controls .button').prop('disabled', true);
        libraryRequest('wpstow_queue_control', {
            job_id: libraryState.job.id,
            command: command
        }).done(function (response) {
            if (response && response.success && response.data && response.data.job) {
                renderQueueJob(response.data.job);
                scheduleQueuePoll(1000);
                return;
            }
            setLibraryNotice(messageFrom(response, '队列操作失败'), 'error');
        }).fail(function (xhr) {
            setLibraryNotice(messageFrom(xhr.responseJSON, '队列操作请求失败'), 'error');
        }).always(function () {
            $buttons.prop('disabled', false);
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
        .on('click', '#wpstow-library-scan', startLibraryScan)
        .on('click', '#wpstow-library-process', startLibraryProcess)
        .on('click', '#wpstow-library-stop', stopLibraryOperation)
        .on('click', '#wpstow-queue-pause', function () { controlQueue('pause'); })
        .on('click', '#wpstow-queue-resume', function () { controlQueue('resume'); })
        .on('click', '#wpstow-queue-cancel', function () { controlQueue('cancel'); })
        .on('change', '#wpstow-library-category', function () {
            resetLibraryState('');
            renderLibraryCounts();
            renderLibraryItems();
            $('#wpstow-library-process').prop('disabled', true);
            setLibraryNotice('文件类型已改变，请重新扫描。', 'neutral');
        })
        .on('change input', '[data-depend-id="provider_config_type"], [data-depend-id^="oneimg_"], [data-depend-id^="superbed_"], [data-depend-id^="s3_"], [data-depend-id^="webdav_"], [data-depend-id^="ftp_"]', clearConnectionResult)
        .on('change', '[data-depend-id="image_compress"], [data-depend-id="image_watermark"]', syncImageOptions);

    $(function () {
        // This plugin can be enqueued before a theme-bundled CSF script. Run
        // after all ready handlers so CSF cannot hide the corrected section again.
        window.setTimeout(function () {
            revealInitialSection();
            syncImageOptions();
            pollQueueStatus();
        }, 0);
    });
})(jQuery, window);
