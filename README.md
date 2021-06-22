# silverstripe-cfstreamvideo
Silverstripe CloudFlareStream video streaming module

**This Silverstripe module provides video uploading to– and streaming from CloudFlareStream via its API.**  

In includes: 
- [x] A DB StreamVideo object to hold video data & settings, and keep track of the status
- [ ] A StreamVideoPage object providing an upload interface (using FilePond)
- [x] A StreamVideoAdminController to handle interactions in the admin section (like API interactions, providing shortcode placeholder, etc)
- Configurable options (on StreamVideoAdminController) / environment options:
  - [x] (ENV) APP_CFSTREAM_API_HOST, APP_CFSTREAM_ACCOUNT_ID, APP_CFSTREAM_ACCOUNT_EMAIL, APP_CFSTREAM_API_TOKEN
  - [x] (ENV) APP_CFSTREAM_SIGNING_KEY_ID, APP_CFSTREAM_SIGNING_KEY_PEM, APP_CFSTREAM_SIGNING_KEY_JWK
  - [ ] (StreamVideoAdminController::config()) $signed_url_buffer
- The initial version of this module:
  - [x] (1) saves uploaded vids locally, 
  - [x] (2) posts an API request to copy the vid to Stream, 
  - [ ] (3) polls (onload/init) the status and, 
  - [x] (4) (configurable) removes the local video upon status 'ready'
- [ ] Uploads work via FilePond (as [Silverstripe in all their wisdom consider chunked uploads out of scope](https://github.com/silverstripe/silverstripe-assets/issues/421)) using chunking for large uploads ([custom filepond config](https://pqina.nl/filepond/docs/api/server/#process-chunks), based on the yet to be published Restruct Admintweaks module)
  - [ ] A later version may use [FilePond combined with TUS](https://github.com/pqina/filepond/issues/48#issuecomment-439448836) to upload large videos directly to Stream
- [x] A ShortCode + Placeholder are provided for easy inclusion of vids in the editor (also on 'regular' pages)
  - [x] The shortcode hooks into the yet to be published Restruct Shortcodable module
  - [x] The modal has a dropdown to select the video by title/name and checkboxes for options: show controls, autoplay, preload, start muted, p
- [ ] A ModelAdmin interface is included to view & edit existing vids, or upload new ones (without having to create a StreamVideoPage):
  - [ ] Videos can be set to be 'protected' (require a [signed URL via Stream API](https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream))
  - [ ] Videos can have [whitelisted (only) domains & countries](https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream#signed-urls), the default/current website domain is whitelisted by default if whitelisting is active
  - [ ] The initial version allows setting/uploading a custom poster image for a video (overriding the one provided by Stream API)
  - [ ] A later version of this module provides could allow managing signing keys from model admin (using the Stream API)
  - [ ] A later version of this module provides some way/Ui to change/select the poster image (via a Stream API call)
- [ ] Prevent video downloading identical to [this WP module](https://cfpowertools.com/article/cloudflare-stream-wordpress-plugin-for-video-protection/) ([under the hood explanation](https://cfpowertools.com/article/cloudflare-stream-video-protection-wordpress-plugin-in-action/))

### REFS:
- [Stream API docs](https://developers.cloudflare.com/stream/)
- This module: [Listing of most required API calls](/API_REQS_NOTES)
- This module: [Wordpress Stream module (esp. API class)](/z_wpplugin/src/inc/class-cloudflare-stream-api.php)
- [Laravel CloudFlareStream](https://github.com/afloeter/laravel-cloudflare-stream/blob/master/src/CloudflareStream.php)
- Earlier [Restruct HTML5 movies/media module](https://github.com/micschk/silverstripe-html5-media/blob/master/code/TranscodeJob.php) may contain some reusable parts like status polling job (but we probably could do with just checking the status eg from 'canView()' or so...)
