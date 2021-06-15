<?php

use Restruct\SilverStripe\StreamVideo\CloudflareStreamHelper;
use SilverStripe\View\Parsers\ShortcodeParser;

ShortcodeParser::get('default')->register('cloudflare_stream', [CloudflareStreamHelper::class, 'cloudflare_stream']);
