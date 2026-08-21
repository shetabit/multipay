<?php

namespace Shetabit\Multipay;

final class Request
{
    /**
     * HTTP request's data.
     */
    private readonly array $requestData;

    /**
     * HTTP POST data.
     */
    private readonly array $postData;

    /**
     * HTTP GET data.
     */
    private readonly array $getData;

    /**
     * The readers that were put in place of the ones reading the super globals.
     *
     * @var array<string, callable>
     */
    private static array $overwrittenMethods = [];

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
     */
    public static function input(string $name) : mixed
    {
        if (isset(self::$overwrittenMethods['input'])) {
            return (self::$overwrittenMethods['input'])($name);
        }

        return new self()->requestData[$name] ?? null;
    }

    /**
     * Retrieve HTTP POST data.
     */
    public static function post(string $name) : mixed
    {
        if (isset(self::$overwrittenMethods['post'])) {
            return (self::$overwrittenMethods['post'])($name);
        }

        return new self()->postData[$name] ?? null;
    }

    /**
     * Retrieve HTTP GET data.
     */
    public static function get(string $name) : mixed
    {
        if (isset(self::$overwrittenMethods['get'])) {
            return (self::$overwrittenMethods['get'])($name);
        }

        return new self()->getData[$name] ?? null;
    }

    /**
     * Read one of the methods above from somewhere else than the super globals.
     */
    public static function overwrite(string $method, callable $callback): void
    {
        self::$overwrittenMethods[$method] = $callback;
    }
}
