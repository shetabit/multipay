<?php

namespace Shetabit\Multipay;

use Exception;
use JsonSerializable;

class RedirectionForm implements JsonSerializable
{
    /**
     * Form's method
     *
     * @var string
     */
    protected $method = 'POST';

    /**
     * Form's inputs
     *
     * @var array
     */
    protected $inputs = [];

    /**
     * Form's action
     *
     * @var string
     */
    protected $action;

    /**
     * Redirection form view's path
     *
     * @var string
     */
    protected static $viewPath;

    /**
     * The callable function that renders the given view
     *
     * @var callable
     */
    protected static $viewRenderer;

    /**
     * Redirection form constructor.
     *
     * @param string $action
     * @param array $inputs
     * @param string $method
     */
    public function __construct(string $action, array $inputs = [], string $method = 'POST')
    {
        $this->action = $action;
        $this->inputs = $inputs;
        $this->method = $method;
    }

    /**
     * Retrieve default view path.
     *
     * @return string
     */
    public static function getDefaultViewPath() : string
    {
        return dirname(__DIR__).'/resources/views/redirect-form.php';
    }

    /**
     * Set view path
     *
     * @param string $path
     *
     * @return void
     */
    public static function setViewPath(string $path)
    {
        static::$viewPath = $path;
    }

    /**
     * Retrieve view path.
     *
     * @return string
     */
    public static function getViewPath() : string
    {
        return static::$viewPath ?? static::getDefaultViewPath();
    }

    /**
     * Set view renderer
     *
     * @param callable $renderer
     */
    public static function setViewRenderer(callable $renderer)
    {
        static::$viewRenderer = $renderer;
    }

    /**
     * Retrieve default view renderer.
     *
     * @return callable
     */
    protected function getDefaultViewRenderer() : callable
    {
        return function (string $view, string $action, array $inputs, string $method) {
            ob_start();

            require($view);

            return ob_get_clean();
        };
    }

    /**
     * Retrieve associated method.
     *
     * @return string
     */
    public function getMethod() : string
    {
        return $this->method;
    }

    /**
     * Retrieve associated inputs
     *
     * @return array
     */
    public function getInputs() : array
    {
        return $this->inputs;
    }

    /**
     * Retrieve associated action
     *
     * @return string
     */
    public function getAction() : string
    {
        return $this->action;
    }

    /**
     * Alias for getAction method.
     *
     * @alias getAction
     *
     * @return string
     */
    public function getUrl() : string
    {
        return $this->getAction();
    }

    /**
     * Render form.
     *
     * @return string
     */
    public function render() : string
    {
        $data = [
            "view" => static::getViewPath(),
            "action" => $this->getAction(),
            "inputs" => $this->getInputs(),
            "method" => $this->getMethod(),
        ];

        $renderer = is_callable(static::$viewRenderer) ? static::$viewRenderer : $this->getDefaultViewRenderer();

        return call_user_func_array($renderer, $data);
    }

    /**
     * Retrieve JSON format of redirection form.
     *
     * @param $options
     *
     * @throws Exception
     * @return string
     */
    public function toJson($options = JSON_UNESCAPED_UNICODE)
    {
        $this->sendJsonHeader();

        $json = json_encode($this, $options);

        if (json_last_error() != JSON_ERROR_NONE) {
            throw new Exception(json_last_error_msg());
        }

        return $json;
    }

    /**
     * Retrieve JSON format of redirection form.
     *
     * @return string
     */
    public function toString() : string
    {
        return $this->render();
    }

    /**
     * Serialize to json
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'action' => $this->getAction(),
            'inputs' => $this->getInputs(),
            'method' => $this->getMethod(),
        ];
    }

    /**
     * Retrieve string format of redirection form.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toString();
    }

    /**
     * Send application/json header
     *
     * @return void
     */
    private function sendJsonHeader()
    {
        header('Content-Type: application/json');
    }
}
