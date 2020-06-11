<?php

namespace Shetabit\Multipay;

class Request
{
    /**
     * HTTP request's data.
     *
     * @var array
     */
    protected $requestData = [];

    /**
     * HTTP POST data.
     *
     * @var array
     */
    protected $postData = [];

    /**
     * HTTP GET data.
     *
     * @var array
     */
    protected $getData = [];

    /**
     * Request constructor.
     */
    public function __construct()
    {
        $this->request = $_REQUEST;
        $this->post = $_POST;
        $this->get = $_GET;
    }

    /**
     * Retrieve HTTP request data.
     *
     * @param string $name
     *
     * @return mixed|null
     */
    public function input(string $name)
    {
        return $this->requestData[$name] ?? null;
    }

    /**
     * Retrieve HTTP POST data.
     *
     * @param string $name
     *
     * @return void
     */
    public function post(string $name)
    {
        return $this->postData[$name] ?? null;
    }

    /**
     * Retrieve HTTP GET data.
     *
     * @param string $name
     *
     * @return void
     */
    public function get(string $name)
    {
        return $this->getData[$name] ?? null;
    }

    /**
     * Call methods statically.
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed|null
     */
    public function __callStatic($name, $arguments)
    {
        return call_user_func_array([new static, $name], $arguments);
    }
}
