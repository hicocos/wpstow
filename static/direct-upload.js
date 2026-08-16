(function (window, $) {
    'use strict';

    var config = window.wpstowDirectUpload || {};
    var tasks = {};

    if (!config.enabled || !window.plupload || !window.fetch || !window.FormData) {
        return;
    }

    function categoryFor(file) {
        var type = String(file.type || '').toLowerCase();
        if (type.indexOf('image/') === 0) {
            return 'image';
        }
        if (type.indexOf('video/') === 0) {
            return 'video';
        }
        if (type.indexOf('audio/') === 0) {
            return 'audio';
        }
        return 'other';
    }

    function shouldTry(file) {
        var route = config.routes && config.routes[categoryFor(file)];
        var maxUploadSize = Number(config.maxUploadSize || 0);
        return (route === 's3' || route === 'r2')
            && (!maxUploadSize || Number(file.size || 0) <= maxUploadSize);
    }

    function nativeFile(file) {
        try {
            return typeof file.getNative === 'function' ? file.getNative() : null;
        } catch (error) {
            return null;
        }
    }

    function ajax(action, values, keepalive) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce);
        Object.keys(values || {}).forEach(function (key) {
            var value = values[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', item);
                });
            } else {
                body.append(key, value);
            }
        });

        return window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            keepalive: !!keepalive
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('服务器返回了无法解析的响应');
            }).then(function (payload) {
                if (!response.ok || !payload || !payload.success) {
                    var error = new Error(payload && payload.data && payload.data.message
                        ? payload.data.message
                        : '直传请求失败');
                    error.status = response.status;
                    throw error;
                }
                return payload;
            });
        });
    }

    function delay(milliseconds) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, milliseconds);
        });
    }

    function sendBlob(url, blob, task, onProgress) {
        return new Promise(function (resolve, reject) {
            if (task.cancelled) {
                reject(new Error('上传已取消'));
                return;
            }

            var xhr = new XMLHttpRequest();
            task.requests.push(xhr);
            xhr.open('PUT', url, true);
            xhr.setRequestHeader('Content-Type', task.mimeType || task.native.type || 'application/octet-stream');
            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    onProgress(event.loaded);
                }
            };
            xhr.onload = function () {
                task.requests = task.requests.filter(function (request) { return request !== xhr; });
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve({
                        etag: String(xhr.getResponseHeader('ETag') || '').replace(/^"|"$/g, ''),
                        status: xhr.status
                    });
                    return;
                }
                var error = new Error('对象存储返回 HTTP ' + xhr.status);
                error.status = xhr.status;
                reject(error);
            };
            xhr.onerror = function () {
                task.requests = task.requests.filter(function (request) { return request !== xhr; });
                var error = new Error('无法连接对象存储，请检查 Bucket CORS');
                error.status = 0;
                reject(error);
            };
            xhr.onabort = function () {
                task.requests = task.requests.filter(function (request) { return request !== xhr; });
                reject(new Error('上传已取消'));
            };
            xhr.send(blob);
        });
    }

    function updateProgress(task, loaded) {
        task.file.loaded = Math.min(task.file.size, loaded);
        task.file.percent = task.file.size > 0
            ? Math.min(99, Math.floor(task.file.loaded * 100 / task.file.size))
            : 0;
        task.uploader.trigger('UploadProgress', task.file);
    }

    function imageDimensions(file) {
        if (String(file.type || '').indexOf('image/') !== 0) {
            return Promise.resolve({ width: 0, height: 0 });
        }
        if (window.createImageBitmap) {
            return window.createImageBitmap(file).then(function (bitmap) {
                var dimensions = { width: bitmap.width, height: bitmap.height };
                if (bitmap.close) {
                    bitmap.close();
                }
                return dimensions;
            }).catch(function () {
                return { width: 0, height: 0 };
            });
        }
        return new Promise(function (resolve) {
            var image = new Image();
            var url = URL.createObjectURL(file);
            image.onload = function () {
                URL.revokeObjectURL(url);
                resolve({ width: image.naturalWidth || 0, height: image.naturalHeight || 0 });
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                resolve({ width: 0, height: 0 });
            };
            image.src = url;
        });
    }

    function uploadSimple(task, details) {
        var attempt = 0;
        function run() {
            return sendBlob(details.upload_url, task.native, task, function (loaded) {
                updateProgress(task, loaded);
            }).catch(function (error) {
                if (task.cancelled || attempt >= Number(config.maxRetries || 4)) {
                    throw error;
                }
                attempt += 1;
                return delay(Math.pow(2, attempt - 1) * 500 + Math.floor(Math.random() * 250)).then(run);
            });
        }
        return run();
    }

    function refreshPartUrl(task, partNumber) {
        return ajax('wpstow_direct_upload_sign_parts', {
            upload_token: task.token,
            part_numbers: [partNumber]
        }).then(function (response) {
            var url = response.data.part_urls[String(partNumber)];
            if (!url) {
                throw new Error('无法刷新分片签名');
            }
            task.partUrls[String(partNumber)] = url;
            return url;
        });
    }

    function uploadMultipart(task, details) {
        var partSize = Number(details.part_size);
        var partCount = Number(details.part_count);
        var nextPart = 1;
        var loadedByPart = {};
        var completed = [];
        task.partUrls = details.part_urls || {};

        function totalLoaded() {
            return Object.keys(loadedByPart).reduce(function (total, number) {
                return total + loadedByPart[number];
            }, 0);
        }

        function uploadPart(partNumber) {
            var start = (partNumber - 1) * partSize;
            var end = Math.min(task.native.size, start + partSize);
            var blob = task.native.slice(start, end);
            var attempt = 0;
            var refreshed = false;

            function run() {
                var url = task.partUrls[String(partNumber)];
                return sendBlob(url, blob, task, function (loaded) {
                    loadedByPart[partNumber] = loaded;
                    updateProgress(task, totalLoaded());
                }).then(function (result) {
                    if (!result.etag) {
                        throw new Error('对象存储未暴露 ETag，请检查 Bucket CORS ExposeHeaders');
                    }
                    loadedByPart[partNumber] = blob.size;
                    completed.push({ part_number: partNumber, etag: result.etag });
                    updateProgress(task, totalLoaded());
                }).catch(function (error) {
                    if (task.cancelled || attempt >= Number(config.maxRetries || 4)) {
                        throw error;
                    }
                    attempt += 1;
                    var refresh = (error.status === 401 || error.status === 403) && !refreshed
                        ? refreshPartUrl(task, partNumber).then(function () { refreshed = true; })
                        : Promise.resolve();
                    return refresh.then(function () {
                        return delay(Math.pow(2, attempt - 1) * 500 + Math.floor(Math.random() * 250));
                    }).then(run);
                });
            }
            return run();
        }

        function worker() {
            var partNumber = nextPart;
            nextPart += 1;
            if (partNumber > partCount) {
                return Promise.resolve();
            }
            return uploadPart(partNumber).then(worker);
        }

        var workers = [];
        var concurrency = Math.max(1, Math.min(6, Number(config.concurrency || 3)));
        for (var index = 0; index < Math.min(concurrency, partCount); index += 1) {
            workers.push(worker());
        }
        return Promise.all(workers).then(function () {
            completed.sort(function (left, right) { return left.part_number - right.part_number; });
            return completed;
        });
    }

    function abortRemote(task, keepalive) {
        if (!task.token) {
            return Promise.resolve(null);
        }
        return ajax('wpstow_direct_upload_abort', {
            upload_token: task.token
        }, keepalive).catch(function () { return null; });
    }

    function performDirect(task) {
        var postId = 0;
        if (task.uploader.settings && task.uploader.settings.multipart_params) {
            postId = Number(task.uploader.settings.multipart_params.post_id || 0);
        }
        var dimensionsPromise = imageDimensions(task.native);

        return ajax('wpstow_direct_upload_init', {
            filename: task.file.name,
            mime_type: task.native.type || task.file.type || '',
            file_size: task.native.size,
            post_id: postId
        }).then(function (response) {
            var details = response.data;
            task.token = details.upload_token;
            task.mimeType = details.mime_type || task.native.type || 'application/octet-stream';
            return (details.mode === 'multipart'
                ? uploadMultipart(task, details)
                : uploadSimple(task, details).then(function () { return []; }))
                .then(function (parts) {
                    return dimensionsPromise.then(function (dimensions) {
                        return ajax('wpstow_direct_upload_complete', {
                            upload_token: task.token,
                            parts: JSON.stringify(parts),
                            width: dimensions.width,
                            height: dimensions.height
                        });
                    });
                });
        });
    }

    function finishDirect(task, response) {
        delete tasks[task.file.id];
        task.file.loaded = task.file.size;
        task.file.percent = 100;
        task.file.status = window.plupload.DONE;
        task.uploader.trigger('UploadProgress', task.file);
        task.uploader.trigger('FileUploaded', task.file, {
            response: task.file.attachment ? JSON.stringify(response) : String(response.data.id),
            status: 200,
            responseHeaders: ''
        });
    }

    function fallbackToServer(task, error) {
        if (task.cancelled) {
            return;
        }
        task.requests.forEach(function (request) { request.abort(); });
        abortRemote(task, false).then(function (response) {
            var result = response && response.data;
            if (result && result.completed && result.attachment) {
                finishDirect(task, { data: result.attachment });
                return;
            }

            delete tasks[task.file.id];
            task.file.wpstowDirectBypass = true;
            task.file.loaded = 0;
            task.file.percent = 0;
            task.file.status = window.plupload.QUEUED;
            if (task.file.attachment) {
                task.file.attachment.set({
                    loaded: 0,
                    percent: 0,
                    wpstowFallback: true,
                    wpstowFallbackMessage: (config.messages && config.messages.fallback) || error.message
                });
            }
            task.uploader.stop();
            task.uploader.start();
        });
    }

    function bindUploader(uploader) {
        if (!uploader || uploader.wpstowDirectBound) {
            return;
        }
        uploader.wpstowDirectBound = true;

        uploader.bind('BeforeUpload', function (up, file) {
            if (file.wpstowDirectBypass) {
                delete file.wpstowDirectBypass;
                return true;
            }
            var source = nativeFile(file);
            if (!source || !shouldTry(file)) {
                return true;
            }

            file.status = window.plupload.UPLOADING;
            var task = {
                uploader: up,
                file: file,
                native: source,
                token: '',
                requests: [],
                cancelled: false
            };
            tasks[file.id] = task;
            performDirect(task).then(function (response) {
                if (!task.cancelled) {
                    finishDirect(task, response);
                }
            }).catch(function (error) {
                fallbackToServer(task, error);
            });
            return false;
        });

        uploader.bind('FilesRemoved', function (up, files) {
            files.forEach(function (file) {
                var task = tasks[file.id];
                if (!task) {
                    return;
                }
                task.cancelled = true;
                task.requests.forEach(function (request) { request.abort(); });
                abortRemote(task, false);
                delete tasks[file.id];
            });
        });
    }

    if (window.wp && window.wp.Uploader) {
        var originalInit = window.wp.Uploader.prototype.init;
        window.wp.Uploader.prototype.init = function () {
            if (typeof originalInit === 'function') {
                originalInit.apply(this, arguments);
            }
            bindUploader(this.uploader);
        };
    }

    $(function () {
        if (window.uploader && typeof window.uploader.bind === 'function') {
            bindUploader(window.uploader);
        }
    });

    window.addEventListener('beforeunload', function () {
        Object.keys(tasks).forEach(function (id) {
            var task = tasks[id];
            if (!task.token || !window.navigator.sendBeacon) {
                return;
            }
            var body = new FormData();
            body.append('action', 'wpstow_direct_upload_abort');
            body.append('nonce', config.nonce);
            body.append('upload_token', task.token);
            window.navigator.sendBeacon(config.ajaxUrl, body);
        });
    });
}(window, jQuery));
