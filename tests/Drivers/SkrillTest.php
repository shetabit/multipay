<?php

namespace Shetabit\Multipay\Tests\Drivers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Shetabit\Multipay\Drivers\Skrill\Skrill;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class SkrillTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'skrill';
    }

    protected function driverClass() : string
    {
        return Skrill::class;
    }

    public function testPurchasePreparesASessionAndReturnsTheTransactionId(): void
    {
        $invoice = new Invoice();
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $this->assertSame((string) $invoice->getUuid(), $driver->amount(15)->purchase());
        $this->assertSame((string) $invoice->getUuid(), $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPaymentUrl']);
        $this->assertSame('POST', $this->request()->getMethod());
    }

    public function testPurchaseSendsThePaymentToTheGatewayFromTheServer(): void
    {
        $invoice = new Invoice();
        $driver = $this->driver([
            'merchantEmail' => 'merchant@example.com',
            'currency' => 'USD',
            'language' => 'DE',
            'callbackUrl' => 'https://example.com/callback',
        ], $invoice);
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $driver->amount(15)->purchase();

        $this->assertSame(
            [
                'pay_to_email' => 'merchant@example.com',
                'transaction_id' => (string) $invoice->getUuid(),
                'amount' => '15',
                'currency' => 'USD',
                'language' => 'DE',
                'return_url' => 'https://example.com/callback',
                'cancel_url' => 'https://example.com/callback',
                'prepare_only' => '1',
            ],
            $this->requestForm()
        );
    }

    #[DataProvider('amounts')]
    public function testPurchaseSendsTheAmountWithoutTrailingZeros(int|float|string $amount, string $expected): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $driver->amount($amount)->purchase();

        $this->assertSame($expected, $this->requestForm()['amount']);
    }

    public static function amounts(): array
    {
        return [
            [23, '23'],
            ['23.00', '23'],
            [39.6, '39.6'],
            ['39.60', '39.6'],
            [10.99, '10.99'],
            [0.1 + 0.2, '0.3'],
            [39.6789, '39.68'],
        ];
    }

    public function testPurchaseTakesTheTransactionIdFromTheOrderIdOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $this->assertSame('order-1', $driver->detail('orderId', 'order-1')->amount(15)->purchase());
        $this->assertSame('order-1', $this->requestForm()['transaction_id']);
    }

    public function testPurchaseSendsTheWebhookUrlAndTheEmailOfThePayer(): void
    {
        $driver = $this->driver(['webhookUrl' => 'https://example.com/webhook']);
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $driver->detail('email', 'payer@example.com')->amount(15)->purchase();

        $this->assertSame('https://example.com/webhook', $this->requestForm()['status_url']);
        $this->assertSame('payer@example.com', $this->requestForm()['pay_from_email']);
    }

    public function testPurchaseLeavesTheOptionalFieldsOutByDefault(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $driver->amount(15)->purchase();

        $this->assertArrayNotHasKey('status_url', $this->requestForm());
        $this->assertArrayNotHasKey('pay_from_email', $this->requestForm());
    }

    public function testPurchaseFailsWhenTheGatewayPreparesNoSession(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->response('<html><body>Invalid request</body></html>', 400)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('The gateway did not prepare a payment session.');
        $this->expectExceptionCode(400);

        $driver->amount(15)->purchase();
    }

    public function testPayPostsTheSessionToThePaymentPage(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->sessionResponse()]);

        $driver->amount(15)->purchase();
        $form = $driver->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['sid' => '0eb5d0f6b94badfeae5b0364b26d0288'], $form->getInputs());
    }

    public function testPayFailsBeforeThePaymentIsPurchased(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $this->driver()->pay();
    }

    public function testVerifyReturnsAReceiptForAProcessedPayment(): void
    {
        $driver = $this->driver(
            ['merchantEmail' => 'merchant@example.com', 'mqiPassword' => 'the-mqi-password'],
            (new Invoice())->transactionId('order-1')
        );
        $this->fakeHttp($driver, [$this->statusResponse('2')]);

        $receipt = $driver->verify();

        $this->assertSame('skrill', $receipt->getDriver());
        $this->assertSame('101149910', $receipt->getReferenceId());
        $this->assertSame('payer@example.com', $receipt->getDetail('pay_from_email'));
        $this->assertRequestedUrl($this->settings()['apiQueryUrl']);
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('text/html', $this->request()->getHeaderLine('Accept'));
        $this->assertSame(
            [
                'action' => 'status_trn',
                'email' => 'merchant@example.com',
                'password' => md5('the-mqi-password'),
                'trn_id' => 'order-1',
            ],
            $this->requestForm()
        );
    }

    public function testVerifyTakesTheTransactionIdFromTheReturnUrl(): void
    {
        $this->fakeRequest(['transaction_id' => 'order-2', 'msid' => 'the-msid']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->statusResponse('2')]);

        $driver->verify();

        $this->assertSame('order-2', $this->requestForm()['trn_id']);
    }

    public function testVerifyAcceptsAPaymentForTheAmountAndTheCurrencyOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => 'EUR'], (new Invoice())->amount(17)->transactionId('order-1'));
        $this->fakeHttp($driver, [$this->statusResponse('2', '17.00', 'eur')]);

        $this->assertSame('101149910', $driver->verify()->getReferenceId());
    }

    public function testVerifyRefusesAPaymentOfAnotherOrderReplayedThroughTheReturnUrl(): void
    {
        // A payer who paid a cheap order once comes back with its transaction_id on an expensive one.
        $this->fakeRequest(['transaction_id' => 'order-1']);

        $driver = $this->driver(['currency' => 'EUR'], (new Invoice())->amount(250));
        $this->fakeHttp($driver, [$this->statusResponse('2', '17', 'EUR')]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('The payment of [17 EUR] does not match the invoice of [250 EUR].');

        $driver->verify();
    }

    public function testVerifyRefusesAPaymentInAnotherCurrency(): void
    {
        $driver = $this->driver(['currency' => 'USD'], (new Invoice())->amount(17)->transactionId('order-1'));
        $this->fakeHttp($driver, [$this->statusResponse('2', '17', 'EUR')]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('The payment of [17 EUR] does not match the invoice of [17 USD].');

        $driver->verify();
    }

    #[DataProvider('unprocessedStatuses')]
    public function testVerifyFailsForAPaymentThatIsNotProcessed(string $status, string $message): void
    {
        $driver = $this->driver([], (new Invoice())->transactionId('order-1'));
        $this->fakeHttp($driver, [$this->statusResponse($status)]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage($message);
        $this->expectExceptionCode((int) $status);

        $driver->verify();
    }

    public static function unprocessedStatuses(): array
    {
        return [
            ['0', 'The payment is pending.'],
            ['-1', 'The payment was cancelled.'],
            ['-2', 'The payment failed.'],
            ['-3', 'The payment was charged back.'],
        ];
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheQuery(): void
    {
        $driver = $this->driver([], (new Invoice())->transactionId('order-1'));
        $this->fakeHttp($driver, [$this->response("403\t\tTransaction not found: order-1\n")]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('403 Transaction not found: order-1');
        $this->expectExceptionCode(403);

        $driver->verify();
    }

    public function testVerifyFailsWithoutATransactionId(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, []);

        try {
            $this->expectException(InvalidPaymentException::class);

            $driver->verify();
        } finally {
            $this->assertSame(0, $this->requestCount());
        }
    }

    private function sessionResponse(): Response
    {
        return $this->response('0eb5d0f6b94badfeae5b0364b26d0288');
    }

    private function statusResponse(string $status, string $amount = '17', string $currency = 'EUR'): Response
    {
        return $this->response(
            "200\t\tOK\n"
            ."status=$status&merchant_id=9999981&mb_transaction_id=101149910&currency=$currency&amount=$amount"
            .'&transaction_id=order-1&pay_from_email=payer%40example.com&mb_currency=EUR',
            200,
            ['Content-Type' => 'text/html']
        );
    }
}
