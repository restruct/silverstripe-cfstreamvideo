<?php

namespace Restruct\SilverStripe\StreamVideo\Model;

use Exception;
use LeKoala\FilePond\FilePondField;
use Restruct\SilverStripe\StreamVideo\Controllers\StreamVideoAdminController;
use Restruct\SilverStripe\StreamVideo\Shortcodes\CloudflareStreamShortcode;
use Restruct\SilverStripe\StreamVideo\StreamApi\CloudflareStreamApiClient;
use Restruct\SilverStripe\StreamVideo\StreamApi\CloudflareStreamHelper;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob;

/**
 * @property string $UID
 * @property string $Name
 * @property int $Size
 * @property string $PreviewURL
 * @property string $ThumbnailURL
 * @property float $ThumbnailTimestamp
 * @property float $ThumbnailTimestampPct
 * @property bool $ReadyToStream
 * @property string $StatusState
 * @property string $StatusErrors
 * @property string $StatusMessages
 * @property bool $RequireSignedURLs
 * @property bool $AllowedOrigins
 * @property int $Width
 * @property int $Height
 * @property float $Duration
 * @property int $PosterImageID
 * @property int $VideoID
 * @method \SilverStripe\Assets\Image PosterImage()
 * @method \SilverStripe\Assets\File Video()
 */
class StreamVideoObject extends DataObject
{
    use QueuedVideo;

    private static $table_name = 'StreamVideoObject';

    public function singular_name()
    {
        return $this->fieldLabel('SingularName');
    }
    public function plural_name()
    {
        return $this->fieldLabel('PluralName');
    }

    /**
     * @config
     * @var boolean
     */
    private static $keep_local_video = false;

    /**
     * @config What to do with the video record in CFStream upon deleting the local record
     * @var string options: keep = do nothing, rename = prepend 'DELETED: ' to video name, delete = remove in Stream right away
     */
    private static $stream_video_action_on_delete_record = 'mark';

    /**
     * @config
     * @var boolean
     */
    private static $upload_from_qjob_if_available = false;

    /**
     * @config
     * @var boolean
     */
    private static $create_token_with_api = false;

    /**
     * @config
     * @var int
     */
    private static $signed_buffer_seconds = 600;

    /**
     * @config
     * @var string
     */
    private static $video_folder = 'video-stream';

    /**
     * @config
     * @var string
     */
    private static $poster_folder = 'video-poster-imgs';

    /**
     * @config
     * @var int default percentage of total video duration to create thumbnail from
     */
    private static $default_thumbnail_timestamp_pct = 0.1;

    // Facilitate uploading as QueuedJob
    public $ExecuteFree = 'never';
    private function scheduleUploadJob($write = true)
    {
        if ($this->StatusState || !ClassInfo::exists('Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob')) {
            return false;
        }
        // using full namespaced classnames because qjobs module may not be installed (so we cannot use imports)
        $qJobDescrID = singleton(\Symbiote\QueuedJobs\Services\QueuedJobService::class)->queueJob(
            new \Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob($this)
        );
        $this->StatusState = CloudflareStreamApiClient::STATUS_SCHEDULED;
        if ($write) {
            $this->write(); // triggers double write because called from onAfterWrite but only once/when $this->StatusState is not yet set
        }

        return $qJobDescrID;
    }
    public function onScheduledExecution()
    {
        return $this->postLocalVideo();
    }

    private static $db = [
        'UID' => DBVarchar::class . '(100)',
        'Name' => DBVarchar::class . '(200)',
        'Info' => DBText::class,
        'Size' => DBInt::class,
        'PreviewURL' => DBVarchar::class . '(100)',
        'ThumbnailURL' => DBVarchar::class . '(100)',
        'ReadyToStream' => DBBoolean::class,
        // scheduled = waiting to be uploaded by scheduled background job...
        'StatusState' => DBEnum::class . "('scheduled,downloading,queued,inprogress,ready,error', null)",
        'StatusErrors' => DBText::class,
        'StatusMessages' => DBText::class,
        // Access Controls
        'RequireSignedURLs' => DBBoolean::class,
        'AllowedOrigins' => DBText::class,
        'Width' => DBInt::class,
        'Height' => DBInt::class,
        'Duration' => DBDecimal::class, // in seconds
        'ThumbnailTimestamp' => DBFloat::class, // in seconds
        'ThumbnailTimestampPct' => DBFloat::class, //
    ];

