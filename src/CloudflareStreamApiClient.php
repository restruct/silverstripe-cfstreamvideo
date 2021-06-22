<?php

namespace Restruct\SilverStripe\StreamVideo;

use Exception;
use GuzzleHttp\Client;
use Firebase\JWT\JWT;

/**
 * @link https://api.cloudflare.com/#stream-videos-properties
 */
class CloudflareStreamApiClient
{
    const API_BASE_URL = "https://api.cloudflare.com/client/v4/";
    const STATUS_DOWNLOADING = "downloading";
    const STATUS_QUEUED = "queued";
    const STATUS_INPROGRESS = "inprogress";
    const STATUS_READY = "ready";
    const STATUS_ERROR = "error";

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $defaultAllowedOrigins = [];

    private $accountId;

    // when using X-Auth-Key, X-Auth-Email headers
    private $key;
    private $email;

    // when using Authorization: Bearer [token]
    private $token;

    // For signing token
    private $privateKeyId;
    private $privateKeyPem;

    private $createTokenWithApi = false;

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
     * Get the value of defaultAllowedOrigins
     * @return array
     */
    public function getDefaultAllowedOrigins()
    {
        return $this->defaultAllowedOrigins;
    }

    /**
     * Set the value of defaultAllowedOrigins
     *
     * @param array $defaultAllowedOrigins
     * @return $this
     */
    public function setDefaultAllowedOrigins(array $defaultAllowedOrigins)
    {
        $this->defaultAllowedOrigins = $defaultAllowedOrigins;
        return $this;
    }

    /**
     * Get the value of privateKeyId
     * @return mixed
     */
    public function getPrivateKeyId()
    {
        return $this->privateKeyId;
    }

    /**
     * Set the value of privateKeyId
     *
     * @param mixed $privateKeyId
     * @return $this
     */
    public function setPrivateKeyId($privateKeyId)
    {
        $this->privateKeyId = $privateKeyId;
        return $this;
    }

    /**
     * Get the value of privateKeyPem
     * @return mixed
     */
    public function getPrivateKeyPem()
    {
        return $this->privateKeyPem;
    }

    /**
     * Set the value of privateKeyPem
     *
     * @param mixed $privateKeyPem
     * @return $this
     */
    public function setPrivateKeyPem($privateKeyPem)
    {
        $this->privateKeyPem = $privateKeyPem;
        return $this;
    }

    /**
     * Get the value of createTokenWithApi
     * @return bool
     */
    public function getCreateTokenWithApi()
    {
        return $this->createTokenWithApi;
    }

    /**
     * Set the value of createTokenWithApi
     *
     * @param bool $createTokenWithApi
     * @return $this
     */
    public function setCreateTokenWithApi($createTokenWithApi)
    {
        $this->createTokenWithApi = $createTokenWithApi;
        return $this;
    }

    /**
     * @param string] $endpoint
     * @param array $data
     * @param array $headers
     * @param string $method
     * @param boolean $useAuth
     * @return object
     */
    public function makeRequest($endpoint, $data = [], $headers = [], $method = "GET", $useAuth = true)
    {
        if ($useAuth) {
            $headers = $this->getHeadersWithAuth($headers);
        }

        $uri = self::API_BASE_URL . $endpoint;
        $options =  [
            "headers" => $headers
        ];
        if (!empty($data)) {
            if ($method == "POST") {
                if (is_array($data)) {
                    $options["json"] = $data;
                } else {
                    $options["body"] = $data;
                }
            } else {
                $endpoint .= "?" . http_build_query($endpoint);
            }
        }

        $response = $this->client->request($method, $uri, $options);

        if ($response->getStatusCode() > 299) {
            throw new Exception("Operation failed");
        }

        $decoded = json_decode($response->getBody()->getContents());
        return $decoded;
    }

