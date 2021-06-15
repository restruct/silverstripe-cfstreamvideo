<?php

namespace Restruct\SilverStripe\StreamVideo;

use Exception;
use GuzzleHttp\Client;

/**
 * @link https://api.cloudflare.com/#stream-videos-properties
 */
class CloudflareStreamApiClient
{
    const API_BASE_URL = "https://api.cloudflare.com/client/v4/";

    /**
     * @var Client
     */
    protected $client;

    private $accountId;

    // when using X-Auth-Key, X-Auth-Email headers
    private $key;
    private $email;

    // when using Authorization: Bearer [token]
    private $token;

    /**
     * Initialize client with authentication credentials.
     *
     * @param string $accountId
     */
    public function __construct($accountId)
    {
        if (empty($accountId)) {
            throw new Exception("Invalid account id");
        }

        $this->accountId = $accountId;

        $config = [];

        // fix invalid local certificate
        if (strlen(ini_get('curl.cainfo')) === 0) {
            $config['verify'] = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
        }

        $this->client = new Client($config);
    }
    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param string $key
     * @return $this
     */
    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param string $email
     * @return $this
     */
    public function setEmail($email)
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @param string $token
     * @return $this
     */
    public function setToken($token)
    {
        $this->token = $token;
        return $this;
    }

    /**
     * You can check result->status == active
     *
     * @return array Response body contents
     */
    public function verifyToken()
    {
        if (!$this->token) {
            throw new Exception("No token");
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json'
        ];
        $response = $this->client->get(self::API_BASE_URL . "user/tokens/verify", [
            'headers' => $headers,
        ]);

        return json_decode($response->getBody()->getContents());
    }

    protected function getHeadersWithAuth($headers = [])
    {
        if ($this->token) {
            $base = [
                'Authorization' => 'Bearer ' . $this->token,
            ];
        } elseif ($this->key && $this->email) {
            $base = [
                'X-Auth-Key' => $this->key,
                'X-Auth-Email' => $this->email,
            ];
        } else {
            throw new Exception("No credentials set");
        }
        return array_merge($base, $headers);
    }

    /**
     * Get the status of a video.
     *
     * @param string $resourceUrl
     * @return array Response body contents
     */
    public function status($resourceUrl)
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Type' => 'application/json',
        ]);
        $response = $this->client->get($resourceUrl, [
            'headers' => $headers,
        ]);

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Upload a video with a given filepath.
     *
     * @param string $filepath
     * @return string $resourceUrl URL to manage the video resource
     */
    public function upload($filepath)
    {
        $file = fopen($filepath, 'r');
        if (!$file) {
            throw new Exception("Invalid file");
        }

        $filesize = filesize($filepath);
        $filename = basename($filepath);

        $response = $this->post($filename, $filesize);
        $resourceUrl = $response->getHeader('Location')[0];
        $this->patch($resourceUrl, $file, $filesize);

        return $resourceUrl;
    }

    /**
     * Create a resource on Cloudflare Stream.
     *
     * @param string $filename
     * @param int    $filesize
     * @return object $response Response from Cloudflare
     */
    public function post($filename, $filesize)
    {
        if (empty($filename) || empty($filesize)) {
            throw new Exception("Invalid file");
        }

        $headers = $this->getHeadersWithAuth([
            'Content-Length' => 0,
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => $filesize,
            'Upload-Metadata' => "filename {$filename}",
        ]);
        $response = $this->client->post(self::API_BASE_URL . "accounts/{$this->accountId}/stream", [
            'headers' => $headers,
        ]);

        if (201 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response;
    }

    /**
     * Create a resource on Cloudflare Stream from a url
     *
     * @param string $url
     * @param array $meta
     * @return object $response Response from Cloudflare
     */
    public function fromUrl($url, $meta = [])
    {
        if (empty($url)) {
            throw new Exception("Invalid url");
        }

        $data = [
            'url' => $url,
            'meta' => $meta,
        ];
        $headers = $this->getHeadersWithAuth();
        $response = $this->client->post(self::API_BASE_URL . "accounts/{$this->accountId}/stream/copy", [
            'headers' => $headers,
            'json' => $data
        ]);

        if (201 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response;
    }

    /**
     * Upload the file to Cloudflare Stream.
     *
     * @param string   $resourceUrl
     * @param resource $file        fopen() pointer resource
     * @param int      $filesize
     * @return object $response Response from Cloudflare
     */
    public function patch($resourceUrl, $file, $filesize)
    {
        if (empty($file)) {
            throw new Exception("Invalid file");
        }

        $headers = $this->getHeadersWithAuth([
            'Content-Length' => $filesize,
            'Content-Type' => 'application/offset+octet-stream',
            'Tus-Resumable' => '1.0.0',
            'Upload-Offset' => 0,
        ]);
        $response = $this->client->patch($resourceUrl, [
            'headers' => $headers,
            'body' => $file,
        ]);

        if (204 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response;
    }

    /**
     * Delete video from Cloudflare Stream.
     *
     * @param string $resourceUrl
     */
    public function delete($resourceUrl)
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Length' => 0,
        ]);
        $response = $this->client->delete($resourceUrl, [
            'headers' => $headers
        ]);

        if (204 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }
    }

    /**
     * Get embed code for the video.
     *
     * @param string $resourceUrl
     * @return string HTML embed code
     */
    public function code($resourceUrl)
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Type' => 'application/json',
        ]);
        $response = $this->client->get("{$resourceUrl}/embed", [
            'headers' => $headers,
        ]);

        if (200 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response->getBody()->getContents();
    }

    /**
     * Set allowedOrigins on the video.
     *
     * @param string $resourceUrl
     * @param string $origins     Comma separated hostnames
     */
    public function allow($resourceUrl, $origins)
    {
        if (false !== strpos($origins, '/')) {
            throw new Exception("Operation failed");
        }

        $videoId = @end(explode('/', $resourceUrl));
        $headers = $this->getHeadersWithAuth();
        $data = [
            'uid' => $videoId,
            'allowedOrigins' => $origins
        ];
        $response = $this->client->post($resourceUrl, [
            'json' => $data,
            'headers' => $headers
        ]);

        if (200 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }
    }

    public function listVideos()
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Type' => 'application/json',
        ]);
        $response = $this->client->get(self::API_BASE_URL . "accounts/{$this->accountId}/stream", [
            'headers' => $headers
        ]);

        if (200 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response->getBody()->getContents();
    }

    public function videoDetails($uid)
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Type' => 'application/json',
        ]);
        $response = $this->client->get(self::API_BASE_URL . "accounts/{$this->accountId}/stream/{$uid}", [
            'headers' => $headers
        ]);

        if (200 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response->getBody()->getContents();
    }

    public function embedCode($uid)
    {
        $headers = $this->getHeadersWithAuth([
            'Content-Type' => 'application/json',
        ]);
        $response = $this->client->get(self::API_BASE_URL . "accounts/{$this->accountId}/stream/{$uid}/embed", [
            'headers' => $headers
        ]);

        if (200 != $response->getStatusCode()) {
            throw new Exception("Operation failed");
        }

        return $response->getBody()->getContents();
    }
}