    private static $indexes = [
        "UID" => [
            'type' => 'unique',
            'columns' => ['UID'],
        ],
    ];

    private static $has_one = [
        'PosterImage' => Image::class,
        'Video' => File::class,
    ];

    private static $owns = [
        'PosterImage', 'Video'
    ];

    private static $summary_fields = [
        'PreviewImageSvg',
        'Name',
        "StatusState",
        "UID",
        "AllowedOrigins",
    ];

    public function fieldLabels($includerelations = true)
    {
        return array_merge(
            parent::fieldLabels($includerelations),
            [
                'SingularName' => _t(__CLASS__ . '.SingularName', 'Stream Video'),
                'PluralName' => _t(__CLASS__ . '.PluralName', 'Stream Videos'),
                //                'Title' => _t(__CLASS__.'.Title', 'Video Title'),
                'PreviewImageSvg' => _t(__CLASS__ . '.PreviewImageSvg', 'Video Preview Image'),
                'UID' => _t(__CLASS__ . '.UID', 'Stream Video ID (UID)'),
                'AddExistingUID_descr' => _t(__CLASS__ . '.AddExistingUID_descr', 'Optional: add an existing Video ID from your Stream account (instead of adding/uploading a new one)'),
                'Name' => _t(__CLASS__ . '.Name', 'Video Name'),
                'Info' => _t(__CLASS__ . '.Info', 'Video Info (eg credits)'),
                'ThumbnailTimestamp' => _t(__CLASS__ . '.ThumbnailTimestamp', 'Timeframe (in seconds) to use as preview image'),
                'ThumbnailTimestamp_descr' => _t(__CLASS__ . '.ThumbnailTimestamp_descr', '(generating a new poster image may take up to 10-20 seconds, <a href="javascript:location.reload();">refresh this page</a> to see the new image)'),
                'ThumbnailTimestampPct' => _t(__CLASS__ . '.ThumbnailTimestampPct', 'Timeframe (in procent) of preview image'),
                'Size' => _t(__CLASS__ . '.Size', 'Filesize'),
                'PreviewURL' => _t(__CLASS__ . '.PreviewURL', 'Preview URL'),
                'PreviewPlayer' => _t(__CLASS__ . '.PreviewPlayer', 'Video Player Preview'),
                'ThumbnailURL' => _t(__CLASS__ . '.ThumbnailURL', 'Thumbnail URL'),
                'ReadyToStream' => _t(__CLASS__ . '.ReadyToStream', 'Ready to stream?'),
                'StatusState' => _t(__CLASS__ . '.StatusState', 'Status'),
                'StatusErrors' => _t(__CLASS__ . '.StatusErrors', 'Errors'),
                'StatusMessages' => _t(__CLASS__ . '.StatusMessages', 'Messages'),
                'RequireSignedURLs' => _t(__CLASS__ . '.RequireSignedURLs', 'Use Signed URLs (time-bound viewing)'),
                'SigningKeyMissing' => _t(__CLASS__ . '.SigningKeyMissing', 'Signing Key missing, cannot creaet signed URLs.'),
                'SigningKeyAdminCreate' => _t(
                    __CLASS__ . '.SigningKeyAdminCreate',
                    '<a href="{generate_signing_key_link}" target="_blank">Generate one</a> (ADMINs only).',
                    ['generate_signing_key_link' => StreamVideoAdminController::singleton()->Link('generate_signing_key')]
                ),
                'AllowedOrigins' => _t(__CLASS__ . '.AllowedOrigins', 'Allowed Origins'),
                'AllowedOrigins_descr' => _t(__CLASS__ . '.AllowedOrigins_descr', 'Restrict viewing to these specific domain names (one per line)'),
                'Width' => _t(__CLASS__ . '.Width', 'Video Width'),
                'Height' => _t(__CLASS__ . '.Height', 'Video Height'),
                'Video' => _t(__CLASS__ . '.Video', 'Video source'),
                'PosterImage' => _t(__CLASS__ . '.PosterImage', 'Custom video preview image'),
                'PosterImage_descr' => _t(__CLASS__ . '.PosterImage_descr', '(Default image shown next to upload-field) '),
                // Some shortcodable form labels
                "sc_video" => _t(__CLASS__ . '.sc_video', "Select video"),
                "sc_hide_controls" => _t(__CLASS__ . '.sc_hide_controls', "Hide play/pause controls"),
                "sc_autoplay" => _t(__CLASS__ . '.sc_autoplay', "Start playing automatically"),
                "sc_loop" => _t(__CLASS__ . '.sc_loop', "Loop this video"),
                "sc_muted" => _t(__CLASS__ . '.sc_muted', "Mute audio initially"),
                "sc_preload" => _t(__CLASS__ . '.sc_preload', "Suggest preload"),
                'sc_preload_none' => _t(__CLASS__ . '.sc_preload_none', "Let browser decide (default)"),
                'sc_preload_metadata' => _t(__CLASS__ . '.sc_preload_metadata', "Prepare/preload only metadata"),
                'sc_preload_auto' => _t(__CLASS__ . '.sc_preload_auto', "Preload the beginning of the video"),
            ]
        );
    }

