<?php

namespace Restruct\SilverStripe\StreamVideo;

use SilverStripe\Core\Extensible;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormField;

/**
 */
class CloudflareStreamShortcode
{
    use Extensible;

    private static $shortcode = "cloudflare_stream";

    /**
     * Arguments are forwarded to the iframe player
     * @link https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player#basic-options
     * @return string
     */
    public static function cloudflare_stream($arguments, $content = null, $parser = null)
    {
        $uid = $arguments["uid"];
        unset($arguments['uid']);

        $client = CloudflareStreamHelper::getApiClient();

        $video = StreamVideoObject::getByUID($uid);
        if (!$video) {
            return;
        }

        $opts = $arguments;
        // we can set the custom poster
        if ($video->PosterImageID) {
            $opts['poster'] = $video->PosterImage()->getAbsoluteURL();
        }

        // return $client->embedCode($uid);
        return $client->iframePlayer($uid, $opts);
    }


    public static function parse_shortcode($attrs, $content = null, $parser = null, $shortcode = null, $info = null)
    {
        return self::cloudflare_stream($attrs, $content, $parser);
    }

    /**
     * nice description for in dropdown, `singular_name()` or else `ClassName.Shortname` will be used as fallback
     *
     * @return string
     */
    public function getShortcodeLabel()
    {
        return "Cloudflare Stream";
    }

    /**
     * attribute-formfields for popup
     * @link https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player#basic-options
     * @return FormField[]
     */
    public function getShortcodeFields()
    {
        $arr = [];
        $arr[] = new DropdownField("uid", "Select video", $this->getShortcodableRecords());
        $arr[] = new CheckboxField("controls", "Show controls");
        $arr[] = new CheckboxField("autoplay", "Autoplay");
        $arr[] = new CheckboxField("loop", "Loop");
        $arr[] = new CheckboxField("muted", "Muted");
        $arr[] = new DropdownField("preload", "Preload", [
            'none' => "None (default)",
            'auto' => "Auto",
            'metadata' => "Metadata"
        ]);
        return $arr;
    }

    /**
     * @return array
     */
    public function getShortcodableRecords()
    {
        return StreamVideoObject::get()->map("UID", "Name");
    }

    // public function getShortcodePlaceholder($attributes)
    // {

    // }
}
