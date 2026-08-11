<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Drivers\Zarinpal\Strategies\Normal;
use Shetabit\Multipay\Drivers\Zarinpal\Strategies\Sandbox;
use Shetabit\Multipay\Drivers\Zarinpal\Strategies\Zaringate;
use Shetabit\Multipay\Drivers\Zarinpal\Zarinpal;
use Shetabit\Multipay\Exceptions\DriverNotFoundException;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use ReflectionProperty;

class ZarinpalTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'zarinpal';
    }

    protected function driverClass() : string
    {
        return Zarinpal::class;
    }

    /**
     * The driver hands its work over to the strategy of the configured mode, so the
     * responses have to be given to that strategy.
     */
    protected function fakeHttp(Driver $driver, array $responses, array $clientOptions = []) : void
    {
        if ($driver instanceof Zarinpal) {
            $driver = new ReflectionProperty($driver, 'strategy')->getValue($driver);
        }

        parent::fakeHttp($driver, $responses, $clientOptions);
    }

    public function testItUsesTheStrategyOfTheConfiguredMode(): void
    {
        $strategies = ['normal' => Normal::class, 'sandbox' => Sandbox::class, 'zaringate' => Zaringate::class];

        foreach ($strategies as $mode => $strategy) {
            $driver = $this->driver(['mode' => $mode]);

            $this->assertInstanceOf(
                $strategy,
                new ReflectionProperty($driver, 'strategy')->getValue($driver),
                "Mode [$mode] should use ".$strategy
            );
        }
    }

    public function testItRefusesAnUnknownMode(): void
    {
        $this->expectException(DriverNotFoundException::class);
        $this->expectExceptionMessage('Zarinpal payment mode not found (check your settings)');

        $this->driver(['mode' => 'unknown']);
    }

    public function testPurchaseReturnsTheAuthorityOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'zarinpal-merchant']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 100, 'authority' => 'authority-1'], 'errors' => []]),
        ]);

        $this->assertSame('authority-1', $driver->amount(1000)->purchase());
        $this->assertSame('authority-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);

        $body = $this->requestJson();

        $this->assertSame('zarinpal-merchant', $body['merchant_id']);
        $this->assertSame('IRR', $body['currency']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callback_url']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 100, 'authority' => 'authority-1'], 'errors' => []]),
        ]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['amount']);
    }

    public function testPurchaseSendsTheContactDetailsAsMetadata(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 100, 'authority' => 'authority-1'], 'errors' => []]),
        ]);

        $driver->detail(['mobile' => '09120000000', 'email' => 'john@example.com'])->amount(1000)->purchase();

        $this->assertSame(
            ['mobile' => '09120000000', 'email' => 'john@example.com'],
            $this->requestJson()['metadata']
        );
    }

    public function testPurchaseFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['errors' => ['code' => -9], 'data' => []])]);

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseUsesTheSandboxInSandboxMode(): void
    {
        $driver = $this->driver(['mode' => 'sandbox']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 100, 'authority' => 'authority-1'], 'errors' => []]),
        ]);

        $driver->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['sandboxApiPurchaseUrl']);
    }

    public function testPayRedirectsToTheGatewayWithTheAuthority(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'authority-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheSandboxInSandboxMode(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver(['mode' => 'sandbox'], $invoice)->pay();

        $this->assertSame($this->settings()['sandboxApiPaymentUrl'].'authority-1', $form->getAction());
    }

    public function testPayPutsTheAuthorityIntoTheZaringateUrl(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver(['mode' => 'zaringate'], $invoice)->pay();

        $this->assertSame(
            str_replace(':authority', 'authority-1', $this->settings()['zaringateApiPaymentUrl']),
            $form->getAction()
        );
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver(['merchantId' => 'zarinpal-merchant', 'currency' => 'T'], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'data' => [
                    'code' => 100,
                    'ref_id' => 'reference-1',
                    'message' => 'Paid',
                    'card_pan' => '6037****1234',
                    'card_hash' => 'the-hash',
                    'fee_type' => 'Merchant',
                    'fee' => 0,
                ],
                'errors' => [],
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('zarinpal', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame(100, $receipt->getDetail('code'));
        $this->assertSame('6037****1234', $receipt->getDetail('card_pan'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('authority-1', $body['authority']);
        $this->assertSame(10000, $body['amount']);
    }

    public function testVerifyAcceptsAnAlreadyVerifiedTransaction(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 101, 'ref_id' => 'reference-1'], 'errors' => []]),
        ]);

        $this->assertSame('reference-1', $driver->amount(1000)->verify()->getReferenceId());
    }

    public function testVerifyFallsBackToTheAuthorityOfTheCallback(): void
    {
        $this->fakeRequest(['Authority' => 'authority-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['data' => ['code' => 100, 'ref_id' => 'reference-1'], 'errors' => []]),
        ]);

        $driver->amount(1000)->verify();

        $this->assertSame('authority-from-callback', $this->requestJson()['authority']);
    }

    public function testVerifyFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => [], 'errors' => ['code' => -51]])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->amount(1000)->verify();
    }
}
