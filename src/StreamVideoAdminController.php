<?php

namespace Restruct\SilverStripe\StreamVideo;

use Shortcodable\Shortcodable;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class StreamVideoAdminController
    extends Controller
{
    private static $url_segment = 'streamvideo';

    private static $signed_url_buffer = 600;

    /**
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'CMS_ACCESS_LeftAndMain',
//        'handleEdit' => 'CMS_ACCESS_LeftAndMain',
        'shortcodePlaceHolder' => 'CMS_ACCESS_LeftAndMain'
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

        if(!Permission::check('CMS_ACCESS_LeftAndMain')){
            Security::permissionFailure($this, [
                'default' => 'You need to be logged in to access StreamVideoAdminController.',
                'alreadyLoggedIn' => 'Insufficient permissions to access StreamVideoAdminController. If you have an alternative account with higher permission levels you may try to login with that.',
            ]);
        }

        // Ref: get STREAM API credentials from ENV
        Environment::getEnv('APP_CFSTREAM_API_HOST');
        Environment::getEnv('APP_CFSTREAM_ACCOUNT_ID');
        Environment::getEnv('APP_CFSTREAM_ACCOUNT_EMAIL');
        Environment::getEnv('APP_CFSTREAM_API_TOKEN');
        Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_ID');
        Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_PEM');
        Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_JWK');
    }

    /**
     * Provides content (form html) for the shortcode dialog
     **/
    public function index()
    {
        return;
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
