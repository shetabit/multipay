<?php

namespace Shetabit\Multipay\Tests\Drivers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Shetabit\Multipay\Drivers\Cryptomus\Cryptomus;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class CryptomusTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'cryptomus';
    }

    protected function driverClass() : string
    {
        return Cryptomus::class;
    }

    public function testPurchaseReturnsTheUuidOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $this->assertSame('invoice-uuid', $driver->amount(15)->purchase());
        $this->assertSame('invoice-uuid', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('POST', $this->request()->getMethod());
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $invoice = new Invoice();
        $driver = $this->driver(['currency' => 'EUR', 'callbackUrl' => 'https://example.com/callback'], $invoice);
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount(10.28)->purchase();

        $this->assertSame(
            [
                'amount' => '10.28',
                'currency' => 'EUR',
                'order_id' => (string) $invoice->getUuid(),
                'url_return' => 'https://example.com/callback',
                'url_success' => 'https://example.com/callback',
            ],
            $this->requestJson()
        );
    }

    #[DataProvider('amounts')]
    public function testPurchaseSendsTheAmountWithoutTheNoiseOfFloatArithmetic(
        int|float|string $amount,
        string $expected
    ): void {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount($amount)->purchase();

        $this->assertSame($expected, $this->requestJson()['amount']);
    }

    public static function amounts(): array
    {
        return [
            'float arithmetic' => [0.1 + 0.2, '0.3'],
            'whole float' => [15.0, '15'],
            'float' => [10.28, '10.28'],
            'eight decimals' => [0.12345678, '0.12345678'],
            'beyond eight decimals' => [0.123456789, '0.12345679'],
            'integer' => [100, '100'],
            'numeric string' => ['10.50', '10.50'],
        ];
    }

    public function testPurchaseTakesTheOrderIdFromTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->detail('orderId', 'order-1')->amount(15)->purchase();

        $this->assertSame('order-1', $this->requestJson()['order_id']);
    }

    public function testPurchaseSendsTheWebhookUrlWhenOneIsConfigured(): void
    {
        $driver = $this->driver(['webhookUrl' => 'https://example.com/webhook']);
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount(15)->purchase();

        $this->assertSame('https://example.com/webhook', $this->requestJson()['url_callback']);
    }

    public function testPurchaseLeavesTheWebhookUrlOutByDefault(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount(15)->purchase();

        $this->assertArrayNotHasKey('url_callback', $this->requestJson());
    }

    public function testPurchaseSignsTheBodyWithTheApiKey(): void
    {
        $driver = $this->driver(['merchantId' => 'merchant-uuid', 'apiKey' => 'the-api-key']);
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount(15)->purchase();

        $this->assertSame('merchant-uuid', $this->request()->getHeaderLine('merchant'));
        $this->assertSame(
            md5(base64_encode($this->requestBody()).'the-api-key'),
            $this->request()->getHeaderLine('sign')
        );
        $this->assertSame('application/json', $this->request()->getHeaderLine('Content-Type'));
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['state' => 1, 'message' => 'Merchant not found'], 401)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Merchant not found');
        $this->expectExceptionCode(401);

        $driver->amount(15)->purchase();
    }

    public function testPurchaseFailsWithTheValidationErrorsOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['state' => 1, 'errors' => ['amount' => ['validation.numeric']]], 422),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('amount: validation.numeric');

        $driver->amount(15)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayAnswersWithoutAnInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->response('not json')]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('The gateway refused the request.');

        $driver->amount(15)->purchase();
    }

    public function testPayRedirectsToThePaymentPageOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->invoiceResponse()]);

        $driver->amount(15)->purchase();
        $form = $driver->pay();

        $this->assertSame('https://pay.cryptomus.com/pay/invoice-uuid', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayFailsBeforeTheInvoiceIsPurchased(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $this->driver()->pay();
    }

    #[DataProvider('paidStatuses')]
    public function testVerifyReturnsAReceiptForAPaidInvoice(string $status): void
    {
        $driver = $this->driver([], (new Invoice())->transactionId('invoice-uuid'));
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'state' => 0,
                'result' => ['uuid' => 'invoice-uuid', 'payment_status' => $status, 'txid' => 'the-txid'],
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('cryptomus', $receipt->getDriver());
        $this->assertSame('invoice-uuid', $receipt->getReferenceId());
        $this->assertSame('the-txid', $receipt->getDetail('txid'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame(['uuid' => 'invoice-uuid'], $this->requestJson());
    }

    public static function paidStatuses(): array
    {
        return [['paid'], ['paid_over']];
    }

    #[DataProvider('unpaidStatuses')]
    public function testVerifyFailsForAnInvoiceThatIsNotPaid(string $status): void
    {
        $driver = $this->driver([], (new Invoice())->transactionId('invoice-uuid'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['state' => 0, 'result' => ['uuid' => 'invoice-uuid', 'payment_status' => $status]]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("The invoice has not been paid, its payment status is [$status].");

        $driver->verify();
    }

    public static function unpaidStatuses(): array
    {
        return [['check'], ['confirm_check'], ['wrong_amount'], ['cancel'], ['fail'], ['refund_paid']];
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

    public function testVerifyFailsWhenTheGatewayRefusesTheRequest(): void
    {
        $driver = $this->driver([], (new Invoice())->transactionId('invoice-uuid'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['state' => 1, 'errors' => ['uuid' => ['validation.exists']]], 422),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('uuid: validation.exists');

        $driver->verify();
    }

    private function invoiceResponse(): Response
    {
        return $this->jsonResponse([
            'state' => 0,
            'result' => [
                'uuid' => 'invoice-uuid',
                'order_id' => 'order-1',
                'url' => 'https://pay.cryptomus.com/pay/invoice-uuid',
                'payment_status' => 'check',
                'is_final' => false,
            ],
        ]);
    }
}
