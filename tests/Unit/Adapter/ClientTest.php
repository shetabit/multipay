<?php

namespace Shetabit\Multipay\Tests\Unit\Adapter;

use Shetabit\Multipay\Http\Client;
use Shetabit\Multipay\Http\HttpAdapter;
use Shetabit\Multipay\Http\Response;
use Shetabit\Multipay\Tests\TestCase;

class ClientTest extends TestCase
{
    protected $client;

    protected function setUp(): void
    {
        $this->client = new Client('https://httpbin.org/', static::class);
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

        $response = $this->client->get('get', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertEquals($data, $response->getData()['args']);
    }
    public function testPost()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];

        $response = $this->client->post('post', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($data, $response->getData()['json']);
    }

    public function testPatch()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];

        $response = $this->client->patch('patch', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($data, $response->getData()['json']);
    }

    public function testDelete()
    {
        $data = [
            'foo' => 'bar',

            'message' => 'ok!',
        ];

        $response = $this->client->delete('delete', $data);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals(static::class, $response->getDriver());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($data, $response->getData()['json']);
    }
    public function testError404()
    {
        $response = $this->client->get('status/404');
        $this->assertEquals(404, $response->getStatusCode());

        $this->assertFalse($response->isSuccess());

        $this->assertTrue($response->isNotFound());
    }

    public function testServerError()
    {
        $response = $this->client->get('status/500');
        $this->assertEquals(500, $response->getStatusCode());

        $this->assertFalse($response->isSuccess());

        $this->assertTrue($response->isServerError());
    }
}
