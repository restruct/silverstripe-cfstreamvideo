<?php

use Restruct\SilverStripe\StreamVideo\CloudflareStreamShortcode;
use SilverStripe\View\Parsers\ShortcodeParser;

ShortcodeParser::get('default')->register('cloudflare_stream', [CloudflareStreamShortcode::class, 'cloudflare_stream']);
