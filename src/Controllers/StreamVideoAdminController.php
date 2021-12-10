<?php

namespace Restruct\SilverStripe\StreamVideo\Controllers;

use Exception;
use Restruct\SilverStripe\StreamVideo\Model\StreamVideoObject;
use Restruct\SilverStripe\StreamVideo\StreamApi\CloudflareStreamHelper;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class StreamVideoAdminController extends Controller
{
    private static $url_segment = 'admin/streamvideo';

    private static $signed_url_buffer = 600;

    /**
     * @var array
     */
    private static $allowed_actions = [
        'sync_from_api' => 'CMS_ACCESS_LeftAndMain',
        'refresh_video_statuses' => 'CMS_ACCESS_LeftAndMain',
        'verify_token' => 'CMS_ACCESS_LeftAndMain',
        'generate_signing_key' => 'ADMIN',
        'shortcodePlaceHolder' => 'CMS_ACCESS_LeftAndMain',
        'video_data' => true,
    ];

    /**
     * Provides a way to send protected assets so that cloudflare can copy through url
     */
    public function video_data()
    {
        $ID = (int)$this->getRequest()->getVar("ID");
        $StreamVideo = StreamVideoObject::get()->byID($ID);
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

//        $env = Director::baseFolder() . "/.env";
        $data = <<<TEXT
APP_CFSTREAM_SIGNING_KEY_ID="{$key->id}"
APP_CFSTREAM_SIGNING_KEY_PEM="{$key->pem}"
APP_CFSTREAM_SIGNING_KEY_JWK="{$key->jwk}"
TEXT;
//        $write = file_put_contents($env, $data, FILE_APPEND);
//
//        if ($write) {
//            DB::alteration_message("Key has been added to env file");
//        } else {
//            DB::alteration_message("Failed to write env file, please add manually");
//        }

        DB::alteration_message("Signing Key generated, please add to your environment:");
        echo '<pre>';
        print_r($data);
        echo "<br><br><br>Info:<br>";
        print_r($result);
        die();
    }

    public function sync_from_api()
    {
        $client = CloudflareStreamHelper::getApiClient();
        $list = $client->listVideos();
        foreach ($list->result as $result) {
            $record = StreamVideoObject::get()->filter('UID', $result->uid)->first();
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
}
