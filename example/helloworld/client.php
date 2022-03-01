<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/grpc/blob/main/LICENSE
 */
require __DIR__ . '/vendor/autoload.php';

use Helloworld\HelloRequest;
use OpenSwoole\GRPC\Client;
use OpenSwoole\GRPC\ClientFactory;
use OpenSwoole\GRPC\ClientPool;
use OpenSwoole\GRPC\Constant;

\Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR]);
// Co::set(['log_level' => SWOOLE_LOG_DEBUG]);

Co\run(function () {
    $conn = ClientFactory::make('127.0.0.1', 9501);
    $conn = (new Client('127.0.0.1', 9501))->connect();
    $client = new Helloworld\GreeterClient($conn);
    $message = new HelloRequest();
    $message->setName(str_repeat('x', 10));
    $out = $client->sayHello($message);

    var_dump($out->serializeToJsonString());
    $conn->close();
    echo "closed\n";

    // server streaming grpc

    $conn = ClientFactory::make('127.0.0.1', 9501);
    $conn = (new Client('127.0.0.1', 9501, Constant::GRPC_STREAM))->connect();
    $client = new Helloworld\StreamClient($conn);
    $message = new HelloRequest();
    $message->setName(str_repeat('x', 10));

    $out = $client->FetchResponse($message);
    var_dump($out->serializeToJsonString());

    while (1) {
        $out = $client->getNext();
        var_dump($out->serializeToJsonString());
    }

    $conn->close();
    echo "closed\n";

    // server streaming send/recv

    // $conn = (new Client('127.0.0.1', 9501))->connect();
    // $method = '/helloworld.Greeter/SayHello';
    // $message = new HelloRequest();
    // $message->setName(str_repeat('x', 100));
    // $streamId = $conn->send($method, $message);

    // while(1) {
    //     // TODO: end stream situation
    //     $data = $conn->recv($streamId);
    //     var_dump($data);
    // }

    // $conn->close();
    // echo "closed\n";

    // stream push
    // $conn = (new Client('127.0.0.1', 9501))->connect();
    // $method = '/helloworld.Greeter/SayHello';

    // $message = new HelloRequest();
    // $message->setName(str_repeat('x', 100). time());

    // // while(1) {
    // //     $conn->send($method, $message, 'proto', false);
    // //     \co::sleep(1);
    // // }

    // $streamId = $conn->send($method, $message, 'proto', true);

    // while(1) {
    //     var_dump($streamId);

    //     $message = new HelloRequest();
    //     $message->setName(str_repeat('x', 100). time());

    //     $conn->sendPacket($streamId, $message);
    //     \co::sleep(1);
    // }

    // $conn->close();
    // echo "closed\n";

    $conn = (new Client('127.0.0.1', 9501))->connect();
    $method = '/helloworld.Greeter/SayHello';

    $message = new HelloRequest();
    $message->setName(str_repeat('x', 100));
    $message = $message->serializeToJsonString();

    $streamId = $conn->send($method, $message, 'json');
    $data = $conn->recv($streamId);

    var_dump($data);

    $conn->close();
    echo "closed\n";

    $connpool = new ClientPool(ClientFactory::class, ['host' => '127.0.0.1', 'port' => 9501], 16);
    $now = microtime(true);
    $i = 16;
    $total = 100_000;

    while ($i-- > 0) {
        $conn = $connpool->get();
        go(function () use ($conn, $connpool, $now, &$total, $i) {
            $client = new Helloworld\GreeterClient($conn);

            $message = new HelloRequest();
            $message->setName(str_repeat('x', 100));

            while (1) {
                $total--;

                $out = $client->sayHello($message);

                if ($total <= 0) {
                    var_dump($out->serializeToJsonString());
                    echo (int) (100_000 / (microtime(true) - $now)) . "\n";
                    break;
                }
            }
            $connpool->put($conn);
            echo "DONE {$i}\n";
        });
    }

    go(function () use ($connpool) {
        co::sleep(1);
        $connpool->close();
        echo "CLOSE\n";
    });
});
