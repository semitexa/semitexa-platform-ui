/**
 * Semitexa Platform UI — uploads (`platform.field` with `control: 'file'`).
 *
 * Choosing a file sends it straight away, through HUG, as multipart
 * `{upload: <signed upload context>, file}` (XHR: fetch has no upload
 * progress). The server checks size and the type the bytes really are, keeps
 * the file under a name it chose, and answers with a signed one-time ticket.
 * The ticket — never the file — becomes the field's value (its hidden
 * `data-ui-part="input"`), so validation, `required` and the form snapshot work
 * exactly as for any field, and the form action redeems it.
 *
 * Events on the field's upload root (bubbling): ui-upload:start,
 * ui-upload:progress {loaded, total, percent}, ui-upload:done {name, size, type},
 * ui-upload:error {reason, message}, and ui-upload:end after either. While a
 * file is on its way the root carries `data-ui-uploading` (a form submit waits
 * for it).
 *
 * A file over the field's limit (`data-ui-upload-max`, the same number signed
 * into the context) is refused here, before any byte is sent: a request over the
 * server's own limit would be dropped by Swoole and read as a network failure.
 * The server still checks — this only spares the visitor the wait.
 */
import { withCsrf, mount, HUG_PATH } from 'platform-ui/core';

function emit(root, name, detail) {
    root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} }));
}

/** The receiver's wording, so the message is the same from either side. */
function humanBytes(bytes) {
    return bytes >= 1048576 ? (Math.round((bytes / 1048576) * 10) / 10) + ' MB' : Math.ceil(bytes / 1024) + ' KB';
}

function connect(fileInput) {
    const root = fileInput.closest('[data-ui-upload-root]');
    if (!root) return null;
    const value = root.querySelector('[data-ui-part="input"]');
    const progress = root.querySelector('[data-ui-upload-progress]');
    const status = root.querySelector('[data-ui-upload-status]');
    let xhr = null;

    function settle(ticket, message) {
        value.value = ticket;
        if (status) status.textContent = message;
        // The field validates its new value like any change.
        value.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function finish() {
        xhr = null;
        root.removeAttribute('data-ui-uploading');
        if (progress) progress.hidden = true;
        emit(root, 'ui-upload:end');
    }

    function send(file) {
        if (xhr) xhr.abort();
        const max = parseInt(fileInput.getAttribute('data-ui-upload-max') || '', 10);
        if (max > 0 && file.size > max) {
            const message = 'The file is larger than ' + humanBytes(max) + '.';
            settle('', message);
            emit(root, 'ui-upload:error', { reason: 'upload_too_large', message });
            // finish(), not just the end event: an upload this file aborted
            // never reaches its own finish(), and data-ui-uploading would keep
            // a form submit waiting forever.
            finish();
            return;
        }
        const body = new FormData();
        body.append('upload', fileInput.getAttribute('data-ui-upload') || '');
        body.append('file', file);
        xhr = new XMLHttpRequest();
        const request = xhr;
        request.open('POST', HUG_PATH);
        request.withCredentials = true;
        const headers = withCsrf('POST', { Accept: 'application/json' });
        Object.keys(headers).forEach((h) => request.setRequestHeader(h, headers[h]));
        request.upload.addEventListener('progress', (e) => {
            if (!e.lengthComputable) return;
            const percent = Math.round((e.loaded / e.total) * 100);
            if (progress) progress.value = percent;
            emit(root, 'ui-upload:progress', { loaded: e.loaded, total: e.total, percent });
        });
        request.addEventListener('load', () => {
            if (request !== xhr) return; // superseded by a newer file
            let reply = null;
            try { reply = JSON.parse(request.responseText); } catch (e) { reply = null; }
            if (request.status === 200 && reply && typeof reply.ticket === 'string') {
                settle(reply.ticket, String(reply.name || file.name));
                emit(root, 'ui-upload:done', { name: reply.name, size: reply.size, type: reply.type });
            } else {
                const message = reply && typeof reply.message === 'string' ? reply.message : 'The upload failed.';
                settle('', message);
                emit(root, 'ui-upload:error', { reason: reply && reply.reason ? String(reply.reason) : 'http_' + request.status, message });
            }
            finish();
        });
        const failed = () => {
            if (request !== xhr) return;
            settle('', 'The upload failed.');
            emit(root, 'ui-upload:error', { reason: 'network', message: 'The upload failed.' });
            finish();
        };
        request.addEventListener('error', failed);
        request.addEventListener('timeout', failed);
        root.setAttribute('data-ui-uploading', '');
        if (progress) { progress.value = 0; progress.hidden = false; }
        if (status) status.textContent = '';
        emit(root, 'ui-upload:start', { name: file.name, size: file.size });
        request.send(body);
    }

    const onChange = () => {
        const file = fileInput.files && fileInput.files[0];
        if (file) { send(file); return; }
        // Selection cleared mid-upload: that upload must not land a ticket.
        if (xhr) { xhr.abort(); finish(); }
        settle('', '');
    };
    fileInput.addEventListener('change', onChange);
    // A form reset clears the chosen file; the ticket goes with it.
    const form = fileInput.form;
    const onReset = () => setTimeout(() => { value.value = ''; if (status) status.textContent = ''; }, 0);
    if (form) form.addEventListener('reset', onReset);

    return {
        destroy() {
            if (xhr) xhr.abort();
            fileInput.removeEventListener('change', onChange);
            if (form) form.removeEventListener('reset', onReset);
        },
    };
}

mount('input[type="file"][data-ui-upload]', { connect });
