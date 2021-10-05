<?php

namespace Shetabit\Multipay\Http;

use Carbon\Carbon;

class Response implements \ArrayAccess
{

    /**
     * Response Date.
     *
     * @var Carbon
     */
    protected $date;

    /**
     * Payment Driver.
     *
     * @var string
     */
    protected $driver;

    /**
     * Response Data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * response status code
     * @var
     */
    protected $statusCode;

    /**
     * response status message
     * @var string[]
     */
    protected $statusMessages = [
        // INFORMATIONAL CODES
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        // SUCCESS CODES
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // REDIRECTION CODES
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy', // Deprecated
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        // CLIENT ERROR
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        444 => 'Connection Closed Without Response',
        451 => 'Unavailable For Legal Reasons',
        499 => 'Client Closed Request',
        // SERVER ERROR
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        599 => 'Network Connect Timeout Error',
    ];

    /**
     * Response constructor.
     *
     * @param string $driver
     * @param int $statusCode
     */
    public function __construct(string $driver, int $statusCode)
    {
        $this->driver = $driver;
        $this->statusCode = $statusCode;
        $this->date = Carbon::now();
    }


    /**
     * set Response Data.
     *
     * @param $key
     * @param null $value
     *
     * @return $this
     */
    public function data($key, $value = null) : self
    {
        $data = is_array($key) ? $key : [$key=>$value];

        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * get Response Data.
     *
     * @param null $key
     *
     * @return mixed
     */
    public function getData($key = null)
    {
        return is_null($key)
            ? $this->data
            : $this->data[$key] ?? null;
    }
    /**
     * Get Response Date.
     *
     * @return Carbon
     */
    public function getDate(): Carbon
    {
        return $this->date;
    }

    /**
     * Get Response ArvanCloud Driver.
     *
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get Response Message.
     *
     * @return string
     */
    public function getStatusCode(): string
    {
        return $this->statusCode;
    }

    /**
     * Check if request status is success
     * @return bool
     */
    public function isSuccess(): bool
    {
        $code = $this->getStatusCode();
        return  $code >= 200 && $code < 300;
    }

    /**
     * Is the request forbidden due to ACLs?
     *
     * @return bool
     */
    public function isForbidden(): bool
    {
        return (403 == $this->getStatusCode());
    }
    /**
     * Is the current status "informational"?
     *
     * @return bool
     */
    public function isInformational(): bool
    {
        $code = $this->getStatusCode();
        return ($code >= 100 && $code < 200);
    }

    /**
     * Does the status code indicate the resource is not found?
     *
     * @return bool
     */
    public function isNotFound(): bool
    {
        return (404 == $this->getStatusCode());
    }

    /**
     * Does the status code indicate the resource is gone?
     *
     * @return bool
     */
    public function isGone(): bool
    {
        return (410 == $this->getStatusCode());
    }

    /**
     * Do we have a normal, OK response?
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return (200 == $this->getStatusCode());
    }

    /**
     * Does the status code reflect a server error?
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        $code = $this->getStatusCode();
        return (500 <= $code && 600 > $code);
    }

    /**
     * Do we have a redirect?
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        $code = $this->getStatusCode();
        return (300 <= $code && 400 > $code);
    }
    /**
     * Get status message
     * @return string
     */
    public function getStatusMessages(): string
    {
        return $this->statusMessages[$this->getStatusCode()];
    }

    /**
     * Set status messages
     * @param $code
     * @param string|null $message
     */
    public function setStatusMessages($code, string $message = null)
    {
        $statusMessage = is_array($code) ? $code : [$code=>$message];
        $this->statusMessages = array_merge($this->statusMessages, $statusMessage);
    }

    /**
     * Throw Error if Request is Not Success
     * @param string $exception
     * @param string|null $message
     */
    public function throwError(string $exception, string $message = null)
    {
        if (! $this->isSuccess()) {
            throw new $exception($message??$this->getStatusMessages());
        }
    }

    /**
     * set Response Data.
     *
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $this->data($name, $value);
    }

    /**
     * get Response Data.
     *
     * @param $name
     *
     * @return mixed|null
     */
    public function __get($name)
    {
        return $this->GetData($name);
    }

    /**
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * @param mixed $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->data[$offset];
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     *
     * @return mixed|void
     */
    public function offsetSet($offset, $value)
    {
        return $this->data[$offset] = $value;
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }
}
