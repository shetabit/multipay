<?php

namespace Shetabit\Multipay\Abstracts;

use Shetabit\Multipay\Contracts\DriverInterface;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;

abstract class Driver implements DriverInterface
{
    /**
     * The invoice the driver is working on.
     */
    protected Invoice $invoice;

    /**
     * The settings of the driver, as configured in `config/payment.php`.
     *
     * The drivers receive them as an array and keep them as an object.
     */
    protected object $settings;

    /**
     * Driver constructor.
     */
    abstract public function __construct(Invoice $invoice, array|object $settings);

    /**
     * Set payment amount.
     *
     * @throws \Exception
     */
    public function amount(mixed $amount) : static
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
    public function detail(array|string $key, mixed $value = null) : static
    {
        $this->invoice->detail($key, $value);

        return $this;
    }

    /**
     * Set invoice.
     */
    public function invoice(Invoice $invoice) : static
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Retrieve invoice.
     */
    public function getInvoice() : Invoice
    {
        return $this->invoice;
    }

    /**
     * Create payment redirection form.
     */
    public function redirectWithForm(string $action, array $inputs = [], string $method = 'POST') : RedirectionForm
    {
        return new RedirectionForm($action, $inputs, $method);
    }

    /**
     * Purchase the invoice
     *
     * @return string|int the transaction's id
     */
    abstract public function purchase() : string|int|null;

    /**
     * Pay the invoice
     */
    abstract public function pay() : RedirectionForm;

    /**
     * Verify the payment
     */
    abstract public function verify() : ReceiptInterface;
}
