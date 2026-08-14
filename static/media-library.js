(function ($) {
    'use strict';

    function updateState($wrap, code, label, message) {
        $wrap.removeClass(function (index, value) {
            return (value.match(/(^|\s)wpstow-media-status--\S+/g) || []).join(' ');
        }).addClass('wpstow-media-status--' + code).attr('data-wpstow-status', code);
        $wrap.find('.wpstow-media-status__badge').text(label);
        $wrap.find('.wpstow-media-status__message').text(message);
    }

    function renderGridStatus(view) {
        if (!view || !view.model || !view.$el) {
            return;
        }
        var status = view.model.get('wpstow');
        if (!status || view.$el.children('.wpstow-grid-status').length) {
            return;
        }

        var title = status.message || '';
        if (status.url_mode_label) {
            title += (title ? ' · ' : '') + '当前链接：' + status.url_mode_label;
        }
        var $status = $('<div>', {
            'class': 'wpstow-grid-status wpstow-grid-status--' + status.code,
            'data-wpstow-status': status.code,
            'aria-label': 'WPStow：' + status.label,
            title: title
        });
        $('<strong>', {'class': 'wpstow-grid-status__badge', text: status.label}).appendTo($status);
        if (!status.uploaded && status.code !== 'local') {
            $('<button>', {
                type: 'button',
                'class': 'button button-small wpstow-process-button',
                text: status.code === 'error' ? wpstowMediaLibrary.retry : '立即处理',
                disabled: !status.can_process,
                'data-attachment-id': status.attachment_id,
                'data-nonce': status.nonce,
                title: status.message
            }).appendTo($status);
        }
        view.$el.append($status);
    }

    if (window.wp && wp.media && wp.media.view && wp.media.view.Attachment && wp.media.view.Attachment.Library) {
        var originalGridRender = wp.media.view.Attachment.Library.prototype.render;
        wp.media.view.Attachment.Library.prototype.render = function () {
            var result = originalGridRender.apply(this, arguments);
            renderGridStatus(this);
            return result;
        };
    }

    $(document).on('click', '.wpstow-process-button', function () {
        var $button = $(this);
        var $wrap = $button.closest('.wpstow-media-status');
        var originalText = $button.text();

        $button.prop('disabled', true).attr('aria-busy', 'true').text(wpstowMediaLibrary.processing);
        if ($wrap.hasClass('wpstow-grid-status')) {
            $wrap.attr('class', 'wpstow-grid-status wpstow-grid-status--pending');
            $wrap.find('.wpstow-grid-status__badge').text('正在处理');
        } else {
            updateState($wrap, 'pending', '正在处理', '正在上传主文件与全部衍生文件，请勿关闭页面。');
        }

        $.ajax({
            type: 'POST',
            url: wpstowMediaLibrary.ajaxUrl,
            dataType: 'json',
            data: {
                action: 'wpstow_upload_one',
                nonce: $button.data('nonce'),
                post_id: $button.data('attachment-id')
            }
        }).done(function (response) {
            if (response && response.success) {
                var status = response.data.status || {};
                if ($wrap.hasClass('wpstow-grid-status')) {
                    $wrap.attr('class', 'wpstow-grid-status wpstow-grid-status--processed');
                    $wrap.find('.wpstow-grid-status__badge').text(status.label || '已处理');
                    if (status.message) {
                        $wrap.attr('title', status.message);
                    }
                } else {
                    updateState($wrap, status.code || 'processed', status.label || '已处理', response.data.message || status.message || '处理完成');
                }
                $button.remove();
                return;
            }
            var message = response && response.data && response.data.message ? response.data.message : wpstowMediaLibrary.failed;
            if ($wrap.hasClass('wpstow-grid-status')) {
                $wrap.attr('class', 'wpstow-grid-status wpstow-grid-status--error');
                $wrap.find('.wpstow-grid-status__badge').text('处理失败');
            } else {
                updateState($wrap, 'error', '处理失败', message);
            }
            $button.prop('disabled', false).removeAttr('aria-busy').text(wpstowMediaLibrary.retry).attr('title', message);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            message = message || wpstowMediaLibrary.failed;
            if ($wrap.hasClass('wpstow-grid-status')) {
                $wrap.attr('class', 'wpstow-grid-status wpstow-grid-status--error');
                $wrap.find('.wpstow-grid-status__badge').text('处理失败');
            } else {
                updateState($wrap, 'error', '处理失败', message);
            }
            $button.prop('disabled', false).removeAttr('aria-busy').text(originalText || wpstowMediaLibrary.retry).attr('title', message);
        });
    });
}(jQuery));
