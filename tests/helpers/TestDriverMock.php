<?php


namespace Shetabit\Multipay\Tests\helpers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;

class TestDriverMock extends Driver
{
    protected $invoice;

    protected $settings;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings=$settings;
    }

    public function purchase()
    {
        // TODO: Implement purchase() method.
    }

    public function pay(): RedirectionForm
    {
        // TODO: Implement pay() method.
    }

    public function verify(): ReceiptInterface
    {
        // TODO: Implement verify() method.
    }
}
