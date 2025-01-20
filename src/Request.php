<?php

namespace Shetabit\Multipay;

final class Request
{
    /**
     * HTTP request's data.
     */
    protected array $requestData;

    /**
     * HTTP POST data.
     */
    protected array $postData;

    /**
     * HTTP GET data.
     */
    protected array $getData;

    /**
     * Overwritten methods
     * @var array
     */
    protected static $overwrittenMethods = [];

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
     *
     * @return mixed|null
     */
    public static function input(string $name)
    {
        if (isset(static::$overwrittenMethods['input'])) {
            return (static::$overwrittenMethods['input'])($name);
        }

        return (new static)->requestData[$name] ?? null;
    }

    /**
     * Retrieve HTTP POST data.
     *
     *
     * @return mixed|null
     */
    public static function post(string $name)
    {
        if (isset(static::$overwrittenMethods['post'])) {
            return (static::$overwrittenMethods['post'])($name);
        }

        return (new static)->postData[$name] ?? null;
    }

    /**
     * Retrieve HTTP GET data.
     *
     *
     * @return mixed|null
     */
    public static function get(string $name)
    {
        if (isset(static::$overwrittenMethods['get'])) {
            return (static::$overwrittenMethods['get'])($name);
        }

        return (new static)->getData[$name] ?? null;
    }

    /**
     * @param string $method
     * @param $callback
     */
    public static function overwrite($method, $callback): void
    {
        static::$overwrittenMethods[$method] = $callback;
    }
}
