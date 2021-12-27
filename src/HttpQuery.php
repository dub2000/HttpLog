<?php

namespace Dub2000\HttpLog;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HttpQuery
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public static function logs()
    {
        return DB::table('http_log')->select()->get();
    }

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

        if ($method == 'get') {
            return Http::withHeaders($headers)->get($url);
        }

        $http_log_id = DB::table('http_log')->insertGetId([
            'method' => mb_strtoupper($method),
            'group' => $group,
            'created_at' => date('Y-m-d H:i:s'),
            'url' => $url,
            'payload' => $data ? json_encode($data) : null,
            'headers' => json_encode($headers),
        ]);

        $response = Http::withHeaders($headers)->$method($url, $data);

        $endTime = microtime(true);

        DB::table('http_log')->where('id', $http_log_id)->update([
            'response_code' => $response->status(),
            'response_content' => json_encode($response->body()),
            'updated_at' => 'now()',
            'duration' => number_format($endTime - $beginTime, 3),
        ]);
        return $response;
    }

    public static function json(string $method, string $url, array $params = [], array $headers = [], $data = null, $group = null): Response
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        return self::run($method, $url, $params, $headers, $data, $group);
    }
}
