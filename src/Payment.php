<?php

namespace Shetabit\Multipay;

use Shetabit\Multipay\Contracts\DriverInterface;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\DriverNotFoundException;
use Shetabit\Multipay\Exceptions\TimeoutException;
use Shetabit\Multipay\Exceptions\InvoiceNotFoundException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Traits\HasPaymentEvents;
use Shetabit\Multipay\Traits\InteractsWithRedirectionForm;

class Payment
{
    use InteractsWithRedirectionForm;
    use HasPaymentEvents;

    /**
     * Payment Configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * Payment Driver Settings.
     *
     * @var array
     */
    protected $settings;

    /**
     * callbackUrl
     *
     * @var string
     */
    protected $callbackUrl;

    /**
     * Payment Driver Name.
     *
     * @var string
     */
    protected $driver;

    /**
     * Payment Driver Instance.
     *
     * @var object
     */
    protected $driverInstance;

    /**
     * @var Invoice
     */
    protected $invoice;

    /**
     * PaymentManager constructor.
     *
     * @param array $config
     *
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        $this->config = empty($config) ? $this->loadDefaultConfig() : $config;
        $this->invoice(new Invoice());
        $this->via($this->config['default']);
    }

    /**
     * Retrieve Default config's path.
     *
     * @return string
     */
    public static function getDefaultConfigPath() : string
    {
        return dirname(__DIR__).'/config/payment.php';
    }

    /**
     * Set custom configs
     * we can use this method when we want to use dynamic configs
     *
     * @param $key
     * @param $value|null
     *
     * @return $this
     */
    public function config($key, $value = null)
    {
        $configs = [];

        $key = is_array($key) ? $key : [$key => $value];

        foreach ($key as $k => $v) {
            $configs[$k] = $v;
        }

        $this->settings = array_merge($this->settings, $configs);

        return $this;
    }

    /**
     * Set callbackUrl.
     *
     * @param $url|null
     * @return $this
     */
    public function callbackUrl($url = null)
    {
        $this->config('callbackUrl', $url);

        return $this;
    }

    /**
     * Reset the callbackUrl to its original that exists in configs.
     *
     * @return $this
     */
    public function resetCallbackUrl()
    {
        $this->callbackUrl();

        return $this;
    }

    /**
     * Set payment amount.
     *
     * @param $amount
     * @return $this
     * @throws \Exception
     */
    public function amount($amount)
    {
        $this->invoice->amount($amount);

        return $this;
    }

    /**
     * Set a piece of data to the details.
     *
     * @param $key
     *
     * @param $value|null
     *
     * @return $this
     */
    public function detail($key, $value = null)
    {
        $this->invoice->detail($key, $value);

        return $this;
    }

    /**
     * Set transaction's id
     *
     * @param $id
     *
     * @return $this
     */
    public function transactionId($id)
    {
        $this->invoice->transactionId($id);

        return $this;
    }

    /**
     * Change the driver on the fly.
     *
     * @param $driver
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function via($driver)
    {
        $this->driver = $driver;
        $this->validateDriver();
        $this->invoice->via($driver);
        $this->settings = array_merge($this->loadDefaultConfig()['drivers'][$driver] ?? [], $this->config['drivers'][$driver]);

        return $this;
    }

    /**
     * Purchase the invoice
     *
     * @param Invoice $invoice|null
     * @param $finalizeCallback|null
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function purchase(Invoice $invoice = null, $finalizeCallback = null)
    {
        if ($invoice) { // create new invoice
            $this->invoice($invoice);
        }

        $this->driverInstance = $this->getFreshDriverInstance();

        //purchase the invoice
        $transactionId = $this->driverInstance->purchase();
        if ($finalizeCallback) {
            call_user_func_array($finalizeCallback, [$this->driverInstance, $transactionId]);
        }

        // dispatch event
        $this->dispatchEvent(
            'purchase',
            $this->driverInstance,
            $this->driverInstance->getInvoice()
        );

        return $this;
    }

    /**
     * Pay the purchased invoice.
     *
     * @param $initializeCallback|null
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function pay($initializeCallback = null)
    {
        $this->driverInstance = $this->getDriverInstance();

        if ($initializeCallback) {
            call_user_func($initializeCallback, $this->driverInstance);
        }

        $this->validateInvoice();

        // dispatch event
        $this->dispatchEvent(
            'pay',
            $this->driverInstance,
            $this->driverInstance->getInvoice()
        );

        return $this->driverInstance->pay();
    }

    /**
     * Verifies the payment
     *
     * @param $finalizeCallback|null
     *
     * @return ReceiptInterface
     *
     * @throws InvoiceNotFoundException
     * @throws PurchaseFailedException
     * @throws TimeoutException
     */
    public function verify($finalizeCallback = null) : ReceiptInterface
    {
        $this->driverInstance = $this->getDriverInstance();
        $this->validateInvoice();
        $receipt = $this->driverInstance->verify();

        if (!empty($finalizeCallback)) {
            call_user_func($finalizeCallback, $receipt, $this->driverInstance);
        }

        // dispatch event
        $this->dispatchEvent(
            'verify',
            $receipt,
            $this->driverInstance,
            $this->driverInstance->getInvoice()
        );

        return $receipt;
    }

    /**
     * Retrieve default config.
     *
     * @return array
     */
    protected function loadDefaultConfig() : array
    {
        return require(static::getDefaultConfigPath());
    }

    /**
     * Set invoice instance.
     *
     * @param Invoice $invoice
     *
     * @return self
     */
    protected function invoice(Invoice $invoice)
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Retrieve current driver instance or generate new one.
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getDriverInstance()
    {
        if (!empty($this->driverInstance)) {
            return $this->driverInstance;
        }

        return $this->getFreshDriverInstance();
    }

    /**
     * Get new driver instance
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getFreshDriverInstance()
    {
        $this->validateDriver();
        $class = $this->config['map'][$this->driver];

        if (!empty($this->callbackUrl)) { // use custom callbackUrl if exists
            $this->settings['callbackUrl'] = $this->callbackUrl;
        }

        return new $class($this->invoice, $this->settings);
    }

    /**
     * Validate Invoice.
     *
     * @throws InvoiceNotFoundException
     */
    protected function validateInvoice()
    {
        if (empty($this->invoice)) {
            throw new InvoiceNotFoundException('Invoice not selected or does not exist.');
        }
    }

    /**
     * Validate driver.
     *
     * @throws \Exception
     */
    protected function validateDriver()
    {
        if (empty($this->driver)) {
            throw new DriverNotFoundException('Driver not selected or default driver does not exist.');
        }

        if (empty($this->config['drivers'][$this->driver]) || empty($this->config['map'][$this->driver])) {
            throw new DriverNotFoundException('Driver not found in config file. Try updating the package.');
        }

        if (!class_exists($this->config['map'][$this->driver])) {
            throw new DriverNotFoundException('Driver source not found. Please update the package.');
        }

        $reflect = new \ReflectionClass($this->config['map'][$this->driver]);

        if (!$reflect->implementsInterface(DriverInterface::class)) {
            throw new \Exception("Driver must be an instance of Contracts\DriverInterface.");
        }
    }
}
