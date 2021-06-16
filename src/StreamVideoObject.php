<?php

namespace Restruct\SilverStripe\StreamVideo;

// use Restruct\Silverstripe\AdminTweaks\Traits\EnforceCMSPermission;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;

class StreamVideoObject extends DataObject
{
    // use EnforceCMSPermission;

    private static $table_name = 'StreamVideoObject';

    private static $db = [
        'UID' => DBVarchar::class . '(100)',
        'Name' => DBVarchar::class . '(200)',
        'Size' => DBInt::class,
        'PreviewURL' => DBVarchar::class . '(100)',
        'ThumbnailURL' => DBVarchar::class . '(100)',
        'ReadyToStream' => DBBoolean::class,
        'StatusState' => DBVarchar::class,
        'StatusErrors' => DBText::class,
        'StatusMessages' => DBText::class,
        // Access Controls
        'RequireSignedURLs' => DBBoolean::class,
        // ... (additional stuff)

        //        "result": {
        //            "uid": "a96d40583f3fd1d2676182e3e15f9383",
        //            "thumbnail": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/thumbnails/thumbnail.jpg",
        //            "thumbnailTimestampPct": 0,
        //            "readyToStream": false,
        //            "status": {
        //              "state": "downloading"
        //            },
        //        "meta": {
        //            "downloaded-from": "https://domain.tld/video.mp4",
        //              "name": "TESTVID"
        //            },
        //            "created": "2021-06-14T07:49:04.1555Z",
        //            "modified": "2021-06-14T07:49:04.1555Z",
        //            "size": 70603950,
        //            "preview": "https://watch.videodelivery.net/a96d40583f3fd1d2676182e3e15f9383",
        //            "allowedOrigins": [],
        //            "requireSignedURLs": false,
        //            "uploaded": "2021-06-14T07:49:04.155489Z",
        //            "uploadExpiry": null,
        //            "maxSizeBytes": null,
        //            "maxDurationSeconds": null,
        //            "duration": -1,
        //            "input": {
        //            "width": -1,
        //              "height": -1
        //            },
        //            "playback": {
        //            "hls": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/manifest/video.m3u8",
        //              "dash": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/manifest/video.mpd"
        //            },
        //            "watermark": null
        //          },
        //          "success": true,
        //          "errors": [],
        //          "messages": []
    ];

    private static $has_one = [
        'PosterImage' => Image::class,
    ];

    private static $summary_fields = [
        'StreamOrCustomPosterImage' => 'Poster Image',
        'Name',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        /** @var UploadField $poster */
        if ($poster = $fields->dataFieldByName('PosterImage')) {
            $poster->setAllowedFileCategories('image')
                ->setFolderName('video-poster-imgs')
                ->setAllowedMaxFileNumber(1);
        }

        return $fields;
    }
}
