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

use Cclilshy\PRipple\Worker\WorkerNet;
use Closure;
use Cclilshy\PRipple\Core\Map\CoroutineMap;
use Cclilshy\PRipple\Core\Output;
use Cclilshy\PRipple\Core\Event\Event;
use Cclilshy\PRipple\PRipple;
use InvalidArgumentException;
use Throwable;
use Cclilshy\PRipple\Worker\Socket\TCPConnection;

/**
 * Http服务类
 */
class HttpWorker extends WorkerNet
{
    /**
     * 上传文件路径
     * @var string
     */
    public static string $uploadPath;

    /**
     * 请求超时时间
     * @var int $timeout
     */
    public int $timeout = 60;

    /**
     * Http流工厂
     * @var RequestFactory $requestFactory
     */
    private RequestFactory $requestFactory;

    /**
     * 请求处理器
     * @var Closure $requestHandler
     */
    private Closure $requestHandler;

    /**
     * 请求队列
     * @var Request[] $requests
     */
    private array $requests = [];

    /**
     * 请求异常处理器
     * @var Closure $exceptionHandler
     */
    private Closure $exceptionHandler;

    /**
     * 定义请求处理
     * @param Closure $requestHandler
     * @return void
     */
    public function defineRequestHandler(Closure $requestHandler): void
    {
        $this->requestHandler = $requestHandler;
    }

    /**
     * 定义异常处理器
     * @param Closure $exceptionHandler
     * @return void
     */
    public function defineExceptionHandler(Closure $exceptionHandler): void
    {
        $this->exceptionHandler = $exceptionHandler;
    }

    /**
     * 心跳
     * @return void
     */
    public function heartbeat(): void
    {
        while ($request = array_shift($this->requests)) {
            try {
                $request
                    ->setup(function (Request $request) {
                        $requesting = call_user_func($this->requestHandler, $request);
                        foreach ($requesting as $response) {
                            if ($response instanceof Response) {
                                $response->setHeader('Server', 'PRipple');
                                if ($request->keepAlive) {
                                    $response->setHeader('Connection', 'Keep-Alive');
                                    $response->setHeader('Keep-Alive', 'timeout=5, max=1000');
                                }
                                $request->client->send($response->__toString());
                                if (!$request->keepAlive) {
                                    $this->removeTcpConnection($request->client);
                                }
                            }
                        }
                    })
                    ->except($this->exceptionHandler)
                    ->timeout(function (Throwable $exception, Event $event, Request $request) {
                        call_user_func_array($this->exceptionHandler, [$exception, $event, $request]);
                    }, $this->timeout)
                    ->defer(fn() => $this->recover($request->hash))
                    ->execute();
            } catch (Throwable $exception) {
                Output::printException($exception);
            }
        }
        $this->busy = false;
    }

    /**
     * @param string $hash
     * @return void
     */
    private function recover(string $hash): void
    {
    }

    /**
     * 创建请求工厂
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->subscribe(Request::ON_UPLOAD);
        $this->subscribe(Request::ON_DOWNLOAD);
        $this->subscribe(RequestFactory::COMPLETE);
        $this->requestFactory = new RequestFactory($this);
        if (!$uploadPath = PRipple::getArgument('HTTP_UPLOAD_PATH')) {
            Output::printException(new InvalidArgumentException('HTTP_UPLOAD_PATH is not defined'));
            exit(0);
        }
        HttpWorker::$uploadPath = $uploadPath;
    }

    /**
     * 设置为非堵塞模式
     * @param TCPConnection $tcpConnection
     * @return void
     */
    public function onConnect(TCPConnection $tcpConnection): void
    {
        $tcpConnection->setReceiveBufferSize(81920);
        $tcpConnection->setSendBufferSize(81920);
    }

    /**
     * 原始报文到达,压入请求工厂
     * @param string        $context
     * @param TCPConnection $tcpConnection
     * @return void
     */
    public function onMessage(string $context, TCPConnection $tcpConnection): void
    {
        try {
            if (($request = $this->requestFactory->revolve($context, $tcpConnection)) instanceof Request) {
                $this->onRequest($request);
            }
        } catch (RequestSingleException $exception) {
            $tcpConnection->send(
                (new Response())->setStatusCode(400)->setBody($exception->getMessage())->__toString()
            );
        }
    }

    /**
     * 一个新请求到达
     * @param Request $request
     * @return void
     */
    public function onRequest(Request $request): void
    {
        $this->requests[$request->hash] = $request;
        $request->client->setName($request->hash);
        $this->busy = true;
    }

    /**
     * 回收请求
     * @param TCPConnection $tcpConnection
     * @return void
     */
    public function onClose(TCPConnection $tcpConnection): void
    {
        $this->recover($tcpConnection->getName());
    }

    /**
     * 处理事件
     * @param Event $event
     * @return void
     */
    public function handleEvent(Event $event): void
    {
        $hash = $event->source;
        try {
            CoroutineMap::resume($hash, $event);
        } catch (Throwable $exception) {
            Output::printException($exception);
        }
    }

    /**
     * 不接管任何父进程请求
     * @return void
     */
    public function forking(): void
    {
        parent::forking();
        foreach ($this->requests as $request) {
            $request->destroy();
            unset($this->requests[$request->hash]);
        }
    }
}
