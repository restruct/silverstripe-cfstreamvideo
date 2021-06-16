<?php

namespace Restruct\SilverStripe\StreamVideo;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldConfig;

class StreamVideoAdmin extends ModelAdmin
{
    private static $url_segment = 'cfstreamvideo';
    private static $menu_title = "Stream Videos";
    private static $managed_models = [
        StreamVideoObject::class
    ];
    public $showImportForm = false;

    public function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        return $config;
    }
}
