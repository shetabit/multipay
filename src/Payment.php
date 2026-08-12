<?php

namespace Shetabit\Multipay;

use Shetabit\Multipay\Contracts\DriverInterface;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\DriverNotFoundException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\TimeoutException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Traits\HasPaymentEvents;
use Shetabit\Multipay\Traits\InteractsWithRedirectionForm;
use ReflectionClass;
use Exception;

class Payment
{
    use InteractsWithRedirectionForm;
    use HasPaymentEvents;

    /**
     * Payment Configuration.
     */
    protected array $config;

    /**
     * Settings of the current payment driver.
     */
    protected array $settings = [];

    /**
     * Name of the current payment driver.
     */
    protected string $driver = '';

    /**
     * The driver that is doing the work.
     */
    protected DriverInterface|null $driverInstance = null;

    /**
     * The invoice that is being paid.
     */
    protected Invoice $invoice;

    /**
     * PaymentManager constructor.
     *
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        $this->config = $config === [] ? $this->loadDefaultConfig() : $config;
        $this->invoice(new Invoice());
        $this->via($this->config['default']);
    }

    /**
     * Retrieve Default config's path.
     */
    public static function getDefaultConfigPath() : string
    {
        return dirname(__DIR__).'/config/payment.php';
    }

    /**
     * Set custom configs
     * we can use this method when we want to use dynamic configs
     *
     * @param array|string $key   a single setting's name, or a set of settings
     * @param mixed        $value the value of a single setting
     */
    public function config(array|string $key, mixed $value = null): static
    {
        $this->settings = array_merge($this->settings, is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    /**
     * Set callbackUrl.
     *
     * The url becomes a setting of the driver that is selected at the time, so
     * a `via()` after it hands the driver its own callbackUrl again.
     */
    public function callbackUrl(string|null $url = null): static
    {
        $this->config('callbackUrl', $url);

        return $this;
    }

    /**
     * Reset the callbackUrl to its original that exists in configs.
     */
    public function resetCallbackUrl(): static
    {
        $settings = $this->driverConfig($this->driver);

        if (array_key_exists('callbackUrl', $settings)) {
            $this->settings['callbackUrl'] = $settings['callbackUrl'];
        } else { // the current driver has no callbackUrl of its own
            unset($this->settings['callbackUrl']);
        }

        return $this;
    }

    /**
     * Set payment amount.
     *
     * @throws \Exception
     */
    public function amount(mixed $amount): static
    {
        $this->invoice->amount($amount);

        return $this;
    }

    /**
     * Set a piece of data to the details.
     *
     * @param array|string $key   a single detail's name, or a set of details
     * @param mixed        $value the value of a single detail
     */
    public function detail(array|string $key, mixed $value = null): static
    {
        $this->invoice->detail($key, $value);

        return $this;
    }

    /**
     * Set transaction's id
     */
    public function transactionId(string|int|null $id): static
    {
        $this->invoice->transactionId($id);

        return $this;
    }

    /**
     * Change the driver on the fly.
     *
     * @throws \Exception
     */
    public function via(string $driver): static
    {
        $this->driver = $driver;
        $this->validateDriver();
        $this->invoice->via($driver);
        $this->settings = $this->driverConfig($driver);

        return $this;
    }

    /**
     * Purchase the invoice
     *
     * @throws \Exception
     */
    public function purchase(Invoice|null $invoice = null, callable|null $finalizeCallback = null): static
    {
        if ($invoice instanceof Invoice) { // create new invoice
            $this->invoice($invoice);
        }

        $this->driverInstance = $this->getFreshDriverInstance();

        //purchase the invoice
        $transactionId = $this->driverInstance->purchase();

        if ($finalizeCallback !== null) {
            $finalizeCallback($this->driverInstance, $transactionId);
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
     * @throws \Exception
     */
    public function pay(callable|null $initializeCallback = null) : RedirectionForm
    {
        $this->driverInstance = $this->getDriverInstance();

        if ($initializeCallback !== null) {
            $initializeCallback($this->driverInstance);
        }

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
     * @throws PurchaseFailedException
     * @throws PreviouslyVerifiedException
     * @throws TimeoutException
     */
    public function verify(callable|null $finalizeCallback = null) : ReceiptInterface
    {
        $this->driverInstance = $this->getDriverInstance();
        $receipt = $this->driverInstance->verify();

        if ($finalizeCallback !== null) {
            $finalizeCallback($receipt, $this->driverInstance);
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
     */
    protected function loadDefaultConfig() : array
    {
        return require(static::getDefaultConfigPath());
    }

    /**
     * The settings of the given driver, the way they are in the configuration.
     *
     * The settings that ship with the package are the ones a driver falls back
     * to for the keys the user did not configure themselves.
     */
    protected function driverConfig(string $driver) : array
    {
        return array_merge(
            $this->loadDefaultConfig()['drivers'][$driver] ?? [],
            $this->config['drivers'][$driver] ?? []
        );
    }

    /**
     * Set invoice instance.
     */
    protected function invoice(Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Retrieve current driver instance or generate new one.
     *
     * @throws \Exception
     */
    protected function getDriverInstance() : DriverInterface
    {
        return $this->driverInstance ?? $this->getFreshDriverInstance();
    }

    /**
     * Get new driver instance
     *
     * @throws \Exception
     */
    protected function getFreshDriverInstance() : DriverInterface
    {
        $this->validateDriver();
        $class = $this->config['map'][$this->driver];

        return new $class($this->invoice, $this->settings);
    }

    /**
     * Validate driver.
     *
     * @throws \Exception
     */
    protected function validateDriver() : void
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

        $reflect = new ReflectionClass($this->config['map'][$this->driver]);

        if (!$reflect->implementsInterface(DriverInterface::class)) {
            throw new Exception("Driver must be an instance of Contracts\DriverInterface.");
        }
    }
}