    /**
     * @return string
     */
    public function getPosterImageUrlFromApi()
    {
        $url = $this->ThumbnailURL;
        if ($this->RequireSignedURLs) {
            $token = CloudflareStreamHelper::getApiClient()->getSignedToken($this->UID);
            $url = str_replace($this->UID, $token, $url);
        }
        return $url;
    }

    public function getCMSFields()
    {
        // Somehow the video is deleted but the id is still set
        if ($this->VideoID && !$this->Video()) {
            $this->VideoID = 0;
        }

        Requirements::javascript("restruct/silverstripe-cfstreamvideo:client/scripts/utils.js");
        $fields = parent::getCMSFields();
        $infoField = $fields->dataFieldByName('Info')->setRows(1);
        $nameField = $fields->dataFieldByName('Name');
        $UIDField = $fields->dataFieldByName('UID');
        $fields->dataFieldByName('ThumbnailTimestamp')->setDescription($this->fieldLabel('ThumbnailTimestamp_descr'));

        if ((!$this->UID && !$this->VideoID) || $this->StatusState === CloudflareStreamApiClient::STATUS_SCHEDULED) {
            $fields = new FieldList();
            $fields->push(new TabSet("Root", $mainTab = new Tab("Main")));
            $fields->addFieldToTab('Root.Main', $nameField);
            $fields->addFieldToTab('Root.Main', $UIDField->setDescription($this->fieldLabel('AddExistingUID_descr')));

            if (!$this->StatusState) {
                if (class_exists(FilePondField::class)) {
                    $fields->push($Video = new FilePondField("Video"));
                    // @TODO: temp bugfix in FilePond, we need to also set maxFileSize manually for JS config (besides setAllowedMaxFileSize)
                    $Video->addFilePondConfig('maxFileSize', Convert::memstring2bytes('1GB'));
                    $Video->setChunkUploads(true);
                } else {
                    $fields->push($Video = new UploadField("Video"));
                }
                $Video->setFolderName(self::config()->video_folder);
                $Video->setAllowedMaxFileNumber(1);
                $Video->getValidator()->setAllowedMaxFileSize(['*' => '1GB']);
                // MP4, MKV, MOV, AVI, FLV, MPEG-2 TS, MPEG-2 PS, MXF, LXF, GXF, 3GP, WebM, MPG, QuickTime
                // https://developers.cloudflare.com/stream/faq#what-input-file-formats-are-supported
                $Video->getValidator()->setAllowedExtensions(['mp4', 'mkv', 'mov', 'avi', 'flv', 'vob', 'mxf', 'lxf', 'gxf', '3gp', 'webm', 'mpg']);
                $Video->setDescription(
                    'A video file of maximum ' . File::format_size($Video->getValidator()->getAllowedMaxFileSize())
                        . ', most video file formats are supported (eg MP4, MKV, MOV, AVI, WebM, MPG, QuickTime, etc)'
                );
            }
        } else {
            if ($this->UID) {
                $result = $this->refreshDataFromApi();
                // Add warning
                if (!$result) {
                    $VideoDeletedMessage = _t(__CLASS__ . '.VideoDeletedMessage', 'The video was deleted on cloudflare servers. Save to remove UID.');
                    $fields->insertBefore("Name", new LiteralField("AlertVideoDeleted", '<div class="message bad">' . $VideoDeletedMessage . '</div>'));
                }
                $fields->removeByName("Video");
            }
            $techFields = [
                "UID",
                "Size",
                "Width",
                "Height",
                "Duration",
                "ThumbnailTimestampPct",
                "PreviewURL",
                "ThumbnailURL",
                "ReadyToStream",
                "StatusState",
                "StatusErrors",
                "StatusMessages",
            ];
            foreach ($techFields as $fieldName) {
                $fields->addFieldToTab('Root.Details', $fields->dataFieldByName($fieldName));
            }
            $fields->makeFieldReadonly($techFields);

            if (CloudflareStreamHelper::getSigningKey() && $this->UID) {
                // We can enable signed urls
            } else {
                $fields->makeFieldReadonly("RequireSignedURLs");
                $fields->dataFieldByName('RequireSignedURLs')->setDescription($this->fieldLabel('SigningKeyMissing'));
                if (Permission::check('ADMIN')) {
                    $fields->dataFieldByName('RequireSignedURLs')->setDescription(
                        $this->fieldLabel('SigningKeyMissing')
                            . ' ' . $this->fieldLabel('SigningKeyAdminCreate')
                    );
                }
            }
        }

        if ($AllowedOrigins = $fields->dataFieldByName("AllowedOrigins")) {
            $AllowedOrigins
                ->setDescription($this->fieldLabel('AllowedOrigins_descr'))
                ->setRows(2);
        }

        /** @var UploadField $poster */
        if ($poster = $fields->dataFieldByName('PosterImage')) {
            $thumbnailUrl = $this->getPosterImageUrlFromApi();
            $poster->setAllowedFileCategories('image')
                ->setFolderName(self::config()->poster_folder)
                ->setAllowedMaxFileNumber(1)
                ->setDescription($this->fieldLabel('PosterImage_descr'))
                ->setRightTitle(
                    $this->ThumbnailURL ? DBHTMLVarchar::create()->setValue("<img src=\"{$thumbnailUrl}?{$this->LastEdited}\" style=\"width:auto;height:70px;margin-top:-.25rem;\" />") : ''
                );
        }

        if ($this->UID) {
            $ShortCode = "<pre style=\"cursor:pointer;padding:1em;background:#fff\"
                    onclick=\"copyToClipboard(this.innerText);jQuery.noticeAdd({text:'Copied to clipboard'})\"
                >[" . Config::inst()->get(CloudflareStreamShortcode::class, 'shortcode') . " id={$this->ID}]</pre>
                <p><em>Click on shortcode to copy to clipboard</em></p>";
            $wrappedShortCode = '<div class="form-group field"><label class="form__field-label">Shortcode</label><div class="form__field-holder">' . $ShortCode . '</div></div>';
            $fields->addFieldToTab("Root.Main", LiteralField::create("ShortCodeInfo", $wrappedShortCode));
        }

        if ($this->UID && $this->StatusState === CloudflareStreamApiClient::STATUS_READY) {
            $ratio = $this->Height / $this->Width * 100;
            $vidPlayer = CloudflareStreamHelper::getApiClient()->iframePlayer($this->UID, [], $this->RequireSignedURLs, $ratio, self::config()->signed_buffer_seconds);
            $vidWidth = 260 * (1 / $this->Height * $this->Width);
            $wrappedVidPlayer = '<div class="form-group field"><label class="form__field-label">' . $this->fieldLabel('PreviewPlayer') . '</label><div class="form__field-holder" style="max-width:' . $vidWidth . 'px;">' . $vidPlayer . '</div></div>';
            $fields->addFieldToTab("Root.Main", LiteralField::create("VideoPlayer", $wrappedVidPlayer));
        } elseif ($this->ID) {
            $wrappedPreview = '<div class="form-group field"><label class="form__field-label"></label><div class="form__field-holder">' . $this->PreviewImageSvg(260) . '</div></div>';
            $fields->addFieldToTab("Root.Main", LiteralField::create("VideoPreview", $wrappedPreview));
        }

        if (isset($_GET['debug'])) {
            $apiDetails = json_encode(CloudflareStreamHelper::getApiClient()->videoDetails($this->UID), JSON_PRETTY_PRINT);
            $debugField = new LiteralField("DebugApi", "<pre>" . $apiDetails . "</pre>");
            $fields->addFieldToTab("Root.Debug", $debugField);
        }

        return $fields;
    }

