<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace OpenSwoole\GRPC;

class TraceInterceptor implements InterceptorInterface
{
    public function handle(string $service, string $method, Context $context, $request, $invoker)
    {
        return $invoker->handle($service, $method, $context, $request, $invoker);
    }
}
