<?php

namespace Shetabit\Multipay\Tests\Unit\Adapter;

use Shetabit\Multipay\Http\Client;
use Shetabit\Multipay\Http\HttpAdapter;
use Shetabit\Multipay\Http\Response;
use Shetabit\Multipay\Tests\TestCase;

class ClientTest extends TestCase
{
    protected $client;
    protected $guzzleMock;
    protected function setUp(): void
    {
        $this->guzzleMock = $this
            ->getMockBuilder(\GuzzleHttp\Client::class)
            ->setConstructorArgs(['config'=>['base_uri'=>'https://http.test/']])
            ->getMock();
        $this->client = new Client($this->guzzleMock, static::class);
    }

    public function testInstance()
    {
        $this->assertInstanceOf(HttpAdapter::class, $this->client);
    }

    public function testGet()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];

        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'get', ['query'=>$data], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response());
        $response = $this->client->get('get', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
    }
    public function testPost()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'post', ['json'=>$data], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response());
        $response = $this->client->post('post', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPut()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('PUT', 'put', ['json'=>$data], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response());
        $response = $this->client->put('put', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPatch()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('PATCH', 'patch', ['json'=>$data], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response());
        $response = $this->client->patch('patch', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelete()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('DELETE', 'delete', ['json'=>$data], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response());
        $response = $this->client->delete('delete', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
    }
    public function testError404()
    {
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'status/404', ['query'=>[]], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response(404));

        $response = $this->client->get('status/404');
        $this->assertEquals(404, $response->getStatusCode());

        $this->assertFalse($response->isSuccess());

        $this->assertTrue($response->isNotFound());
    }

    public function testServerError()
    {
        $this->guzzleMock
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'status/500', ['query'=>[]], [])
            ->willReturn(new \GuzzleHttp\Psr7\Response(500));

        $response = $this->client->get('status/500');
        $this->assertEquals(500, $response->getStatusCode());

        $this->assertFalse($response->isSuccess());

        $this->assertTrue($response->isServerError());
    }
}