    public function validate()
    {
        $result = parent::validate();

        if ($this->ID && (!$this->UID && !$this->VideoID)) {
            $result->addError("A video needs an UID or a local video");
        }

        return $result;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Set name from file
        if ($this->VideoID && !$this->Name) {
            $this->Name = $this->Video()->getTitle();
        }

        // If UID (= upload to Stream API done), check if we can update some stuff from/to the CF API
        if ($this->UID) {
            $client = CloudflareStreamHelper::getApiClient();
            $vidMeta = $client->getVideoMeta($this->UID);

            // Update name from CF if empty
            if (!$this->Name && !empty($vidMeta['name'])) {
                $this->Name = $vidMeta['name'];
            }

            // Update video details from our fields
            $changed = $this->getChangedFields(true, self::CHANGE_VALUE);
            if (!empty($changed) || empty($vidMeta['name'])) {
                if (isset($changed['Name']) || empty($vidMeta['name'])) {
                    $client->setVideoMeta($this->UID, "name", $this->Name);
                }
                if (isset($changed['RequireSignedURLs'])) {
                    $client->setSignedURLs($this->UID, $this->RequireSignedURLs);
                }
                if (isset($changed['AllowedOrigins'])) {
                    $client->setAllowedOrigins($this->UID, $this->getAllowedOriginsAsArray());
                }
                if (isset($changed['ThumbnailTimestamp']) && $this->Duration) {
                    $client->setThumbnailTimestampPct($this->UID, 1 / $this->Duration * $this->ThumbnailTimestamp);
                }
            }

            // Refresh state
            if (!$this->IsReady()) {
                $this->refreshDataFromApi(false);
            }

            // Check width
            if ($this->Width <= 0) {
                $dimensions = $client->getDimensions($this->UID);
                $this->Width = $dimensions->width;
                $this->Height = $dimensions->height;
            }
        }
    }

