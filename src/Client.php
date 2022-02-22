<?php

declare(strict_types=1);
/**
 * This file is part of OpenSwoole RPC.
 * @link     https://openswoole.com
 * @contact  hello@openswoole.com
 * @license  https://github.com/openswoole/rpc/blob/master/LICENSE
 */
namespace OpenSwoole\GRPC;

class Client
{
    private $client;

    private $streams;

    private $closed = false;

    private $settings = [
        'timeout'                => 2,
        'open_eof_check'         => true,
        'package_max_length'     => 2000000,
        'max_concurrent_streams' => 1000,
        'max_retries'            => 30,
    ];

    public function __construct($host, $port)
    {
        $client = new \Swoole\Coroutine\Http2\Client($host, $port);
        // TODO: clientInterceptors
        $this->client  = $client;
        $this->streams = [];
        return $this;
    }

    public function set(array $settings): self
    {
        $this->settings = array_merge($this->settings, $settings ?? []);
        return $this;
    }

    public function connect()
    {
        $this->client->set($this->settings);
        if (!$this->client->connect()) {
            throw new \Exception(swoole_strerror($this->client->errCode, 9), $this->client->errCode);
        }

        \Swoole\Coroutine::create(function () {
            while (!$this->closed && [$streamId, $data, $pipeline, $trailers] = $this->recvData()) {
                if ($streamId > 0 && !$pipeline) {
                    $this->streams[$streamId][0]->push([$data,  $trailers]);
                    $this->streams[$streamId][0]->close();
                    unset($this->streams[$streamId]);
                } elseif ($streamId > 0) {
                    $this->streams[$streamId][0]->push([$data, $trailers]);
                }
            }
        });
        return $this;
    }

    public function stats()
    {
        return $this->client->stats();
    }

    public function close()
    {
        // send goaway?
        // drain streams?
        $this->closed = true;
        $this->client->close();
    }

    public function recv($streamId, $timeout = -1)
    {
        return $this->streams[$streamId][0]->pop($timeout);
    }

    public function sendPacket($streamId, $message, $type = 'proto', $end = false)
    {
        return $this->sendStreamPacket($streamId, $message, $type, $end);
    }

    public function send($method, $message, $type = 'proto', $end = true)
    {
        $retry = 0;
        while ($retry++ < $this->settings['max_retries']) {
            $streamId = $this->sendMessage($method, $message, $type, $end);
            if ($streamId && $streamId > 0) {
                $this->streams[$streamId] = [new \Swoole\Coroutine\Channel(1), $end];
                return $streamId;
            }
            if ($this->client->errCode > 0) {
                throw new \Exception(swoole_strerror($this->client->errCode, 9), $this->client->errCode);
            }
            \Swoole\Coroutine::usleep(10000);
        }
        return false;
    }

    private function sendStreamPacket($streamId, $message, $type, $end = false)
    {
        if ($type === 'proto') {
            $payload = $message->serializeToString();
        } elseif ($type === 'json') {
            $payload = $message;
        }
        $payload = pack('CN', 0, strlen($payload)) . $payload;
        return $this->client->write($streamId, $payload, $end);
    }

    private function sendMessage($method, $message, $type, $end = true)
    {
        $request           = new \Swoole\Http2\Request();
        $request->pipeline = false;
        $request->method   = 'POST';
        $request->path     = $method;
        $request->headers  = [
            'user-agent'     => 'grpc-openswoole/' . \SWOOLE_VERSION,
            'content-type'   => 'application/grpc+' . $type,
            'te'             => 'trailers',
        ];
        if ($type === 'proto') {
            $payload = $message->serializeToString();
        } elseif ($type === 'json') {
            $payload = $message;
        }
        $request->data = pack('CN', 0, strlen($payload)) . $payload;

        return $this->client->send($request);
    }

    private function recvData()
    {
        $response = $this->client->read(30);

        if (!$response) {
            if ($this->client->errCode > 0) {
                throw new \Exception(swoole_strerror($this->client->errCode, 9), $this->client->errCode);
            }
            \co::sleep(1);
            return [0, null, false, null];
        }

        // TODO: fix status may not be the next frame?
        if ($this->streams[$response->streamId][1]) {
            $status = $this->client->read(-1);
            if ($response->streamId === $status->streamId) {
                $response->headers['grpc-status']  = $status->headers['grpc-status'] ?? '0';
                $response->headers['grpc-message'] = $status->headers['grpc-message'] ?? '';
            }
        }

        if ($response && $response->data) {
            $data     = substr($response->data, 5);
            $trailers = ['grpc-status' => $response->headers['grpc-status'] ?? '0', 'grpc-message' => $response->headers['grpc-message'] ?? ''];

            return [$response->streamId, $data, $response->pipeline, $trailers];
        }

        return [0, null, false, null];
    }
}
