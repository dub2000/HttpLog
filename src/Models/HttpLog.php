<?php

namespace Dub2000\HttpLog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class HttpLog extends Model
{
    use HasFactory;

    protected $table = 'http_log';

    public static $group = null;

    private static function _writeToCurrentDatabase(array $logData): void
    {
        $row = ['updated_at' => 'now()'];
        foreach ($logData as $k => $v)
            $row[$k] = (is_array($v) || is_object($v)) ? json_encode($v) : $v;

        DB::table('http_log')->insert($row);
    }

    private static function _writeToFilesystem(array $logData): void
    {
        //umask(0);
        $dir = storage_path() . '/logs/http';
        if (!file_exists($dir))
            mkdir($dir);
        $group = $logData['group'] ?: '_';

        $path = $dir . '/' . $group . '.log';
        $fileExists = file_exists($path);

        file_put_contents($path, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);

        if (!$fileExists)
            chmod($path, 0666);
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

        $config = config('http-log');
        $toLog = in_array(mb_strtoupper($method), $config['methods-to-log']);

        if (is_array($data))
            $response = Http::withHeaders($headers)->$method($url, $data);
        elseif ($data)
            $response = Http::withHeaders($headers)->withBody($data, '')->$method($url);
        else
            $response = Http::withHeaders($headers)->$method($url);

        $endTime = microtime(true);

        if ($toLog) {
            $logData = [
                'group' => $group ?? self::$group,
                'created_at' => date('Y-m-d H:i:s'),
                'method' => mb_strtoupper($method),
                'url' => $url,
                'payload' => $data,
                'headers' => $headers,
                'response_code' => $response->status(),
                'response_content' => $response->body(),
                'response_headers' => $response->headers(),
                'duration' => number_format($endTime - $beginTime, 3),
            ];

            if (isset($config['storage']) && $config['storage'] == 'filesystem')
                self::_writeToFilesystem($logData);

            if (!isset($config['storage']) || $config['storage'] == 'database')
                self::_writeToCurrentDatabase($logData);

        }

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
