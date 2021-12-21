<?php

namespace Restruct\SilverStripe\StreamVideo\Controllers;

use Restruct\SilverStripe\StreamVideo\Model\StreamVideoObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Versioned\Versioned;

/**
 * Oembed json provider
 *
 * When testing locally you may have to update the accepted ports if needed
 * SilverStripe\AssetAdmin\Forms\RemoteFileFormFactory:
 *   fileurl_port_whitelist:
 *     - 80
 *     - 443
 *
 * @link https://oembed.com/
 */
class StreamOEmbedController extends Controller
{
    private static $url_segment = 'oembed/streamvideo';

    public function index()
    {
        $id = (int)$this->getRequest()->getVar("ID");
        if (!$id) {
            return $this->httpError(404);
        }
        /** @var StreamVideoObject $video  */
        $video = StreamVideoObject::get()->byID($id);
        if (!$video) {
            return $this->httpError(404);
        }

        $siteTitle = "SilverStripe";
        $siteUrl = Director::absoluteBaseURL();
        $w = $video->Width;
        $h = $video->Height;
        $title = $video->getTitle();

        $html = $video->forTemplate();

        $previewImage = Director::absoluteURL($video->PosterImageUrl());

        // SilverStripe extracts witdth/height from html code
        if (strpos($html, "width=") === false) {
            $html = str_replace("<iframe", "<iframe width=\"$w\" height=\"$h\"", $html);
        }
        $html = str_replace("\n", "", $html);

        $arr = [
            "version" => "1.0",
            "type" => "video",
            "provider_name" => $siteTitle,
            "provider_url" => $siteUrl,
            "width" => $w,
            "height" => $h,
            "title" => $title,
            "thumbnail_url" => $previewImage,
            "html" => $html,
        ];

        $body = json_encode($arr, JSON_PRETTY_PRINT);

        $response = new HTTPResponse($body);
        $response->addHeader("Content-Type", "application/json");
        return $response;
    }
}
