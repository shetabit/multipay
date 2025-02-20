<?php

namespace Shetabit\Multipay;

use Exception;
use JsonSerializable;

class RedirectionForm implements JsonSerializable, \Stringable
{
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
     */
    public function __construct(protected string $action, protected array $inputs = [], protected string $method = 'POST')
    {
    }

    /**
     * Retrieve default view path.
     */
    public static function getDefaultViewPath() : string
    {
        return dirname(__DIR__).'/resources/views/redirect-form.php';
    }

    /**
     * Set view path
     *
     *
     */
    public static function setViewPath(string $path): void
    {
        static::$viewPath = $path;
    }

    /**
     * Retrieve view path.
     */
    public static function getViewPath() : string
    {
        return static::$viewPath ?? static::getDefaultViewPath();
    }

    /**
     * Set view renderer
     */
    public static function setViewRenderer(callable $renderer): void
    {
        static::$viewRenderer = $renderer;
    }

    /**
     * Retrieve default view renderer.
     */
    protected function getDefaultViewRenderer() : callable
    {
        return function (string $view, string $action, array $inputs, string $method): string|false {
            ob_start();

            require($view);

            return ob_get_clean();
        };
    }

    /**
     * Retrieve associated method.
     */
    public function getMethod() : string
    {
        return $this->method;
    }

    /**
     * Retrieve associated inputs
     */
    public function getInputs() : array
    {
        return $this->inputs;
    }

    /**
     * Retrieve associated action
     */
    public function getAction() : string
    {
        return $this->action;
    }

    /**
     * Alias for getAction method.
     *
     * @alias getAction
     */
    public function getUrl() : string
    {
        return $this->getAction();
    }

    /**
     * Render form.
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
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Send application/json header
     */
    private function sendJsonHeader(): void
    {
        header('Content-Type: application/json');
    }
}
