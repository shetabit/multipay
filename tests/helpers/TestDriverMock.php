<?php


namespace Shetabit\Multipay\Tests\helpers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
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
        return "biSBUv86G";
    }

    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm('/', [], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        return new Receipt("test", "122156415036");
    }
}
