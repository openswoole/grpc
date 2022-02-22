<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace Helloworld;

use OpenSwoole\GRPC;

class StreamService implements StreamInterface
{
    /**
     * @throws GRPC\Exception\InvokeException
     */
    public function FetchResponse(GRPC\ContextInterface $ctx, HelloRequest $request): HelloReply
    {
        while (1) {
            $name = $request->getName();
            $out  = new HelloReply();
            $out->setMessage('hello ' . $name . time());
            $ctx->getValue(\OpenSwoole\GRPC\Server::class)->push($ctx, $out);
            \co::sleep(1);
        }
    }
}
