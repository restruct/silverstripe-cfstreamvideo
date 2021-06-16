<?php

namespace Restruct\SilverStripe\StreamVideo;

use SilverStripe\Core\Environment;

/**
 * @link https://developers.cloudflare.com/stream/
 */
class CloudflareStreamHelper
{
    const DEFAULT_HOST = 'https://api.cloudflare.com/client/v4/';
    protected static $client;

    public static function getApiHost()
    {
        $host = Environment::getEnv('APP_CFSTREAM_API_HOST');
        if (!$host) {
            $host = self::DEFAULT_HOST;
        }
        return $host;
    }

    public static function getAccountEmail()
    {
        return Environment::getEnv('APP_CFSTREAM_ACCOUNT_EMAIL');
    }

    public static function getAccountId()
    {
        return Environment::getEnv('APP_CFSTREAM_ACCOUNT_ID');
    }

    public static function getApiToken()
    {
        return Environment::getEnv('APP_CFSTREAM_API_TOKEN');
    }

    public static function getApiKey()
    {
        return Environment::getEnv('APP_CFSTREAM_API_KEY');
    }

    public static function getSigningKey()
    {
        return Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_ID');
    }

    public static function getSigningPem()
    {
        return Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_PEM');
    }

    public static function getSigningJwk()
    {
        return Environment::getEnv('APP_CFSTREAM_SIGNING_KEY_JWK');
    }

    /**
     * @return CloudflareStreamApiClient
     */
    public static function getApiClient()
    {
        if (!self::$client) {
            self::$client = new CloudflareStreamApiClient(
                self::getAccountId(),
            );
            // You need either a token or a email/api key pair
            if (self::getApiToken()) {
                self::$client->setToken(self::getApiToken());
            }
            if (self::getAccountEmail()) {
                self::$client->setEmail(self::getAccountEmail());
            }
            if (self::getApiKey()) {
                self::$client->setEmail(self::getApiKey());
            }
        }
        return self::$client;
    }

    public static function cloudflare_stream($arguments, $content = null, $parser = null)
    {
        $uid = $arguments["uid"];

        $client = CloudflareStreamHelper::getApiClient();

        return $client->embedCode($uid);
    }
}