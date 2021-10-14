<?php

namespace Shetabit\Multipay\Http;

class Client implements HttpAdapter
{
    /**
     * guzzle http client.
     *
     * @var Client
     */
    protected $client;

    /**
     * Payment Driver.
     *
     * @var string
     */
    protected $driver;

    /**
     * Http constructor.
     *
     * @param \GuzzleHttp\Client|string $baseUrl
     * @param string $driver
     */
    public function __construct($baseUrl, string $driver)
    {
        $this->driver = $driver;
        $this->client = is_string($baseUrl)
            ? new \GuzzleHttp\Client([
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'base_uri'       => $baseUrl,
                'http_errors' =>false,
                'allow_redirects'=> false,
            ])
            : $baseUrl;
    }


    /**
     * Sends a GET request.
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     * @return Response
     */
    public function get(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('GET', $url, ['query'=>$data], $headers);
    }

    /**
     * Sends a Post request.
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     * @return Response
     */
    public function post(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $url, ['json'=>$data], $headers);
    }

    /**
     * Sends a Put request.
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     * @return Response
     */
    public function put(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('PUT', $url, ['json'=>$data], $headers);
    }

    /**
     * Sends a Patch request.
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     * @return Response
     */
    public function patch(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('PATCH', $url, ['json'=>$data], $headers);
    }

    /**
     * Sends a Delete request.
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     * @return Response
     */
    public function delete(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('DELETE', $url, ['json'=>$data], $headers);
    }

    /**
     * Sends a request.
     *
     * @param string $method
     * @param string $url
     * @param array $data
     * @param array $headers
     *
     *
     * @return Response
     */
    public function request(string $method, string $url, array $data = [], array $headers = []): Response
    {
        $response = $this->client->request($method, $url, $data, $headers);

        $responseData = json_decode(($response->getBody() ?? new \stdClass())->getContents(), true);

        return $this->response($responseData??[], $response->getStatusCode());
    }
    /**
     * Return Response.
     *
     * @param array $data
     * @param int $statusCode
     * @return Response
     */
    protected function response(array $data, int $statusCode): Response
    {
        $r = new Response($this->driver, $statusCode);
        $r->data($data);

        return $r;
    }
}
