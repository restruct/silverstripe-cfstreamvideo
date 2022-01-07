<?php

namespace Restruct\SilverStripe\StreamVideo\Controllers;

use Restruct\SilverStripe\StreamVideo\Model\StreamVideoObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;

/**
 * Oembed json provider
 * OEmbed 'autodiscovery' works by including a <link rel="alternate" type="application/json+oembed" ...> in a media item's HTML
 * Silverstripe, when fed the direct OEmbed JSON URL, doesn't recognize the OEmbed data. So the 'OEmbed link' should be to
 * this controllers index action, which returns the actual OEmbed JSON URL for Silverstripe to 'discover'...
 *
 * Alas Silverstripe ignores most of the OEmbed attributes and basically only looks at the value of the 'html' property.
 * So while we do include Title and Description (Info), these aren't read & inserted into the formfields by RemoteFileFormFactory.
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
    private static $url_segment = 'streamvideo-embed';

    private static $allowed_actions = [
        'json'
    ];

    private static $url_handlers = [
        'json/$VideoID/$VideoNameIndicator' => 'json',
        '$VideoID/$VideoNameIndicator' => 'index',
    ];

    // streamvideo-embed/$VideoID/$VideoNameIndicator
    public function index()
    {
        $Video = $this->getVideo();

        return $Video
            ->customise([
                'OEmbedURL' => Director::absoluteURL( $this->Link("json/{$Video->ID}/{$Video->NameAsURLSegment()}/?format=json") ),
                'VideoHTML' => DBHTMLVarchar::create()->setValue($Video->forTemplate()),
            ])
            ->renderWith(SSViewer::fromString('
                <html>
                    <head>
                        <link rel="alternate" type="application/json+oembed" href="$OEmbedURL" title="{$Name.ATT}">
                        <link rel="alternate" type="text/json+oembed" href="$OEmbedURL" title="{$Name.ATT}">
                    </head>
                    <body>
                        <a href="$OEmbedURL">$OEmbedURL</a>
                        <%--$VideoHTML--%>
                    </body>
                </html>
            '));
    }

    // streamvideo-embed/json/$VideoID/$VideoNameIndicator
    public function json()
    {
        $Video = $this->getVideo();

        // SilverStripe extracts witdth/height from html code
        $html = $Video->forTemplate();
//        $html = $Video->EmbedCode();
        if (strpos($html, "width=") === false) {
            $html = str_replace("<iframe", "<iframe width=\"{$Video->Width}\" height=\"{$Video->Height}\"", $html);
        }

        $arr = [
            "version" => "1.0",
            "type" => "Video",
            "provider_name" => class_exists('SilverStripe\SiteConfig\SiteConfig') ? SiteConfig::get()->first()->Title : 'Video',
            "provider_url" => Director::absoluteBaseURL(),
            "title" => $Video->getTitle(),
            "description" => $Video->Info,
            "thumbnail_url" => Director::absoluteURL($Video->PosterImageUrl()),
            "width" => $Video->Width,
            "height" => $Video->Height,
            "html" => str_replace("\n", "", $html),
        ];

        $response = new HTTPResponse();
        $response->addHeader("Content-Type", "application/json");
        $response->setBody(json_encode($arr, JSON_PRETTY_PRINT));

        return $response;
    }

    /**
     * Get the StreamVideoObject by VideoID URL param
     * @return StreamVideoObject
     * @throws \SilverStripe\Control\HTTPResponse_Exception
     */
    private function getVideo()
    {
        $id = (int) $this->getRequest()->param("VideoID");
        if (!$id) {
            $this->httpError(404); // throws, no need for return
        }

        /** @var StreamVideoObject $video  */
        $video = StreamVideoObject::get()->byID($id);
        if (!$video) {
            $this->httpError(404);
        }

        return $video;
    }
}
