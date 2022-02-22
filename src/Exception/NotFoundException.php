<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace OpenSwoole\GRPC\Exception;

use OpenSwoole\GRPC\Status;

class UnimplementedException extends GRPCException
{
    protected const CODE = Status::NOT_FOUND;
}
