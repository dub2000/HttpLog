<?php

namespace Dub2000\HttpLog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HttpLog extends Model
{
    use HasFactory;

    protected $table = 'http_log';

    public static $group = null;

    public static function run(string $method, string $url, array $params = [], array $headers = [], $data = null, $group = null): Response
    {
        $method = mb_strtolower($method);

        if (count($params)) {
            if (!strpos($url, '?')) {
                $url .= '?';
            }
            $tmp = [];
            foreach ($params as $k => $v) {
                if (is_array($v)) {
                    continue;
                }
                $tmp[] = urlencode($k) . '=' . urlencode($v);
            }
            $url .= implode('&', $tmp);
        }

        $beginTime = microtime(true);

        if (!in_array(mb_strtoupper($method), config('http-log.methods-to-log'))) {
            return Http::withHeaders($headers)->get($url);
        }

        $http_log_id = DB::table('http_log')->insertGetId([
            'method' => mb_strtoupper($method),
            'group' => $group ?? self::$group,
            'created_at' => date('Y-m-d H:i:s'),
            'url' => $url,
            'payload' => $data ? json_encode($data) : null,
            'headers' => json_encode($headers),
        ]);

        if (is_array($data))
            $response = Http::withHeaders($headers)->$method($url, $data);
        elseif ($data)
            $response = Http::withHeaders($headers)->withBody($data, '')->$method($url);
        else
            $response = Http::withHeaders($headers)->$method($url);


        $endTime = microtime(true);
        DB::table('http_log')->where('id', $http_log_id)->update([
            'response_code' => $response->status(),
            'response_content' => json_encode($response->body()),
            'response_headers' => json_encode($response->headers()),
            'updated_at' => 'now()',
            'duration' => number_format($endTime - $beginTime, 3),
        ]);
        return $response;
    }

    public static function json(string $method, string $url, array $params = [], array $headers = [], $data = null, $group = null): Response
    {
        $headers['Content-Type'] = 'application/json';
//        $headers['Accept'] = 'application/json';

        return self::run($method, $url, $params, $headers, $data, $group);
    }

    public function getPayloadAttribute()
    {
        return json_decode($this->attributes['payload']);
    }
    public function getHeadersAttribute()
    {
        return json_decode($this->attributes['headers']);
    }
    public function getResponseContentAttribute()
    {
        return json_decode($this->attributes['response_content']);
    }
    public function getResponseHeadersAttribute()
    {
        return json_decode($this->attributes['response_headers']);
    }

}
