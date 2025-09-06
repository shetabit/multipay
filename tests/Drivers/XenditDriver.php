<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class XenditDriver extends Driver
{
    public const TRANSACTION_ID = 'xendit_test_transaction_123';
    public const REFERENCE_ID = 'xendit_ref_456';

    protected $invoice;
    protected $settings;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice = $invoice;
        $this->settings = (object) $settings;
    }

    public function purchase(): string
    {
        $this->invoice->transactionId(self::TRANSACTION_ID);
        return self::TRANSACTION_ID;
    }

    public function pay(): RedirectionForm
    {
        $invoiceUrl = 'https://checkout.xendit.co/web/' . self::TRANSACTION_ID;
        
        return new RedirectionForm($invoiceUrl, [], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        $receipt = new Receipt('xendit', self::REFERENCE_ID);
        
        // Add sample details for v2/invoices
        $receipt->detail('external_id', self::REFERENCE_ID);
        $receipt->detail('payment_method', 'BANK_TRANSFER');
        $receipt->detail('payment_channel', 'BCA');
        $receipt->detail('paid_at', date('Y-m-d\TH:i:s\Z'));
        
        return $receipt;
    }
}
