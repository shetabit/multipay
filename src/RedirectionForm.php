<?php

namespace Shetabit\Multipay;

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

    public function __construct($action, array $inputs = [], $method = 'POST')
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
        return '../config/payment.php';
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
        self::$viewPath = $path;
    }

    /**
     * Retrieve view path.
     *
     * @return string
     */
    public static function getViewPath() : string
    {
        return self::$viewPath ?? self::getDefaultViewPath();
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
     * @param string $action
     * @param array $inputs
     * @param string $method
     *
     * @return string
     */
    public function render() : string
    {
        $data = [
            'method' => $this->getMethod(),
            'inputs' => $this->getInputs(),
            'action' => $this->getAction(),
        ];

        ob_start();

        extract($data);

        $viewPath = self::getViewPath();

        require($viewPath);

        return ob_get_clean();
    }

    /**
     * Retrieve JSON format of redirection form.
     *
     * @param $options
     *
     * @return string
     */
    public function toJson($options = JSON_UNESCAPED_UNICODE) : string
    {
        return json_encode($this, $options);
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
     * @return mixed
     */
    public function jsonSerialize()
    {
        return [
            'method' => $this->getMethod(),
            'inputs' => $this->getInputs(),
            'action' => $this->getAction(),
            'view' => $this->getViewPath(),
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
}