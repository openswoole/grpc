<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace OpenSwoole\GRPC;

use OpenSwoole\GRPC\Exception\GRPCException;
use OpenSwoole\GRPC\Exception\InvokeException;
use OpenSwoole\GRPC\Exception\NotFoundException;

final class Server
{
    private string $host;

    private int $port;

    private int $mode;

    private int $sockType;

    private array $settings = [
        \Swoole\Constant::OPTION_OPEN_HTTP2_PROTOCOL => 1,
        \Swoole\Constant::OPTION_ENABLE_COROUTINE    => true,
    ];

    private array $services = [];

    private array $interceptors = [];

    private $server;

    public function __construct(string $host, int $port = 0, int $mode = \SWOOLE_BASE, int $sockType = \SWOOLE_SOCK_TCP)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->mode     = $mode;
        $this->sockType = $sockType;
        $server         = new \Swoole\HTTP\Server($this->host, $this->port, $this->mode, $this->sockType);
        $server->on('start', function () {
            \swoole_error_log(\SWOOLE_LOG_INFO, "OpenSwoole GRPC Server is started grpc://{$this->host}:{$this->port}");
        });
        $this->server   = $server;
        return $this;
    }

    public function set(array $settings): self
    {
        $this->settings = array_merge($this->settings, $settings ?? []);
        return $this;
    }

    public function start()
    {
        $this->server->set($this->settings);
        $this->server->on('request', function (\Swoole\HTTP\Request $request, \Swoole\HTTP\Response $response) {
            $this->process($request, $response);
        });
        $this->server->start();
    }

    public function on(string $event, object $callback)
    {
        $this->server->on($event, function () use ($callback) { $callback->call($this); });
        return $this;
    }

    public function register(string $class): self
    {
        if (!class_exists($class)) {
            throw new \Exception("{$class} not found");
        }
        $instance = new $class();
        if (!($instance instanceof ServiceInterface)) {
            throw new \Exception("{$class} is not ServiceInterface");
        }
        $service                             = new ServiceContainer($class, $instance);
        $this->services[$service->getName()] = $service;
        return $this;
    }

    public function withInterceptor(string $interceptor): self
    {
        if (!class_exists($interceptor)) {
            throw new \Exception("{$interceptor} not found");
        }
        $instance = new $interceptor();
        if (!($instance instanceof InterceptorInterface)) {
            throw new \Exception("{$interceptor} is not ServiceInterface");
        }

        $this->interceptors[] = $instance;
        return $this;
    }

    public function process(\Swoole\HTTP\Request $request, \Swoole\HTTP\Response $response)
    {
        $context = new Context([
            \OpenSwoole\GRPC\Server::class          => $this,
            \Swoole\HTTP\Server::class              => $this->server,
            \Swoole\Http\Request::class             => $request,
            \Swoole\Http\Response::class            => $response,
            Constant::CONTENT_TYPE                  => $request->header[Constant::CONTENT_TYPE] ?? '',
            Constant::GRPC_STATUS                   => Status::UNKNOWN,
            Constant::GRPC_MESSAGE                  => '',
        ]);

        $context->interceptors = $this->interceptors;

        try {
            $this->validateRequest($request);

            [, $service, $method]        = explode('/', $request->server['request_uri'] ?? '');
            $service                     = '/' . $service;
            $requestMessage              = $request->getContent() ? substr($request->getContent(), 5) : '';

            $responseMessage = $this->handle($service, $method, $context, $requestMessage);

            $context = $context->withValue(Constant::GRPC_STATUS, Status::OK);
        } catch (GRPCException $e) {
            \swoole_error_log(\SWOOLE_LOG_ERROR, $e->getMessage() . ', error code: ' . $e->getCode());
            $responseMessage = '';
            $context         = $context->withValue(Constant::GRPC_STATUS, $e->getCode());
            $context         = $context->withValue(Constant::GRPC_MESSAGE, $e->getMessage());
        }

        $pack = $this->packResponse($context, $responseMessage);

        try {
            foreach ($pack['headers'] as $name => $value) {
                $response->header($name, $value);
            }

            foreach ($pack['trailers'] as $name => $value) {
                $response->trailer($name, (string) $value);
            }
            $response->end($pack['payload']);
        } catch (\Swoole\Exception $e) {
            \swoole_error_log(\SWOOLE_LOG_ERROR, $e->getMessage() . ', error code: ' . $e->getCode());
        }
    }

    public function handle($service, $method, $context, $requestMessage)
    {
        if (empty($context->interceptors)) {
            return $this->execute($service, $method, $context, $requestMessage);
        }

        $interceptor = array_shift($context->interceptors);

        return $interceptor->handle($service, $method, $context, $requestMessage, $this);
    }

    public function execute($service, $method, $context = null, $payload = '')
    {
        $result = null;
        try {
            $result = $this->invoke($service, $method, $context, $payload);
        } catch (\Throwable $e) {
            throw InvokeException::create($e->getMessage(), Status::INTERNAL, $e);
        }

        return $result;
    }

    public function push($context, $result)
    {
        try {
            if ($context->getValue('content-type') !== 'application/grpc+json') {
                $payload = $result->serializeToString();
            } else {
                $payload = $result->serializeToJsonString();
            }
        } catch (\Throwable $e) {
            throw InvokeException::create($e->getMessage(), Status::INTERNAL, $e);
        }

        $payload = pack('CN', 0, strlen($payload)) . $payload;

        return $context->getValue(\Swoole\Http\Response::class)->write($payload);
    }

    private function packResponse(Context $context, $payload)
    {
        $headers = [
            'content-type' => $context->getValue('content-type'),
            'trailer'      => 'grpc-status, grpc-message',
        ];

        $trailers = [
            Constant::GRPC_STATUS  => $context->getValue(Constant::GRPC_STATUS),
            Constant::GRPC_MESSAGE => $context->getValue(Constant::GRPC_MESSAGE),
        ];

        $payload = pack('CN', 0, strlen($payload)) . $payload;

        return [
            'headers'  => $headers,
            'trailers' => $trailers,
            'payload'  => $payload,
        ];
    }

    private function validateRequest(\Swoole\HTTP\Request $request)
    {
        if (!isset($request->header['content-type']) || !isset($request->header['te'])) {
            throw InvokeException::create('illegal GRPC request, missing content-type or te header');
        }

        if ($request->header['content-type'] !== 'application/grpc'
            && $request->header['content-type'] !== 'application/grpc+proto'
            && $request->header['content-type'] !== 'application/grpc+json'
        ) {
            throw InvokeException::create("Content-type not supported: {$request->header['content-type']}", Status::INTERNAL);
        }
    }

    private function invoke(string $service, string $method, $context, string $payload): string
    {
        if (!isset($this->services[$service])) {
            throw NotFoundException::create("{$service}::{$method} not found");
        }
        return $this->services[$service]->invoke($method, $context, $payload);
    }
}
