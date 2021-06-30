<?php

namespace Restruct\SilverStripe\StreamVideo;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\Forms\Form;
use Shortcodable\Shortcodable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Security\Security;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

class StreamVideoAdminController extends Controller
{
    private static $url_segment = 'streamvideo';

    private static $signed_url_buffer = 600;

    /**
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'CMS_ACCESS_LeftAndMain',
        'sync_from_api' => 'CMS_ACCESS_LeftAndMain',
        'refresh_video_statuses' => 'CMS_ACCESS_LeftAndMain',
        'verify_token' => 'CMS_ACCESS_LeftAndMain',
        'generate_signing_key' => 'CMS_ACCESS_LeftAndMain',
        //        'handleEdit' => 'CMS_ACCESS_LeftAndMain',
        'shortcodePlaceHolder' => 'CMS_ACCESS_LeftAndMain',
        'video_data' => true,
    ];

    /**
     * @var array
     */
    private static $url_handlers = [
        //        'edit/$ShortcodeType!/$Action//$ID/$OtherID' => 'handleEdit'
    ];

    public function init()
    {
        parent::init();
    }

    /**
     * Provides content (form html) for the shortcode dialog
     **/
    public function index()
    {
        return;
    }

    /**
     * Provides a way to send protected assets so that cloudflare can copy through url
     *
     * @return string
     */
    public function video_data()
    {
        $ID = (int)$this->getRequest()->getVar("ID");
        $StreamVideo = StreamVideoObject::getByID($ID);
        if (!$StreamVideo) {
            return $this->httpError(404, "No object");
        }
        if (!$StreamVideo->VideoID) {
            return $this->httpError(404, "No video ID");
        }
        if ($StreamVideo->IsReady()) {
            return $this->httpError(404, "Already processed");
        }

        // Switch to draft as the asset might not be published
        if (class_exists(Versioned::class) && Versioned::get_stage() !== Versioned::DRAFT) {
            Versioned::set_stage(Versioned::DRAFT);
        }

        // Send video data through as stream
        $LocalVideo = $StreamVideo->Video();
        if (!$LocalVideo && !$LocalVideo->ID) {
            return $this->httpError(404, "No video");
        }
        $stream = $LocalVideo->getStream();
        if (!$stream) {
            return $this->httpError(404, "No stream");
        }
        header("Content-Type: video/mp4");
        header('Accept-Ranges: bytes');
        fpassthru($stream);
        exit();
    }

    public function verify_token()
    {
        $client = CloudflareStreamHelper::getApiClient();

        $result = $client->verifyToken();
        echo '<pre>';
        print_r($result);
        die();
    }

    public function refresh_video_statuses()
    {
        foreach (StreamVideoObject::get()->exclude('StatusState', 'ready') as $vid) {
            $vid->refreshDataFromApi();
        }
    }

    /**
     * @link https://api.cloudflare.com/#stream-signing-keys-create-a-signing-key
     * @return void
     */
    public function generate_signing_key()
    {
        if (CloudflareStreamHelper::getSigningKey()) {
            throw new Exception("Signing key already configured");
        }
        $client = CloudflareStreamHelper::getApiClient();

        $result = $client->createSigningKey();
        $key = $result->result;

        $env = Director::baseFolder() . "/.env";
        $data = <<<TEXT
APP_CFSTREAM_SIGNING_KEY_ID="{$key->id}"
APP_CFSTREAM_SIGNING_KEY_PEM="{$key->pem}"
APP_CFSTREAM_SIGNING_KEY_JWK="{$key->jwk}"
TEXT;
        $write = file_put_contents($env, $data, FILE_APPEND);

        if ($write) {
            DB::alteration_message("Key has been added to env file");
        } else {
            DB::alteration_message("Failed to write env file, please add manually");
        }

        echo '<pre>';
        print_r($result);
        die();
    }

    public function sync_from_api()
    {
        $client = CloudflareStreamHelper::getApiClient();
        $list = $client->listVideos();
        foreach ($list->result as $result) {
            $record = StreamVideoObject::getByUID($result->uid);
            $operation = "Updated";
            if (!$record) {
                $record = new StreamVideoObject();
                $operation = "Created";
            }
            $record->setDataFromApi($result);
            $record->write();
            DB::alteration_message("$operation record {$record->UID}");
        }
    }

    //    /**
    //     * Generates shortcode placeholder to display inside TinyMCE instead of the shortcode.
    //     *
    //     * @return \SilverStripe\Control\HTTPResponse|string|void
    //     */
    //    public function shortcodePlaceHolder($request)
    //    {
    //        if (!Permission::check('CMS_ACCESS_CMSMain')) {
    //            return;
    //        }
    //
    //        $classname = $request->param('ID');
    //        $id = $request->param('OtherID');
    //
    //        if (!class_exists($classname)) {
    //            return;
    //        }
    //
    //        if ($id && is_subclass_of($classname, DataObject::class)) {
    //            $object = $classname::get()->byID($id);
    //        } else {
    //            $object = singleton($classname);
    //        }
    //
    //        if ($object->hasMethod('getShortcodePlaceHolder')) {
    //            $attributes = null;
    //            if ($shortcode = $request->requestVar('Shortcode')) {
    //                $shortcode = str_replace("\xEF\xBB\xBF", '', $shortcode); //remove BOM inside string on cursor position...
    //                $shortcodeData = singleton('\Silverstripe\Shortcodable\ShortcodableParser')->the_shortcodes([], $shortcode);
    //                if (isset($shortcodeData[0])) {
    //                    $attributes = $shortcodeData[0]['atts'];
    //                }
    //            }
    //
    //            $link = $object->getShortcodePlaceholder($attributes);
    //            return $this->redirect($link);
    //        }
    //    }
}