    protected function onAfterWrite()
    {
        parent::onAfterWrite();

        // If not already scheduled for uploading from onBeforeWrite, send to api now (possibly blocking further execution until done)
        if ($this->VideoID && !$this->UID && !$this->StatusState) {
            $jobDescrID = null;
            // If using qjobs module, create job to upload this vid (sets status to 'scheduled')
            if (self::config()->upload_from_qjob_if_available) {
                $jobDescrID = $this->scheduleUploadJob();
            }
            if (!$jobDescrID) {
                // if not queued, send directly
                $this->sendLocalVideo();
            }
        }
    }

    protected function onBeforeDelete()
    {
        parent::onBeforeDelete();

        // Delete from api too
        if ($this->UID) {
            $client = CloudflareStreamHelper::getApiClient();
            try {
                switch (self::config()->get('stream_video_action_on_delete_record')) {
                    case 'mark':
                        $client->setVideoMeta($this->UID, "name", 'DELETED: ' . $this->Name);
                        break;
                    case 'delete':
                        $client->deleteVideo($this->UID);
                        break;
                    case 'keep':
                    default:
                        // do nothing...
                }
            } catch (\GuzzleHttp\Exception\ClientException $exception) {
                // If 404, ignore (file was probably already deleted in CFStream
                if ($exception->getCode() !== 404) {
                    user_error('CFStream API ERROR: ' . $exception->getMessage());
                }
            }
        }
        // and local file (@TODO: should be done via $owns + cascade delete?)
        if ($this->VideoID && $this->Video()->exists()) {
            $this->Video()->delete();
        }
    }

    /**
     * @TODO: Tweak this to show a 'pending'/status message or so as long as not yet available
     */
    public function forTemplate()
    {
        if (!$this->UID && !$this->VideoID) {
            return;
        }
        if ($this->UID) {
            return CloudflareStreamHelper::getApiClient()->iframePlayer($this->UID);
        }

        // 'temporary' local video file?
        return '<video src=\"' . $this->Video()->getURL() . '\" />';
    }

    public function EmbedCode()
    {
        if ($this->UID) {
            return CloudflareStreamHelper::getApiClient()->embedCode($this->UID);
        }
        return null;
    }

    //
    // HELPERS
    //

