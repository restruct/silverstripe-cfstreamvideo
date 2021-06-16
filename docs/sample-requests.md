#
# Check API credentials:
#
curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer [API_TOKEN]" \
     -H "Content-Type:application/json"

{"result":{"id":"adeb7d5fa0b9f5ce2bfafe6a5c398e","status":"active"},"success":true,"errors":[],"messages":[{"code":10000,"message":"This API Token is valid and active","type":null}]}

#
# TEST upload:
#
curl \
-X POST \
-d '{"url":"https://domain.tld/video.mp4","meta":{"name":"TESTVID"}}' \
-H "Authorization: Bearer [API_TOKEN]" \
https://api.cloudflare.com/client/v4/accounts/[ACCOUNT_ID]/stream/copy

{
  "result": {
    "uid": "a96d40583f3fd1d2676182e3e15f9383",
    "thumbnail": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/thumbnails/thumbnail.jpg",
    "thumbnailTimestampPct": 0,
    "readyToStream": false,
    "status": {
      "state": "downloading"
    },
    "meta": {
      "downloaded-from": "https://domain.tld/movie.mp4",
      "name": "TESTVID"
    },
    "created": "2021-06-14T07:49:04.1555Z",
    "modified": "2021-06-14T07:49:04.1555Z",
    "size": 70603950,
    "preview": "https://watch.videodelivery.net/a96d40583f3fd1d26762e3e15f9383",
    "allowedOrigins": [],
    "requireSignedURLs": false,
    "uploaded": "2021-06-14T07:49:04.155489Z",
    "uploadExpiry": null,
    "maxSizeBytes": null,
    "maxDurationSeconds": null,
    "duration": -1,
    "input": {
      "width": -1,
      "height": -1
    },
    "playback": {
      "hls": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/manifest/video.m3u8",
      "dash": "https://videodelivery.net/a96d40583f3fd1d26762e3e15f9383/manifest/video.mpd"
    },
    "watermark": null
  },
  "success": true,
  "errors": [],
  "messages": []
}

#
# Generate one-time upload link:
#
curl -X POST \
 -H 'Authorization: Bearer [API_TOKEN]' \
https://api.cloudflare.com/client/v4/accounts/[ACCOUNT_ID]/stream/direct_upload \
 --data '{
    "maxDurationSeconds": 3600,
    "expiry": "2021-06-14T11:20:00Z",
    "requireSignedURLs": true,
    "allowedOrigins": ["example.com"],
    "thumbnailTimestampPct": 0.568427
 }'

 {
   "result": {
     "uploadURL": "https://upload.videodelivery.net/9d3557b1a82f4f6db9b7eb8cb1310918",
     "uid": "9d3557b1a82f4f6db9b7eb8cb1310918",
     "watermark": null
   },
   "success": true,
   "errors": [],
   "messages": []
 }

#
# Generate TUS (resumable/chunked) one-time upload link:
#
curl -H "Authorization: bearer [API_TOKEN]" -X POST \
 -H 'Tus-Resumable: 1.0.0' -H 'Upload-Length: $VIDEO_LENGTH' \
 'https://api.cloudflare.com/client/v4/accounts/[ACCOUNT_ID]/stream?direct_user=true'

#
# Request URL signing keys (we sign the URL with these)
#
curl -X POST -H "Authorization: Bearer [API_TOKEN]"  "https://api.cloudflare.com/client/v4/accounts/[ACCOUNT_ID]/stream/keys"

#
# Request a signed viewing URL from STREAM (they sign)
#
curl -X POST -H "Authorization: Bearer [API_TOKEN]" \
 https://api.cloudflare.com/client/v4/accounts/[ACCOUNT_ID]/stream/[STREAM_ID]/token


