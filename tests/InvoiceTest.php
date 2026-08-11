<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Invoice;

class InvoiceTest extends TestCase
{
    public function testItGeneratesAUuidOnCreation(): void
    {
        $invoice = new Invoice;

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $invoice->getUuid()
        );
    }

    public function testItGeneratesADifferentUuidForEachInvoice(): void
    {
        $this->assertNotEquals((new Invoice)->getUuid(), (new Invoice)->getUuid());
    }

    public function testUuidCanBeOverwritten(): void
    {
        $invoice = new Invoice;

        $this->assertSame($invoice, $invoice->uuid('my-custom-uuid'));
        $this->assertSame('my-custom-uuid', $invoice->getUuid());
    }

    public function testAnEmptyUuidFallsBackToAGeneratedOne(): void
    {
        $invoice = new Invoice;
        $invoice->uuid('');

        $this->assertNotEmpty($invoice->getUuid());
    }

    public function testAmountIsZeroByDefault(): void
    {
        $this->assertSame(0, (new Invoice)->getAmount());
    }

    public function testAmountAcceptsNumericValues(): void
    {
        foreach ([10000, 0, 1500.75, '20000'] as $amount) {
            $invoice = new Invoice;
            $invoice->amount($amount);

            $this->assertSame($amount, $invoice->getAmount(), 'Amount should accept '.var_export($amount, true));
        }
    }

    public function testAmountRejectsNonNumericValues(): void
    {
        foreach ([null, true, 'abc', [], new \stdClass] as $amount) {
            $invoice = new Invoice;

            try {
                $invoice->amount($amount);
                $this->fail('Amount should be rejected: '.gettype($amount));
            } catch (\Exception $exception) {
                $this->assertSame('Amount value should be a number (integer or float).', $exception->getMessage());
            }
        }
    }

    public function testTransactionIdIsNullByDefault(): void
    {
        $this->assertNull((new Invoice)->getTransactionId());
    }

    public function testTransactionIdCanBeSet(): void
    {
        $invoice = new Invoice;

        $this->assertSame($invoice, $invoice->transactionId('transaction-1'));
        $this->assertSame('transaction-1', $invoice->getTransactionId());
    }

    public function testDriverIsNullByDefault(): void
    {
        $this->assertNull((new Invoice)->getDriver());
    }

    public function testDriverCanBeSet(): void
    {
        $invoice = new Invoice;

        $this->assertSame($invoice, $invoice->via('bar'));
        $this->assertSame('bar', $invoice->getDriver());
    }

    public function testDetailsAreEmptyByDefault(): void
    {
        $this->assertSame([], (new Invoice)->getDetails());
    }

    public function testADetailCanBeSetUsingAKeyAndAValue(): void
    {
        $invoice = new Invoice;

        $this->assertSame($invoice, $invoice->detail('foo', 'bar'));
        $this->assertSame('bar', $invoice->getDetail('foo'));
        $this->assertSame(['foo' => 'bar'], $invoice->getDetails());
    }

    public function testMultipleDetailsCanBeSetUsingAnArray(): void
    {
        $invoice = new Invoice;
        $invoice->detail(['foo' => 'bar', 'john' => 'doe']);

        $this->assertSame(['foo' => 'bar', 'john' => 'doe'], $invoice->getDetails());
    }

    public function testDetailsAreMergedAndOverwrittenByKey(): void
    {
        $invoice = new Invoice;
        $invoice->detail('foo', 'bar');
        $invoice->detail(['foo' => 'baz', 'john' => 'doe']);

        $this->assertSame(['foo' => 'baz', 'john' => 'doe'], $invoice->getDetails());
    }

    public function testAnUnknownDetailIsNull(): void
    {
        $this->assertNull((new Invoice)->getDetail('unknown'));
    }
}
