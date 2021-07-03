<?php

namespace Restruct\SilverStripe\StreamVideo\Shortcodes;

use Restruct\SilverStripe\StreamVideo\Model\StreamVideoObject;
use Restruct\SilverStripe\StreamVideo\StreamApi\CloudflareStreamHelper;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extensible;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;

/**
 */
class CloudflareStreamShortcode
{
    use Extensible;

    private static $shortcode = "stream_video";
    private static $shortcode_callback = "cloudflare_stream";

    /**
     * nice description for in dropdown, `singular_name()` or else `ClassName.Shortname` will be used as fallback
     *
     * @return string
     */
    public function getShortcodeLabel()
    {
        return "Video (CloudFlare Stream)";
    }

    /**
     * attribute-formfields for popup
     * @link https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player#basic-options
     * @return FieldList
     */
    public function getShortcodeFields()
    {
        $vidObj = StreamVideoObject::singleton();
        return FieldList::create(
            DropdownField::create(
                "id",
                $vidObj->fieldLabel('sc_video'),
                StreamVideoObject::get()->filter('ReadyToStream', true)->map("ID", "Name")
            ),
            CheckboxField::create("hide_controls", $vidObj->fieldLabel('sc_hide_controls')),
            CheckboxField::create("autoplay", $vidObj->fieldLabel('sc_autoplay')),
            CheckboxField::create("loop", $vidObj->fieldLabel('sc_loop')),
            CheckboxField::create("muted", $vidObj->fieldLabel('sc_muted')),
            CheckboxField::create("preload", $vidObj->fieldLabel('sc_preload')),
//            DropdownField::create("preload", $vidObj->fieldLabel('sc_preload'), [
//                'none' => $vidObj->fieldLabel('sc_preload_none'),
//                'metadata' => $vidObj->fieldLabel('sc_preload_metadata'),
//                'auto' => $vidObj->fieldLabel('sc_preload_auto'),
//            ])
        );
    }

    public function getShortcodePlaceHolder($opts)
    {
        $vidObjectID = $opts["id"];
        if (!$vidObjectID || !$video = StreamVideoObject::get()->byID($vidObjectID)) {
            return '';
        }

//        // Simple version: redirect to image URL
//        Controller::curr()->redirect(Director::absoluteURL( $video->PosterImageID ? $video->PosterImage()->getURL() : $video->ThumbnailURL ));

        // Output image/svg data directly (any bitmap but may also be SVG)
        $response = Controller::curr()->getResponse();
        $response->addHeader('Content-Type','image/svg+xml');
        $response->addHeader('Vary','Accept-Encoding');
        $response->setBody($video->PreviewImageSvg(180, true));
        $response->output();
    }

    /**
     * Arguments are forwarded to the iframe player
     * @link https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player#basic-options
     * @return string
     */
    public static function cloudflare_stream($opts, $content = null, $parser = null)
    {
        $vidObjectID = $opts["id"];
        unset($opts["id"]);
        if (!$vidObjectID || !$video = StreamVideoObject::get()->byID($vidObjectID)) {
            return;
        }

        // Refresh state
        if (!$video->IsReady()) {
            $video->refreshDataFromApi(true);
        }

        if(isset($opts['hide_controls'])) {
            $opts['controls'] = 0;
            unset($opts['hide_controls']);
        }

        if(isset($opts['preload'])) {
            $opts['preload'] = 'auto';
        }

        // set the custom poster
        if ($video->PosterImageID) {
            $opts['poster'] = $video->PosterImage()->getAbsoluteURL();
        }

        $ratio = 9 / 100 * 100;
        if ($video->Width && $video->Height) {
            $ratio = $video->Height / $video->Width * 100;
        }

        $RequireSignedURLs = $video->RequireSignedURLs;
        $addBufferSeconds = StreamVideoObject::config()->signed_buffer_seconds;

        // return $client->embedCode($uid);
        return CloudflareStreamHelper::getApiClient()->iframePlayer($video->UID, $opts, $RequireSignedURLs, $ratio, $addBufferSeconds);
    }

}
