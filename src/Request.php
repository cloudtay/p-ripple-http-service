<?php declare(strict_types=1);
/*
 * Copyright (c) 2023 cclilshy
 * Contact Information:
 * Email: jingnigg@gmail.com
 * Website: https://cc.cloudtay.com/
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 版权所有 (c) 2023 cclilshy
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Cclilshy\PRipple\Http\Service;

use Cclilshy\PRipple\Core\Coroutine\Coroutine;
use Cclilshy\PRipple\Facade\Buffer;
use Cclilshy\PRipple\Facade\IO;
use Cclilshy\PRipple\Worker\Socket\TCPConnection;
use Closure;

/**
 * 请求实体
 */
class Request extends Coroutine
{
    public const        string ON_UPLOAD   = 'http.upload.complete';
    public const        string ON_DOWNLOAD = 'http.download.complete';

    /**
     * @var string|mixed
     */
    public string $host;

    /**
     * @var string|mixed
     */
    public string $scheme;

    /**
     * @var string
     */
    public string $url;

    /**
     * @var string
     */
    public string $method;

    /**
     * @var bool
     */
    public bool $upload;

    /**
     * @var array
     */
    public array $files = array();

    /**
     * @var string|mixed
     */
    public string $path;

    /**
     * @var string
     */
    public string $version;

    /**
     * @var string
     */
    public string $header;

    /**
     * @var string
     */
    public string $body;

    /**
     * @var array
     */
    public array $headerArray = array();

    /**
     * @var array|mixed
     */
    public array $post = array();

    /**
     * @var array
     */
    public array $query = array();

    /**
     * @var TCPConnection
     */
    public TCPConnection $client;

    /**
     * @var array
     */
    public array $serverArray = array();

    /**
     * @var mixed|array
     */
    public mixed $cookieArray = array();

    /**
     * @var bool
     */
    public bool $keepAlive = false;

    /**
     * @var Closure $exceptionHandler
     */
    public Closure $exceptionHandler;

    /**
     * Response包应该储存请求原始数据,包括客户端连接
     * 考虑到Request在HttpWorker中的生命周期,当请求对象在Worker中被释放时
     * 如用户下载文件等情况无需保留Request,只需在心跳期间Response依然与客户端交互
     * @var Response $response
     */
    public Response $response;

    /**
     * 请求原始单例
     * @var RequestSingle
     */
    private RequestSingle $requestSingle;
    /**
     * @var array $responseHeaders
     */
    private array $responseHeaders = [];

    private bool $complete = false;

    /**
     * @param RequestSingle $requestSingle
     */
    public function __construct(RequestSingle $requestSingle)
    {
        $this->url    = $requestSingle->url;
        $this->method = $requestSingle->method;
        if (($this->upload = $requestSingle->upload)) {
            $this->files = $requestSingle->uploadHandler->files;
        }
        $this->version     = $requestSingle->version;
        $this->header      = $requestSingle->header;
        $this->headerArray = $requestSingle->headers;

        if ($connection = $this->headerArray['Connection'] ?? null) {
            $this->keepAlive = strtoupper($connection) === 'KEEP-ALIVE';
        }

        $this->body   = $requestSingle->body;
        $this->client = $requestSingle->client;
        $info         = parse_url($this->url);
        if ($query = $info['query'] ?? null) {
            parse_str($query, $this->query);
        }
        $this->path   = $info['path'];
        $this->host   = $this->headerArray['host'] ?? '';
        $this->scheme = $info['scheme'] ?? '';
        if (isset($this->headerArray['Content-Type']) && $this->headerArray['Content-Type'] === 'application/json') {
            $this->post = json_decode($this->body, true);
        } else {
            parse_str($this->body, $this->post);
        }
        if ($cookie = $this->headerArray['Cookie'] ?? null) {
            $items = explode('; ', $cookie);
            foreach ($items as $item) {
                $keyValue = explode('=', $item);
                if (count($keyValue) === 2) {
                    $this->cookieArray[$keyValue[0]] = $keyValue[1];
                }
            }
        }
        $this->hash          = $requestSingle->hash;
        $this->requestSingle = $requestSingle;
        $this->response      = new Response($this);
        parent::__construct();
    }

    /**
     * 获取查询参数
     * @param string|null $key     查询键
     * @param mixed       $default 默认值
     * @return mixed
     */
    public function query(string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        } else {
            return $this->query[$key] ?? $default ?? null;
        }
    }

    /**
     * 获取提交参数
     * @param string|null $key     数据键
     * @param mixed|null  $default 默认值
     * @return mixed
     */
    public function post(string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        } else {
            return $this->post[$key] ?? $default ?? null;
        }
    }

    /**
     * @param string|null $key
     * @param mixed|null  $default
     * @return mixed
     */
    public function header(string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->headerArray;
        } else {
            return $this->headerArray[$key] ?? $default ?? null;
        }
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function cookie(string $key): string|null
    {
        return $this->cookieArray[$key] ?? null;
    }

    /**
     * 响应json
     * @param array|object|string $data
     * @param array|null          $headers
     * @return Response
     */
    public function respondJson(array|object|string $data, array|null $headers = []): Response
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);
        $this->response->setHeaders($headers);
        if (!is_string($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return $this->response->setBody($data);
    }

    /**
     * 响应一个下载请求
     * @param string     $path
     * @param string     $filename
     * @param array|null $headers
     * @return Response
     */
    public function respondFile(string $path, string $filename, array|null $headers = []): Response
    {
        $filesize                       = filesize($path);
        $headers['Content-Type']        = 'application/octet-stream';
        $headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        $headers['Content-Length']      = $filesize;
        $headers['Accept-Length']       = $filesize;
        IO::fileToSocket($path, $this->client);
        return $this->respondBody("", $headers);
    }

    /**
     * 订阅异步事件
     * @param string  $eventName
     * @param Closure $callable
     * @return void
     */
    public function on(string $eventName, Closure $callable): void
    {
        if ($eventName === Request::ON_UPLOAD) {
            $this->flag(Request::ON_UPLOAD);
            $this->on(RequestFactory::COMPLETE, function () {
                $this->erase(Request::ON_UPLOAD);
            });
        }
        parent::on($eventName, $callable);
    }

    /**
     * 响应基础文本
     * @param string     $body
     * @param array|null $headers
     * @return Response
     */
    public function respondBody(string $body, array|null $headers = []): Response
    {
        $headers = array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers);
        return $this->response->setHeaders($headers)->setBody($body);
    }

    /**
     * 重写Coroutine的main注入器
     * 将自身作为最基础的依赖对象,供其他类构建时使用
     * @param Closure $callable
     * @return Coroutine
     */
    public function setup(Closure $callable): Coroutine
    {
        $result = parent::setup($callable);
        $this->inject(Response::class, $this->response);
        $this->requestSingle->hash = $this->hash;
        return $result;
    }
}
