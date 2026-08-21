<?php

namespace Shetabit\Multipay;

use Exception;
use JsonSerializable;
use Stringable;
use Closure;

class RedirectionForm implements JsonSerializable, Stringable
{
    /**
     * Redirection form view's path
     */
    protected static string|null $viewPath = null;

    /**
     * The function that renders the given view.
     *
     * It is called with named arguments, so a renderer has to name its parameters
     * exactly like the ones below.
     *
     * @var (\Closure(string $view, string $action, array $inputs, string $method): string)|null
     */
    protected static Closure|null $viewRenderer = null;

    /**
     * Redirection form constructor.
     */
    public function __construct(
        protected readonly string $action,
        protected readonly array $inputs = [],
        protected readonly string $method = 'POST',
    ) {
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
        static::$viewRenderer = $renderer instanceof Closure ? $renderer : Closure::fromCallable($renderer);
    }

    /**
     * Retrieve default view renderer.
     *
     * @return \Closure(string $view, string $action, array $inputs, string $method): string
     */
    protected function getDefaultViewRenderer() : Closure
    {
        return static function (string $view, string $action, array $inputs, string $method): string {
            ob_start();

            require($view);

            return (string) ob_get_clean();
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

        $renderer = static::$viewRenderer ?? $this->getDefaultViewRenderer();

        return $renderer(...$data);
    }

    /**
     * Retrieve JSON format of redirection form.
     *
     * @throws Exception
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string|false
    {
        $this->sendJsonHeader();

        $json = json_encode($this, $options);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
     */
    public function jsonSerialize() : array
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