    //    /**
    //     * @param string $uid
    //     * @return StreamVideoObject
    //     */
    //    public static function getByUID($uid)
    //    {
    //        return self::get()->filter('UID', $uid)->first();
    //    }

    //    /**
    //     * @param string $id
    //     * @return StreamVideoObject
    //     */
    //    public static function getByID($id)
    //    {
    //        return self::get()->filter('ID', $id)->first();
    //    }

    // public syncronous method to be called from eg qjob, blocking further execution until POST/upload to API is done
    public function postLocalVideo()
    {
        return $this->sendLocalVideo(true, true);
    }

    protected function sendLocalVideo($write = true, $forcePOST = false)
    {
        if (!$this->VideoID) {
            user_error(self::class . "::sendLocalVideo() - no video (VideoID) available for uploading to API.", E_USER_WARNING);
            return false;
        }
        if ($this->UID) {
            user_error(self::class . "::sendLocalVideo() - video already sent to API (UID set).", E_USER_WARNING);
            return false;
        }
        $client = CloudflareStreamHelper::getApiClient();
        $localVideo = $this->Video();

        // Try to upload through fromUrl first as it might go faster
        $data = [
            'thumbnailTimestampPct' => $this->ThumbnailTimestampPct ?: self::config()->default_thumbnail_timestamp_pct,
        ];
        if ($this->AllowedOrigins) {
            $data['allowedOrigins'] = $this->getAllowedOriginsAsArray();
        }
        if ($this->RequireSignedURLs) {
            $data['requireSignedURLs'] = true;
        }


        // @TODO: this can probably be tidied up a bit...
        // The idea here is to be able to 'force' POST'ing the video file, eg when running as/called from a queuedjob
        // Else: try submitting a download link for the vid (which should hopefully return early and process the vid async)
        // Failing that, again fall back to POST'ing it anyway (blocking further execution until done)
        $uid = null;
        if ($forcePOST) {
            $uid = $client->upload($this->getVideoFullPath($localVideo));
        } else {
            try {
                // use our own custom endpoint
                if ($localVideo->getVisibility() == "protected") {
                    $response = $client->fromUrl($this->LocalLink(), $data);
                } else {
                    $response = $client->fromUrl($localVideo->getURL(), $data);
                }
                $uid = $response->result->uid;
            } catch (Exception $ex) {
                $uid = $client->upload($this->getVideoFullPath($localVideo));
            }
        }

        if ($uid) {
            $this->UID = $uid;

            if (!self::config()->keep_local_video) {
                // We don't need the local asset anymore
                $localVideo->delete();
                $this->VideoID = 0;
            }

            // Write again (won't happen twice since we got and UID now)
            if ($write) {
                $this->write();
            }

            return true;
        }

        return false;
    }

    public function refreshDataFromApi($write = true)
    {
        if (!$this->UID) {
            return;
        }

        $client = CloudflareStreamHelper::getApiClient();
        try {
            $responseData = $client->videoDetails($this->UID);
        } catch (\GuzzleHttp\Exception\ClientException $exception) {
            // If 404, just return (file was probably already deleted in CFStream
            if ($exception->getCode() === 404) {
                return;
            }
            user_error('CFStream API ERROR: ' . $exception->getMessage());
        }

        $this->setDataFromApi($responseData->result);
        if ($write) {
            $this->write();
        }
    }

    //
    // GETTERS/SETTERS
    //

    /**
     * @return array
     */
    public function getAllowedOriginsAsArray()
    {
        return array_filter(preg_split('/\r\n|\r|\n/', $this->AllowedOrigins));
    }

    /**
     * @return bool
     */
    public function IsReady()
    {
        return $this->StatusState == CloudflareStreamApiClient::STATUS_READY;
    }

    public function getVideoFullPath(File $file)
    {
        $Filename = $file->FileFilename;
        $Dir = dirname($Filename);
        $Name = basename($Filename);

        $Hash = substr($file->FileHash, 0, 10);

        $Path = '';
        // Is it protected? it's in the secured folders
        if (!$file->isPublished()) {
            $Path = Config::inst()->get(ProtectedAssetAdapter::class, 'secure_folder') . '/';
            $Path .= $Dir . '/' . $Hash . '/' . $Name;
        } else {
            $Path .= $Dir . '/' . $Name;
        }
        return ASSETS_PATH . '/' . $Path;
    }

