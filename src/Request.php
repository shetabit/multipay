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
        $this->requestData = $_REQUEST;
        $this->postData = $_POST;
        $this->getData = $_GET;
    }

    /**
     * Retrieve HTTP request data.
     *
     * @param string $name
     *
     * @return mixed|null
     */
    public static function input(string $name)
    {
        return (new static)->requestData[$name] ?? null;
    }

    /**
     * Retrieve HTTP POST data.
     *
     * @param string $name
     *
     * @return void
     */
    public static function post(string $name)
    {
        return (new static)->postData[$name] ?? null;
    }

    /**
     * Retrieve HTTP GET data.
     *
     * @param string $name
     *
     * @return void
     */
    public static function get(string $name)
    {
        return (new static)->getData[$name] ?? null;
    }
}
