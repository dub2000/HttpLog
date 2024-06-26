<?php

namespace Dub2000\HttpLog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

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
        //umask(0); "C:\Users\lg\PhpstormProjects\yam\packages\dub2000"
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

    private static function _readFromFilesystem(array $params): ?array
    {
        $filter = $params['filter'] ?? [];
        $sortColumn = $params['sort']['property'] ?? 'id';
        $sortDirection = $params['sort']['direction'] ?? 'desc';
        $group = $params['group'] ?? '_';
        $page = $params['pagination']['page'] ?? 0;
        $pageSize = $params['pagination']['page-size'] ?? 10;

        if (isset($filter['date']) && $filter['date'])
            $filter['date'] = Carbon::parse($filter['date'])->setTimezone('+3')->format('Y-m-d');

        if (!isset($filter['date']) && $pageSize != 1)
            return null;

        $path = storage_path() . '/logs/http/' . $group . '.log';
        if (!file_exists($path))
            return [];

        $fp = @fopen($path, "r");
        if (!$fp)
            return null;
        $data = [];

        $i = 0;
        while (($buffer = fgets($fp)) !== false) {
            $i++;
            $row = json_decode($buffer, true);
            if (!isset($row['group']) || $row['group'] != $group)
                continue;
            if (isset($filter['date']) && ($row['created_at'] < ($filter['date'] . ' 00:00:00') || $row['created_at'] > ($filter['date'] . ' 23:59:59')))
                continue;
            if (isset($filter['method']) && ($filter['method'] && !in_array($row['method'], explode(',', $filter['method']))))
                continue;
            if (isset($filter['mask'])) {
                $inFilter = false;

                $payloadStr = is_array($row['payload']) ? json_encode($row['payload']) : $row['payload'];
                if (isset($row['payload']) && trim($filter['mask']) && (mb_stripos($payloadStr, $filter['mask']) !== false))
                    $inFilter = true;

                if (mb_stripos($row['url'], $filter['mask']) !== false)
                    $inFilter = true;
                if (!$inFilter)
                    continue;
            }

            $row['id'] = $group . ':' . $i;
            $row['resource'] = explode('?', $row['url'])[0];

            if ($pageSize > 1) { // Для таблицы это лишние данные
                unset($row['url']);
                unset($row['payload']);
                unset($row['headers']);
                unset($row['response_content']);
                unset($row['response_headers']);
            }

            $data[] = $row;
        }

        fclose($fp);

        // Сортировка TODO: сделать по любому полю. Сейчас только по id (для файлов признак прямой последовательности без самого значения)
        if ($sortColumn == 'id' && $sortDirection == 'desc')
            $data = array_reverse($data);

        // Пагинация
        $data = array_slice($data, offset: $page * $pageSize, length: $pageSize);
        return $data;
    }

    private static function _clearFromFilesystem(string $group): void
    {
        $path = storage_path() . '/logs/http/' . $group . '.log';
        if (file_exists($path))
            unlink($path);
    }

    private static function _readFromCurrentDatabase(array $params): ?array
    {
        $filter = $params['filter'] ?? [];
        $sortColumn = $params['sort']['property'] ?? 'id';
        $sortDirection = $params['sort']['direction'] ?? 'desc';
        if (!isset($filter['date']))
            return null;

        $begin = $filter['date'] . ' 00:00:00';
        $end = $filter['date'] . ' 23:59:59';
        $builder = HttpLog::query()
            ->select()->orderBy($sortColumn, $sortDirection)
            ->where('group', '=', $params['group'] ?? null)
            ->where('created_at', '>', $begin)
            ->where('created_at', '<', $end);
        if ($filter['method'] ?? false)
            $builder->whereIn('method', explode(',', $filter['method']));
        if ($filter['mask'] ?? false) {
            $builder->where('url', 'like', $filter['mask']);
        }

        $items = $builder->paginate(perPage: 2, page: 1)->items();
        $data = [];
        foreach ($items as $item) {
            $attributes = [];
            foreach ($item->attributes as $k => $v) {
                if ($k == 'updated_at')
                    continue;
                $_v = json_decode($v, true);
                $attributes[$k] = $_v ?: $v;
            }
            $data[] = $attributes;
        }
        return $data;
    }

    private static function _clearFromCurrentDatabase(string $group): void
    {
        HttpLog::query()->where('group', '=', $group)
            ->delete();
    }


    public static function clearLogData(string $group): void
    {
        $config = config('http-log');
        if (isset($config['storage']) && $config['storage'] == 'filesystem')
            self::_clearFromFilesystem($group);

        if (!isset($config['storage']) || $config['storage'] == 'database')
            self::_clearFromCurrentDatabase($group);
    }

    public static function getLogData(array $params): ?array
    {
        $config = config('http-log');
        if (isset($config['storage']) && $config['storage'] == 'filesystem')
            return self::_readFromFilesystem($params);

        if (!isset($config['storage']) || $config['storage'] == 'database')
            return self::_readFromCurrentDatabase($params);

        return [];
    }

    public static function run(string $method, string $url, array $params = [], array $headers = [], $data = null, $group = null, $settings = null): Response
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

        $exception = null;
        $response = null;
        $timeout = $settings['timeout'] ?? 30;
        try {
            if (is_array($data)) {
                $response = Http::withHeaders($headers)->timeout($timeout)->$method($url, $data);
            } elseif ($data) {
                $headers['Content-Length'] = strlen($data);
                $response = Http::withHeaders($headers)->timeout($timeout)->withBody($data, $headers['Content-Type'] ?? 'application/json')->$method($url);

            } else {
                $response = Http::withHeaders($headers)->timeout($timeout)->$method($url);
            }
        } catch (\Exception $exception) {
        }

        $endTime = microtime(true);

        if ($toLog) {
            if ($exception) {
                $logData = [
                    'group' => $group ?? self::$group,
                    'created_at' => date('Y-m-d H:i:s'),
                    'method' => mb_strtoupper($method),
                    'url' => $url,
                    'payload' => $data,
                    'headers' => $headers,
                    'response_code' => $exception->getCode(),
                    'response_content' => $exception->getMessage(),
                    'response_headers' => [],
                    'duration' => number_format($endTime - $beginTime, 3),
                ];
            } else {
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
            }

            if (isset($config['storage']) && $config['storage'] == 'filesystem')
                self::_writeToFilesystem($logData);

            if (!isset($config['storage']) || $config['storage'] == 'database')
                self::_writeToCurrentDatabase($logData);
        }

        if ($exception)
            throw $exception;

        return $response;
    }

    public static function json(string $method, string $url, array $params = [], array $headers = [], $data = null, $group = null, $settings = null): Response
    {
        if (!isset($headers['Content-Type']))
            $headers['Content-Type'] = 'application/json';

        return self::run($method, $url, $params, $headers, $data, $group, $settings);
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
