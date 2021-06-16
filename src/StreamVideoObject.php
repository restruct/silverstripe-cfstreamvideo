<?php

namespace Restruct\SilverStripe\StreamVideo;

// use Restruct\Silverstripe\AdminTweaks\Traits\EnforceCMSPermission;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;

/**
 * @property string $UID
 * @property string $Name
 * @property int $Size
 * @property string $PreviewURL
 * @property string $ThumbnailURL
 * @property bool $ReadyToStream
 * @property string $StatusState
 * @property string $StatusErrors
 * @property string $StatusMessages
 * @property bool $RequireSignedURLs
 * @method \SilverStripe\Assets\Image PosterImage()
 */
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
        'StatusState' => "Enum(',downloading,queued,inprogress,ready,error')",
        'StatusErrors' => DBText::class,
        'StatusMessages' => DBText::class,
        // Access Controls
        'RequireSignedURLs' => DBBoolean::class,
    ];

    private static $indexes = [
        "UID" => true,
    ];

    private static $has_one = [
        'PosterImage' => Image::class,
    ];

    private static $summary_fields = [
        'StreamOrCustomPosterImage' => 'Poster Image',
        'Name' => "Name",
        "StatusState" => "State",
    ];

    private static $field_labels = [
        "RequireSignedURLs" => "Require Signed URLs"
    ];

    public function validate()
    {
        $result = parent::validate();

        if (!$this->UID) {
            $result->addError("A video needs an UID");
        }

        return $result;
    }

    /**
     * @param string $uid
     * @return StreamVideoObject
     */
    public static function getByUID($uid)
    {
        return self::get()->filter('UID', $uid)->first();
    }

    public function StreamOrCustomPosterImage($width = 100)
    {
        $url = $this->ThumbnailURL;
        if ($this->PosterImageID) {
            $url = $this->PosterImage()->getURL();
        }
        // 16:9 format
        $height = $width / 16 * 9;
        $html = "<img src=\"{$url}\" height=\"{$height}\" width=\"{$width}\" />";
        $text = DBHTMLText::create("StreamOrCustomPosterImage");
        $text->setValue($html);
        return $text;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        /** @var UploadField $poster */
        if ($poster = $fields->dataFieldByName('PosterImage')) {
            $poster->setAllowedFileCategories('image')
                ->setFolderName('video-poster-imgs')
                ->setAllowedMaxFileNumber(1);
        }

        if (isset($_GET['debug'])) {
            $apiDetails = json_encode(CloudflareStreamHelper::getApiClient()->videoDetails($this->UID), JSON_PRETTY_PRINT);
            $debugField = new LiteralField("DebugApi", "<pre>" . $apiDetails . "</pre>");
            $fields->addFieldToTab("Root.Debug", $debugField);
        }

        return $fields;
    }

    public function setDataFromApi($record)
    {
        // Sample record
        // {
        //     "uid": "3d96d64e6eda7c2356349axxxxxxxxxx",
        //     "thumbnail": "https://videodelivery.net/3d96d64e6eda7c2356349axxxxxxxxxx/thumbnails/thumbnail.jpg",
        //     "thumbnailTimestampPct": 0,
        //     "readyToStream": true, // or false
        //     "status": {
        //       "state": "ready", // or "downloading"
        //       "pctComplete": "100.000000"
        //     },
        //     "meta": {
        //       "filename": "name_of_the_video.mp4",
        //       "filetype": "video/mp4",
        //       "name": "Name of the video",
        //       "relativePath": "null",
        //       "type": "video/mp4",
        //       "downloaded-from": "https://domain.tld/video.mp4"
        //     },
        //     "created": "2021-06-15T09:14:50.898834Z",
        //     "modified": "2021-06-15T09:32:37.122475Z",
        //     "size": 52151551,
        //     "preview": "https://watch.videodelivery.net/3d96d64e6eda7c2356349axxxxxxxxxx",
        //     "allowedOrigins": [
        //       "hartlongcentrum.nl"
        //     ],
        //     "requireSignedURLs": false,
        //     "uploaded": "2021-06-15T09:14:50.898826Z",
        //     "uploadExpiry": "2021-06-16T09:14:50.898817Z",
        //     "maxSizeBytes": null,
        //     "maxDurationSeconds": null,
        //     "duration": 133.9, // -1 if not processed
        //     "input": {
        //       "width": 1920, // -1 if not processed
        //       "height": 1080 // -1 if not processed
        //     },
        //     "playback": {
        //       "hls": "https://videodelivery.net/3d96d64e6eda7c2356349axxxxxxxxxx/manifest/video.m3u8",
        //       "dash": "https://videodelivery.net/3d96d64e6eda7c2356349axxxxxxxxxx/manifest/video.mpd"
        //     },
        //     "watermark": null
        //   }

        $this->UID = $record->uid;
        $this->ThumbnailURL = $record->thumbnail;
        $this->Name = $record->meta->name ?? '';
        $this->Size = $record->size;
        $this->PreviewURL = $record->preview;
        $this->ReadyToStream = $record->readyToStream;
        $this->StatusState = $record->status->state;
        $this->RequireSignedURLs = $record->requireSignedURLs;
    }
}
