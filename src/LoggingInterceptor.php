<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace OpenSwoole\GRPC;

class LoggingInterceptor implements InterceptorInterface
{
    public function handle(string $service, string $method, Context $context, $request, $invoker)
    {
        $rawRequest = $context->getValue(\Swoole\Http\Request::class);
        $client     = $rawRequest->server['remote_addr'] . ':' . $rawRequest->server['remote_port'];
        $server     = $rawRequest->header['host'];
        $streamId   = $rawRequest->streamId;
        $ua         = $rawRequest->header['user-agent'];
        \swoole_error_log(\SWOOLE_LOG_INFO, "GRPC request: {$client}->{$server}, stream({$streamId}), " . $service . '/' . $method . ', ' . $ua);
        return $invoker->handle($service, $method, $context, $request, $invoker);
    }
}