    /**
     * You can check result->status == active
     *
     * @return object
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
        return $this->makeRequest("user/tokens/verify", [], $headers, "GET", false);
    }

    /**
     * @param array $headers
     * @param boolean $tokenOnly
     * @return array
     */
    protected function getHeadersWithAuth($headers = [], $tokenOnly = false)
    {
        if ($this->token) {
            $base = [
                'Authorization' => 'Bearer ' . $this->token,
            ];
        } elseif ($this->key && $this->email && !$tokenOnly) {
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
     * Upload a video with a given filepath.
     *
     * @param string $filepath
     * @param array $data
     * @return string UID
     */
    public function upload($filepath, $data = [])
    {
        $file = fopen($filepath, 'r');
        if (!$file) {
            throw new Exception("Invalid file");
        }

        $filesize = filesize($filepath);
        $filename = basename($filepath);

        // Initiate a video upload using the TUS protocol. On success, server will response with status code 201 (Created) and include a 'location' header indicating where the video content should be uploaded to
        $response = $this->post($filename, $filesize);

        if (!$response) {
            throw new Exception("Unable to initiate TUS protocol");
        }

        // Use location header (headers are arrays => use [0])
        $resourceUrl = $response->getHeader('Location')[0];

        // Once the upload has been created, the client can start to transmit the actual upload content by sending a PATCH request to the upload URL
        // TODO: support resuming failed uploads
        $response = $this->patch($resourceUrl, $file, $filesize);

        // There is an header containing media id (headers are arrays => use [0])
        return $response->getHeader('stream-media-id')[0];
    }

    /**
     * Upload the file to Cloudflare Stream.
     *
     * @param string $resourceUrl
     * @param resource $file fopen() pointer resource
     * @param int $filesize
     * @return object Guzzle Response
     */
    protected function patch($resourceUrl, $file, $filesize)
    {
        if (empty($file)) {
            throw new Exception("Invalid file");
        }

        $headers = [
            'Content-Length' => $filesize,
            'Content-Type' => 'application/offset+octet-stream',
            'Tus-Resumable' => '1.0.0',
            'Upload-Offset' => 0,
        ];
        $headers = $this->getHeadersWithAuth($headers);

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
     * Initiate a video upload using the TUS protocol. On success, server will response with status code 201 (Created)
     * and include a 'location' header indicating where the video content should be uploaded to.
     * See https://tus.io for protocol details.
     *
     * @link https://api.cloudflare.com/#stream-videos-upload-a-video-using-a-single-http-request
     * @link https://api.cloudflare.com/#stream-videos-initiate-a-video-upload-using-tus
     * @param string $filename
     * @param int $filesize
     * @param array $data
     * @return object Guzzle Response
     */
    protected function post($filename, $filesize, $data = [])
    {
        if (empty($filename) || empty($filesize)) {
            throw new Exception("Invalid file");
        }

        $headers = [
            'Content-Length' => 0,
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => $filesize,
            'Upload-Metadata' => "filename {$filename}",
        ];
        $headers = $this->getHeadersWithAuth($headers);

        $endpoint = "accounts/{$this->accountId}/stream";
        $uri = self::API_BASE_URL . $endpoint;
        $response = $this->client->post($uri, [
            'headers' => $headers
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
     * @param array $data thumbnailTimestampPct, allowedOrigins, requireSignedURLs, watermark
     * @return object
     */
    public function fromUrl($url, $data = [])
    {
        if (empty($url)) {
            throw new Exception("Invalid url");
        }
        $data['url'] = $url;
        if (!empty($this->defaultAllowedOrigins) && !isset($data['allowedOrigins'])) {
            $data['allowedOrigins'] = $this->defaultAllowedOrigins;
        }
        return $this->makeRequest("accounts/{$this->accountId}/stream/copy", $data, [], "POST");
    }

    /**
     * Delete video from Cloudflare Stream.
     *
     * @param string $uid
     * @return object
     */
    public function deleteVideo($uid)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}", [], $headers, "DELETE");
    }

    /**
     * @link https://api.cloudflare.com/#stream-videos-list-videos
     * @param array $params after, before, include_counts,search,limit,asc,status
     * @return object
     */
    public function listVideos($params = [])
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream", $params, $headers);
    }

    /**
     * @link https://api.cloudflare.com/#stream-videos-video-details
     * @param string $uid
     * @param array $data
     * @return object
     */
    public function updateVideo($uid, $data = [])
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}", $data, $headers, "POST");
    }

    /**
     * @link https://api.cloudflare.com/#stream-videos-video-details
     * @param string $uid
     * @return object
     */
    public function videoDetails($uid)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}", [], $headers);
    }

    /**
     * @param string $uid
     * @return array
     */
    public function getVideoMeta($uid)
    {
        return $this->videoDetails($uid)->meta;
    }

    /**
     * @param string $uid
     * @param string $k
     * @param string $v
     * @return object
     */
    public function setVideoMeta($uid, $k, $v)
    {
        $curr = $this->videoDetails($uid);
        $meta = $curr->meta ?? [];
        $meta[$k] = $v;
        return $this->updateVideo($uid, ['meta' => $meta]);
    }

    /**
     * @param string $uid
     * @param string $v
     * @return object
     */
    public function setVideoName($uid, $v)
    {
        return $this->setVideoMeta($uid, "name", $v);
    }

    /**
     * @link https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream#limiting-where-videos-can-be-embedded
     * @param string $uid
     * @param array $origins
     * @return object
     */
    public function setAllowedOrigins($uid, $origins)
    {
        $data = [
            'allowedOrigins' => $origins
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}", $data, [], "POST");
    }

    /**
     * @link https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream#signed-urls
     * @param string $uid
     * @param bool $required Indicates whether the video can be a accessed only using it's UID.
     * @return object
     */
    public function setSignedURLs($uid, $required)
    {
        $data = [
            'requireSignedURLS' => (bool)$required
        ];
        return $this->updateVideo($uid, $data);
    }

    /**
     * Example
     * <stream id="ea95132c15732412d22c1476fa83f27a"></stream>
     * <script data-cfasync="false" defer
     *   type="text/javascript"
     *   src="https://embed.cloudflarestream.com/embed/we4g.fla9.latest.js">
     * </script>
     *
     * @link https://api.cloudflare.com/#stream-videos-embed-code-html
     * @param string $uid
     * @param bool $addControls
     * @param bool $useSignedToken
     * @return string
     */
    public function embedCode($uid)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        $endpoint = "accounts/{$this->accountId}/stream/{$uid}/embed";
        $headers = $this->getHeadersWithAuth($headers);
        $uri = self::API_BASE_URL . $endpoint;
        $options =  [
            "headers" => $headers
        ];
        $response = $this->client->get($uri, $options);

        if ($response->getStatusCode() > 299) {
            throw new Exception("Operation failed");
        }

        // We get an html code, not a json response
        $embed = $response->getBody()->getContents();

        return $embed;
    }


    /**
     * The iframe player is a better alternative to the embed code because
     * it supports signed tokens
     *
     * @param string $uid
     * @param array $playerOptions https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player
     * @param boolean $useSignedToken
     * @param float $ratio Ratio in percentage (16/9 by default)
     * @param int $addHours
     * @return string
     */
    public function iframePlayer($uid, $playerOptions = [], $useSignedToken = true, $ratio = null, $addHours = 4)
    {
        $videoid = $uid;

        // Require signed token?
        if ($useSignedToken) {
            // eg <iframe src="https://iframe.videodelivery.net/eyJhbGciOiJSUzI1NiIsImt..."></iframe>
            if ($this->createTokenWithApi) {
                $response = $this->createSignedUrl($uid, [
                    'exp' => time() + ($addHours * 60 * 60)
                ]);
                $videoid = $response->result->token;
            } else {
                $videoid = $this->getSignedToken($uid, $addHours);
            }
        }

        $opts = http_build_query($playerOptions);

        // The Stream player can be placed on a web page in an iframe element
        // with the video UID (or signed URL) replacing $VIDEOID in the example below.
        $embed = <<<HTML
<iframe
    src="https://iframe.videodelivery.net/{$videoid}?{$opts}"
    style="border: none; position: absolute; top: 0; height: 100%; width: 100%;"
    allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;"
    allowfullscreen="true"
></iframe>
HTML;

        if (!$ratio) {
            $ratio = 9 / 16 * 100;
        }
        $embed = "<div style=\"position: relative; padding-top: {$ratio}%;\">$embed</div>";

        return $embed;
    }

    /**
     * "result": {
     * "id": "5213cfa121f70b8c1380686ffc371ba3",
     * "pem": "LS0tLS1CRUdJTiBSU0EgUFJJVkFURSBLRVktLS0tLQpNSUlFcGdJQkFBS0NBUUVBMFRqd2pPaVpXbUo0M3ZmM1RvNERvWG1YV3RKR05HeVhmaHl0dExhQmdGMStFUVdRCkRLaG9LYm9hS21xakNBc21za3V0YkxVN1BVOGRrUU5ER1p3S3VWczA4elNaNGt4aTR0RWdQUFp5dDdkWEMrbFkKUllveXJBR0Y0QVhoeTMyOWJIQ1AxSWxyQkIvQWtHZ25kTEFndW54WTByUmdjdk96aWF3NktKeEZuYzJVSzBXVQo4YjBwNEtLSEdwMUtMOWRrMFdUOGRWWXFiZVJpSmpDbFVFbWg4eXY5Q2xPVmFTNEt4aVg2eFRRNERadzZEYUpmCklWM1F0Tmd2cG1ieWxOSmFQSG5zc3JodDJHS1A5NjJlS2poUVJsaWd2SFhKTE9uSm9KZkxlSUVIWitpeFdmY1QKRE1IOTJzR3ZvdzFET2p4TGlDTjF6SEsraDdiTG9YVGlMZ2M0a3dJREFRQUJBb0lCQVFEQ0lCclNJMTlteGNkdwoycExVaUdCR0N4T3NhVDVLbGhkYUpESG9ZdzUxbEVuTWNXVGUyY01NTkdqaXdsN1NyOFlQMkxmcERaOFJtNzdMCk5rT2tGMnk3M3l5YUhFeEw5S1FyMys0Um9ubCtqTlp2YnV0QVdxSDVodEE0dER4MUd3NE85OEg4YWlTcGh1eWQKRUliTGRrQm54OGlDZUdxbFBnbHZ6Q1dLV0xVZlhGbXplMkF5UjBzaWMyYXZRLzZyclYwb3pDdGQ1T0Vod093agphaCs3N1dZV1l0bkEraDhXZVZreWcvdG44UTJJOXo5ZVJYdlZxR2sxMDZLcWRtZFdiU2tIZzA4cFRUSGhVM2paCnMvZGNjdEdOMWFFanlUQWY0QzdHT2lrcUd1MGFTaW1aeDFOM2RWQzBobngySjJtdlhNQ0VtZ0g3TjVnZUxWUFAKOWdkQjdBQkJBb0dCQU5sT2hGQVhaTHV6Y0Ftczl1K3AxM05STWRFOHpIK2ZFaFBrbk9zZ21Xb3VqUzkxQTRtZgpuK01oN3d5bTZoVU1DbDk2WUNMNGtPM0RUMmlYWlRqTXZuMHBoVEx1MXNYcGxWNDJuamRnZGd3cFBEM0FnL1Y5ClVvV2hxdVhoa1I3RFpsUGg5Nmk1aEE0M1BvbTVPQm9BektJbEcrT3ZKUkhhZEVveC9jSmZScFd2QW9HQkFQWjUKNnNmWDdESElCNEtBczRmMWRuNGZJUkMweUF2WVdCL1R3UzZHUWVoNFRFbDVuSkQwWk9ZRVdUbVVBK3pPanZTNApuM09tZ2xNQTU5SGd1ZW13QXVRcEtwWFBOcFUvTERJaThtNnpmTUpvL3E5M0NOQlFQZngzZGh4ZVh4OXE2Mzg3Cm84QWxkOE42RGs4TThjRis3SlNaeUVJODJzLzdpdGRseXA2bFdLaGRBb0dCQUtnU0VrUGYxQWxZdjA2OGVFRGwKRzc0VkRuTEdrMlFobzltKzk1N2psOFNJUEtwMzFrU2JNUTU3TUdpWXNIT1czRzc4TjE3VTRVTUR6R2NZc1RFOQpLaGVrQldGZldMMjU2OHp5Y1d4akx1bzQrbDdJaDBkWHBudTBqbms5L1AvT0lWYS9iczBRcnhKUHFBN2RNb2JxCkYxdFJXRURCTmVxWkMxaFhVZTBEdzVRQkFvR0JBSjdBQ2NNcnhKcVBycDZVakkyK1FOS2M5Q3dSZEdPRXRjWFMKR3JQL2owWE83YnZKVTFsZHYvc1N3L0U4NzRZL3lIM0F5QnF5SFhDZXZiRkZZQmt1MzczYThlM0pwK3RhNC9scQozdUVFUkEvbmxscW5mWXJHbEJZZlQzaVlKQVpWVkZiL3I4bWJtRmJVTDVFazBqV0JyWmxNcjFwU1hkRGx3QmhhCkhMWXY0em1WQW9HQkFLQmw0cFNnbkNSTEJMUU9jWjhXQmhRSjAwZDZieFNrTGNpZ0xUNFJvY3RwNTY1SHJPMDAKSVFLdElTaEg1a2s3SVRHdUYvOERXZEN2djBMYnhvZVBJc2NFaStTaXk5WDZwWENPaS8xa2FyYVU5U3BpZ3czago3YjVlUVV0UlovTkIycVJwc3EzMEdCUENqanhudEVmK2lqelhUS0xNRndyUDhBMTlQNzRONGVTMAotLS0tLUVORCBSU0EgUFJJVkFURSBLRVktLS0tLQo=",
     * "jwk": "eyJ1c2UiOiJzaWciLCJrdHkiOiJSU0EiLCJraWQiOiI1MjEzY2ZhMTIxZjcwYjhjMTM4MDY4NmZmYzM3MWJhMyIsImFsZyI6IlJTMjU2IiwibiI6IjBUandqT2laV21KNDN2ZjNUbzREb1htWFd0SkdOR3lYZmh5dHRMYUJnRjEtRVFXUURLaG9LYm9hS21xakNBc21za3V0YkxVN1BVOGRrUU5ER1p3S3VWczA4elNaNGt4aTR0RWdQUFp5dDdkWEMtbFlSWW95ckFHRjRBWGh5MzI5YkhDUDFJbHJCQl9Ba0dnbmRMQWd1bnhZMHJSZ2N2T3ppYXc2S0p4Rm5jMlVLMFdVOGIwcDRLS0hHcDFLTDlkazBXVDhkVllxYmVSaUpqQ2xVRW1oOHl2OUNsT1ZhUzRLeGlYNnhUUTREWnc2RGFKZklWM1F0Tmd2cG1ieWxOSmFQSG5zc3JodDJHS1A5NjJlS2poUVJsaWd2SFhKTE9uSm9KZkxlSUVIWi1peFdmY1RETUg5MnNHdm93MURPanhMaUNOMXpISy1oN2JMb1hUaUxnYzRrdyIsImUiOiJBUUFCIiwiZCI6IndpQWEwaU5mWnNYSGNOcVMxSWhnUmdzVHJHay1TcFlYV2lReDZHTU9kWlJKekhGazN0bkRERFJvNHNKZTBxX0dEOWkzNlEyZkVadS15elpEcEJkc3U5OHNtaHhNU19Ta0s5X3VFYUo1Zm96V2IyN3JRRnFoLVliUU9MUThkUnNPRHZmQl9Hb2txWWJzblJDR3kzWkFaOGZJZ25ocXBUNEpiOHdsaWxpMUgxeFpzM3RnTWtkTEluTm1yMFAtcTYxZEtNd3JYZVRoSWNEc0kyb2Z1LTFtRm1MWndQb2ZGbmxaTW9QN1pfRU5pUGNfWGtWNzFhaHBOZE9pcW5ablZtMHBCNE5QS1UweDRWTjQyYlAzWEhMUmpkV2hJOGt3SC1BdXhqb3BLaHJ0R2tvcG1jZFRkM1ZRdElaOGRpZHByMXpBaEpvQi16ZVlIaTFUel9ZSFFld0FRUSIsInAiOiIyVTZFVUJka3U3TndDYXoyNzZuWGMxRXgwVHpNZjU4U0UtU2M2eUNaYWk2TkwzVURpWi1mNHlIdkRLYnFGUXdLWDNwZ0l2aVE3Y05QYUpkbE9NeS1mU21GTXU3V3hlbVZYamFlTjJCMkRDazhQY0NEOVgxU2hhR3E1ZUdSSHNObVUtSDNxTG1FRGpjLWliazRHZ0RNb2lVYjQ2OGxFZHAwU2pIOXdsOUdsYTgiLCJxIjoiOW5ucXg5ZnNNY2dIZ29DemhfVjJmaDhoRUxUSUM5aFlIOVBCTG9aQjZIaE1TWG1ja1BSazVnUlpPWlFEN002TzlMaWZjNmFDVXdEbjBlQzU2YkFDNUNrcWxjODJsVDhzTWlMeWJyTjh3bWotcjNjSTBGQTlfSGQySEY1ZkgycnJmenVqd0NWM3czb09Ud3p4d1g3c2xKbklRanphel91SzEyWEtucVZZcUYwIiwiZHAiOiJxQklTUTlfVUNWaV9Ucng0UU9VYnZoVU9jc2FUWkNHajJiNzNudU9YeElnOHFuZldSSnN4RG5zd2FKaXdjNWJjYnZ3M1h0VGhRd1BNWnhpeE1UMHFGNlFGWVY5WXZibnJ6UEp4YkdNdTZqajZYc2lIUjFlbWU3U09lVDM4Xzg0aFZyOXV6UkN2RWstb0R0MHlodW9YVzFGWVFNRTE2cGtMV0ZkUjdRUERsQUUiLCJkcSI6Im5zQUp3eXZFbW8tdW5wU01qYjVBMHB6MExCRjBZNFMxeGRJYXNfLVBSYzd0dThsVFdWMl8teExEOFR6dmhqX0lmY0RJR3JJZGNKNjlzVVZnR1M3ZnZkcng3Y21uNjFyai1XcmU0UVJFRC1lV1dxZDlpc2FVRmg5UGVKZ2tCbFZVVnYtdnladVlWdFF2a1NUU05ZR3RtVXl2V2xKZDBPWEFHRm9jdGlfak9aVSIsInFpIjoib0dYaWxLQ2NKRXNFdEE1eG54WUdGQW5UUjNwdkZLUXR5S0F0UGhHaHkybm5ya2VzN1RRaEFxMGhLRWZtU1RzaE1hNFhfd05aMEstX1F0dkdoNDhpeHdTTDVLTEwxZnFsY0k2TF9XUnF0cFQxS21LRERlUHR2bDVCUzFGbjgwSGFwR215cmZRWUU4S09QR2UwUl82S1BOZE1vc3dYQ3Nfd0RYMF92ZzNoNUxRIn0=",
     * "created": "2014-01-02T02:20:00Z"
     * }
     *
     * @link https://api.cloudflare.com/#stream-signing-keys-properties
     * @return object
     */
    public function createSigningKey()
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/keys", [], $headers, "POST");
    }

    /**
     * @link https://api.cloudflare.com/#stream-signing-keys-properties
     * @return object
     */
    public function listKeys()
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/keys", [], $headers);
    }

    /**
     * @link https://api.cloudflare.com/#stream-signing-keys-properties
     * @param string $id
     * @return object
     */
    public function deleteKey($id)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        return $this->makeRequest("accounts/{$this->accountId}/stream/keys/{$id}", [], $headers, "DELETE");
    }

    /**
     * @link https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream#get-started-with-a-signing-utility
     * @link https://api.cloudflare.com/#stream-videos-create-a-signed-url-token-for-a-video
     * @param array $data id,pem,exp,nbf,downloadable,accessRules
     * @return object
     */
    public function createSignedUrl($uid, $data = [])
    {
        if ($this->privateKeyId && !isset($data['id'])) {
            $data['id'] = $this->privateKeyId;
        }
        if ($this->privateKeyPem && !isset($data['pem'])) {
            $data['pem'] = $this->privateKeyPem;
        }
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}/token", $data, [], "POST");
    }

    /**
     * @link https://developers.cloudflare.com/stream/viewing-videos/download-videos
     * @param string $uid
     * @return object
     */
    public function makeDownloadable($uid)
    {
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}/downloads", [], [], "POST");
    }

    /**
     * @link https://developers.cloudflare.com/stream/viewing-videos/download-videos
     * @param string $uid
     * @return object
     */
    public function getDownloadLinks($uid)
    {
        return $this->makeRequest("accounts/{$this->accountId}/stream/{$uid}/downloads", [], [], "GET");
    }

    /**
     * Get playback URLs of a specific video.
     *
     * @param string $uid
     * @param bool $useSignedToken
     * @return string
     */
    public function getPlaybackURLs($uid, $useSignedToken = true)
    {
        $video = $this->videoDetails($uid);

        // Signed URL necessary?
        if ($useSignedToken && $video->result->requireSignedURLs) {
            // Replace uid with signed token
            foreach ($video->result->playback as $key => $value) {
                $video->result->playback[$key] = str_replace($uid, $this->getSignedToken($uid), $value);
            }
        }

        // Return playback URLs
        return json_encode($video->result->playback);
    }

    /**
     * Get width and height of a video.
     *
     * @param string $uid
     * @return object
     */
    public function getDimensions($uid)
    {
        $video = $this->videoDetails($uid);
        return $video->result->input;
    }

    /**
     * Get signed token for a video.
     *
     * @link https://developers.cloudflare.com/stream/viewing-videos/securing-your-stream#signing-tokens-in-production
     * @param string $uid
     * @param int $addHours
     * @return string
     */
    public function getSignedToken($uid, $addHours = 4)
    {
        if (empty($this->privateKeyId) || empty($this->privateKeyPem)) {
            throw new Exception("No signing key");
        }

        $key = base64_decode($this->privateKeyPem);
        return JWT::encode([
            'kid' => $this->privateKeyId,
            'sub' => $uid,
            "exp" => time() + ($addHours * 60 * 60)
        ], $key, 'RS256');
    }
}
