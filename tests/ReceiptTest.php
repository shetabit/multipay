<?php

namespace Shetabit\Multipay\Tests;

use Carbon\Carbon;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Receipt;

class ReceiptTest extends TestCase
{
    public function testItImplementsTheReceiptContract(): void
    {
        $this->assertInstanceOf(ReceiptInterface::class, new Receipt('bar', 'reference-id'));
    }

    public function testItKeepsTheDriverName(): void
    {
        $this->assertSame('bar', new Receipt('bar', 'reference-id')->getDriver());
    }

    public function testItKeepsTheReferenceId(): void
    {
        $this->assertSame('reference-id', new Receipt('bar', 'reference-id')->getReferenceId());
    }

    public function testTheReferenceIdIsCastedToString(): void
    {
        $this->assertSame('122156415036', new Receipt('bar', 122156415036)->getReferenceId());
    }

    public function testItIsDatedAtCreationTime(): void
    {
        Carbon::setTestNow('2024-01-01 10:20:30');

        try {
            $receipt = new Receipt('bar', 'reference-id');

            $this->assertInstanceOf(Carbon::class, $receipt->getDate());
            $this->assertSame('2024-01-01 10:20:30', $receipt->getDate()->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testDetailsCanBeSetUsingAKeyAndAValue(): void
    {
        $receipt = new Receipt('bar', 'reference-id');

        $this->assertSame($receipt, $receipt->detail('orderId', 100));
        $this->assertSame(100, $receipt->getDetail('orderId'));
    }

    public function testDetailsCanBeSetUsingAnArray(): void
    {
        $receipt = new Receipt('bar', 'reference-id');
        $receipt->detail([
            'orderId' => 100,
            'traceNo' => 200,
        ]);

        $this->assertSame(['orderId' => 100, 'traceNo' => 200], $receipt->getDetails());
    }

    public function testDetailsCanBeSetUsingPropertyAccess(): void
    {
        $receipt = new Receipt('bar', 'reference-id');
        $receipt->cardNo = '1234';

        $this->assertSame('1234', $receipt->cardNo);
        $this->assertSame('1234', $receipt->getDetail('cardNo'));
    }

    public function testAnUnknownDetailIsNull(): void
    {
        $receipt = new Receipt('bar', 'reference-id');

        $this->assertNull($receipt->getDetail('unknown'));
        $this->assertNull($receipt->unknown);
    }
}
