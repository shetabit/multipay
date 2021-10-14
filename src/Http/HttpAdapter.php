<?php

namespace Shetabit\Multipay\Http;

interface HttpAdapter
{
    /**
     * Adapter constructor.
     *
     * @param $baseUrl
     * @param string $driver
     */
    public function __construct($baseUrl, string $driver);


    /**
     * Sends a GET request.
     * Per Robustness Principle - not including the ability to send a body with a GET request (though possible in the
     * RFCs, it is never useful).
     *
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     * @return mixed
     */
    public function get(string $url, array $data = [], array $headers = []): Response;

    /**
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     * @return mixed
     */
    public function post(string $url, array $data = [], array $headers = []): Response;

    /**
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     * @return mixed
     */
    public function put(string $url, array $data = [], array $headers = []): Response;

    /**
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     * @return mixed
     */
    public function patch(string $url, array $data = [], array $headers = []): Response;

    /**
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     * @return mixed
     */
    public function delete(string $url, array $data = [], array $headers = []): Response;

    /**
     * Sends a request.
     *
     * @param string $method
     * @param string $url
     * @param array  $data
     * @param array  $headers
     *
     *
     */
    public function request(string $method, string $url, array $data = [], array $headers = []): Response;
}
