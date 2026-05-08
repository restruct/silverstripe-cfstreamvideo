<?php

namespace Restruct\SilverStripe\StreamVideo\Forms;

use Restruct\SilverStripe\StreamVideo\Controllers\StreamVideoAdminController;
use SilverStripe\Forms\FormField;
use SilverStripe\View\Requirements;

/**
 * CMS form field for direct-to-Cloudflare TUS video uploads.
 * Video goes directly from browser to CF Stream — never touches our server.
 */
class TusUploadField extends FormField
{
    protected $schemaDataType = 'Custom';

    /**
     * Max video duration in seconds (passed to CF API)
     */
    protected int $maxDuration = 3600;

    public function setMaxDuration(int $seconds): static
    {
        $this->maxDuration = $seconds;
        return $this;
    }

    public function Field($properties = [])
    {
        // URL to request upload URL from our controller
        $createUrlEndpoint = StreamVideoAdminController::singleton()->Link('create_upload_url');

        $fieldId = $this->ID();
        $name = $this->getName();
        $value = $this->Value();
        $maxDuration = $this->maxDuration;

        // Include tus-js-client from CDN
        Requirements::javascript('https://cdn.jsdelivr.net/npm/tus-js-client@4/dist/tus.min.js');

        $html = <<<HTML
<div id="{$fieldId}_wrapper" class="tus-upload-field">
    <input type="hidden" name="{$name}" id="{$fieldId}" value="{$value}" />

    <div class="tus-upload-controls">
        <input type="file" id="{$fieldId}_file" accept="video/*" class="form-control" style="max-width:400px; display:inline-block;" />
        <button type="button" id="{$fieldId}_btn" class="btn btn-primary btn-sm ms-2" disabled>Upload</button>
        <span id="{$fieldId}_status" class="ms-2 text-muted"></span>
    </div>

    <div id="{$fieldId}_progress" class="progress mt-2" style="display:none; max-width:400px;">
        <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
    </div>

    <div id="{$fieldId}_result" class="mt-2 text-success" style="display:none;"></div>
</div>

<script>
(function() {
    var fieldId = '{$fieldId}';
    var createUrlEndpoint = '{$createUrlEndpoint}';
    var maxDuration = {$maxDuration};

    var fileInput = document.getElementById(fieldId + '_file');
    var uploadBtn = document.getElementById(fieldId + '_btn');
    var statusEl = document.getElementById(fieldId + '_status');
    var progressEl = document.getElementById(fieldId + '_progress');
    var progressBar = progressEl.querySelector('.progress-bar');
    var resultEl = document.getElementById(fieldId + '_result');
    var hiddenInput = document.getElementById(fieldId);

    fileInput.addEventListener('change', function() {
        uploadBtn.disabled = !fileInput.files.length;
        statusEl.textContent = fileInput.files.length ? fileInput.files[0].name : '';
    });

    uploadBtn.addEventListener('click', function() {
        var file = fileInput.files[0];
        if (!file) return;

        uploadBtn.disabled = true;
        fileInput.disabled = true;
        statusEl.textContent = 'Upload URL aanvragen...';
        progressEl.style.display = 'block';

        // Step 1: Get direct upload URL from our server
        fetch(createUrlEndpoint + '?maxDuration=' + maxDuration, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.uploadUrl) throw new Error('No upload URL received');

            statusEl.textContent = 'Uploaden...';

            // Step 2: Upload directly to CF via TUS
            var upload = new tus.Upload(file, {
                endpoint: data.uploadUrl,
                // CF Stream uses the upload URL as-is (no creation step needed)
                uploadUrl: data.uploadUrl,
                retryDelays: [0, 1000, 3000, 5000],
                chunkSize: 50 * 1024 * 1024, // 50MB chunks
                metadata: {
                    filename: file.name,
                    filetype: file.type,
                },
                onError: function(error) {
                    statusEl.textContent = 'Upload mislukt: ' + error.message;
                    uploadBtn.disabled = false;
                    fileInput.disabled = false;
                    progressEl.style.display = 'none';
                },
                onProgress: function(bytesUploaded, bytesTotal) {
                    var pct = Math.round(bytesUploaded / bytesTotal * 100);
                    progressBar.style.width = pct + '%';
                    progressBar.textContent = pct + '%';
                },
                onSuccess: function() {
                    // Extract video UID from the upload URL
                    // CF upload URLs look like: https://upload.videodelivery.net/tus/ACCOUNT_ID/VIDEO_UID?tusv2=true
                    var urlParts = upload.url.split('/');
                    var uid = urlParts[urlParts.length - 1].split('?')[0];

                    hiddenInput.value = uid;
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100%';
                    progressBar.classList.add('bg-success');
                    statusEl.textContent = '';
                    resultEl.textContent = 'Video geüpload (UID: ' + uid + ')';
                    resultEl.style.display = 'block';
                }
            });

            upload.start();
        })
        .catch(function(err) {
            statusEl.textContent = 'Fout: ' + err.message;
            uploadBtn.disabled = false;
            fileInput.disabled = false;
            progressEl.style.display = 'none';
        });
    });
})();
</script>
HTML;

        return $html;
    }

    public function Type()
    {
        return 'tus-upload';
    }
}
