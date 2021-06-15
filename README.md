# silverstripe-cfstreamvideo
Silverstripe Cloudfront STREAM video streaming module

**This Silverstripe module provides video uploading to– and streaming from CloudFront Stream via its API.**  
In includes: 
- [ ] A DB StreamVideo object to hold video data & settings, and keep track of the status
- [ ] A StreamVideoPage object providing an upload interface (using FilePond)
- [ ] A StreamVideoAdminController to handle interactions in the admin section (like API interactions, providing shortcode placeholder, etc)
  - [ ] Uploads work via FilePond (as Silverstripe in all their wisdom consider chunked uploads unnecessary) using chunking for large uploads (custom filepond config, depends on the yet to be published Restruct Admintweaks module)
  - [ ] The initial version of this module:
    - [ ] (1) saves uploaded vids locally, 
    - [ ] (2) posts an API request to copy the vid to Stream, 
    - [ ] (3) polls (onload/init) the status and, 
    - [ ] (4) (configurable) removes the local video upon status 'ready'
  - [ ] A later version may use [FilePond combined with TUS](https://github.com/pqina/filepond/issues/48#issuecomment-439448836) to upload large videos directly to Stream
- [ ] A ShortCode + Placeholder are provided for easy inclusion of vids in the editor (also on 'regular' pages), these depend on the yet to be published Restruct Shortcodable module
- [ ] A ModelAdmin interface is included to view & edit existing vids, or upload new ones (without having to create a StreamVideoPage):
  - [ ] Videos can be set to be 'protected' (require a signed URL via Stream API)
  - [ ] A later version of this module provides some way/Ui to change/select the poster image (via a Stream API call)