    public function LocalLink()
    {
        return Director::absoluteURL(StreamVideoAdminController::singleton()->Link('video_data') . '?ID=' . $this->ID);
    }

    public function OEmbedLink()
    {
        return Director::absoluteURL('oembed/streamvideo?ID=' . $this->ID);
    }

    /**
     * This can be used like so in a given page
     * public function MetaTags($includeTitle = true) {
     *   return parent::MetaTags($includeTitle) . "\n" . $this->Video()->OEmbedLinkElement();
     * }
     */
    public function OEmbedLinkElement()
    {
        $href = $this->OEmbedLink();
        $title = $this->Title;
        $html = <<<HTML
<link rel="alternate" type="application/json+oembed"
    href="$href"
    title="$title" />
HTML;
        return $html;
    }

    public function PosterImageUrl()
    {
        if ($this->PosterImageID) {
            return $this->PosterImage()->getURL();
        }
        if ($this->UID && $this->ThumbnailURL) {
            return $this->ThumbnailURL;
        }
        if ($this->UID) {
            return $this->getPosterImageUrlFromApi();
        }
        // Some placeholder?
        return null;
    }

    public function PreviewImageSvg($previewHeight = 36, $convertPosterToDataUri = false)
    {
        $heightToWidthFactor = $this->Height && $this->Width ? $this->Width / $this->Height : 16 / 9; // default to 16:9
        $posterURL = $this->PosterImageID ? Director::absoluteURL($this->PosterImage()->ScaleMaxHeight(360)->getURL()) : $this->ThumbnailURL;
        if ($posterURL) {
            $posterURL .= "?ts={$this->ThumbnailTimestamp}";
        }
        // When resulting SVG will be loaded in img tag, we need to convert the image to data-uri else it will not be loaded by browsers (security measure)
        if ($convertPosterToDataUri && filter_var(str_replace('_', '-', $posterURL), FILTER_VALIDATE_URL)) { // filter_var doesnt like _ in domains (eg during local development)
            // mime_content_type doesnt like external files, falling back to a simpler map
            //            $posterURL = 'data: '.mime_content_type($posterURL).';base64,'.base64_encode(file_get_contents($posterURL));
            $mimetype = str_replace('jpg', 'jpeg', 'image/' . strtolower(substr($posterURL, -3)));
            $posterURL = 'data: ' . $mimetype . ';base64,' . base64_encode(file_get_contents($posterURL));
        }
        // Create data URI of image
        $posterDataUri = $posterURL;
        $customData = [
            'PosterURL' => $posterURL,
            'PosterHeight' => 36,
            'PosterWidth' => 36 * $heightToWidthFactor,
            'IconOffsetTop' => 36 / 2 - 8,
            'IconOffsetLeft' => (36 * $heightToWidthFactor) / 2 - 8, // icon base size = 16x16
            'PreviewHeight' => $previewHeight,
            'PreviewWidth' => $previewHeight * $heightToWidthFactor,
        ];

        $HtmlTag = $this
            ->customise($customData)
            ->renderWith('VideoPreview');

        return DBHTMLVarchar::create()->setValue($HtmlTag);
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
        if (!$this->Name) {
            $this->Name = $record->meta->name ?? '';
        }
        $this->Size = $record->size;
        $this->PreviewURL = $record->preview;
        $this->ReadyToStream = $record->readyToStream;
        $this->StatusState = $record->status->state;
        $this->RequireSignedURLs = $record->requireSignedURLs;
        $this->AllowedOrigins = implode("\n", $record->allowedOrigins);
        $this->Duration = $record->duration ?: null;
        $this->ThumbnailTimestampPct = $record->thumbnailTimestampPct;
        if (!$this->ThumbnailTimestamp) {
            $this->ThumbnailTimestamp = $record->duration && $record->thumbnailTimestampPct ? $record->duration * $record->thumbnailTimestampPct : null;
        }

        $input = $record->input;
        if ($input) {
            $this->Width = $input->width;
            $this->Height = $input->height;
        }
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_LeftAndMain');
    }

    public function canEdit($member = null)
    {
        return $this->canView();
    }

    public function canDelete($member = null)
    {
        return $this->canView();
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canView();
    }
}
