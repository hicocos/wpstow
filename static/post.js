if (typeof wpstow_js_flag !== 'undefined' && wpstow_js_flag === 'page') {
    jQuery('#wpstow-upload-one').off('click.wpstow').on('click.wpstow', sys_ajax);
}

function sys_ajax() {
    var $button = jQuery('#wpstow-upload-one');
    $button.prop('disabled', true).attr('aria-busy', 'true').text('正在上传…');

    jQuery.ajax({
        type: 'POST',
        url: wpstow_ajax_url,
        dataType: 'json',
        data: {
            action: 'wpstow_upload_one',
            nonce: wpstow_nonce,
            post_id: post_id
        }
    }).done(function (response) {
        if (response && response.success) {
            $button.text('已存储至云端');
            window.alert(response.data.message || '上传成功');
            return;
        }
        $button.prop('disabled', false).removeAttr('aria-busy').text('重试上传');
        window.alert((response && response.data && response.data.message) || '上传失败，本地文件已保留');
    }).fail(function (xhr) {
        $button.prop('disabled', false).removeAttr('aria-busy').text('重试上传');
        var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
        window.alert(message || '请求失败，请检查网络或插件日志');
    });
}
