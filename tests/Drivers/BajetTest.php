<?php

namespace Shetabit\Multipay\Tests\Drivers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Shetabit\Multipay\Constants\IranCurrency;
use Shetabit\Multipay\Drivers\Bajet\Bajet;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class BajetTest extends DriverTestCase
{
    protected function driverName(): string
    {
        return 'bajet';
    }

    protected function driverClass(): string
    {
        return Bajet::class;
    }

    private function bajet(array $settings = [], ?Invoice $invoice = null): Bajet
    {
        return new Bajet($invoice ?? $this->invoice(), $this->settings(array_replace([
            'apiUrl' => 'https://bajet.example',
            'username' => 'test-merchant',
            'password' => 'test-password',
            'terminalId' => 'test-terminal',
            'callbackUrl' => 'https://merchant.example/callback',
        ], $settings)));
    }

    private function invoice(): Invoice
    {
        return (new Invoice)->uuid('order-1')->amount(1000)->detail('mobile', '09120000000');
    }

    private function success(array $result): \GuzzleHttp\Psr7\Response
    {
        return $this->jsonResponse(['status' => 200, 'success' => true, 'result' => $result]);
    }

    private function auth(): \GuzzleHttp\Psr7\Response
    {
        return $this->success(['token' => 'test-token', 'expiresIn' => 3600]);
    }

    private function order(array $overrides = []): \GuzzleHttp\Psr7\Response
    {
        return $this->success(array_replace([
            'referenceId' => 'ref-1',
            'referUrl' => 'https://bajet.example/fa/login?id=ref-1&lang=fa',
        ], $overrides));
    }

    private function inquiryResponse(array $overrides = []): \GuzzleHttp\Psr7\Response
    {
        return $this->success(array_replace([
            'referenceId' => 'ref-1', 'orderId' => 'order-1', 'amount' => 10000,
            'finalStatus' => 'SUCCESS', 'creditAmount' => 8000, 'cashAmount' => 2000,
        ], $overrides));
    }

    private function verified(array $overrides = []): \GuzzleHttp\Psr7\Response
    {
        return $this->success(array_replace([
            'referenceId' => 'ref-1', 'orderId' => 'order-1', 'creditAmount' => 8000, 'cashAmount' => 2000,
        ], $overrides));
    }

    public function testPurchaseAuthenticatesAndSendsTheDocumentedOrder(): void
    {
        $driver = $this->bajet();
        $this->fakeHttp($driver, [$this->auth(), $this->order()]);
        $this->assertSame('ref-1', $driver->purchase());
        $this->assertSame('ref-1', $driver->getInvoice()->getTransactionId());
        $this->assertSame('order-1', $driver->getInvoice()->getDetail('orderId'));
        $this->assertRequestedUrl('https://bajet.example/api/v1/jetpay/token');
        $this->assertSame([
            'username' => 'test-merchant', 'password' => 'test-password', 'terminalId' => 'test-terminal',
        ], $this->requestJson());
        $this->assertFalse($this->request()->hasHeader('Authorization'));
        $this->assertRequestedUrl('https://bajet.example/api/v1/jetpay/order', 1);
        $this->assertSame('POST', $this->request(1)->getMethod());
        $this->assertSame('application/json', $this->request(1)->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer test-token', $this->request(1)->getHeaderLine('Authorization'));
        $this->assertSame([
            'orderId' => 'order-1', 'amount' => 10000, 'mobile' => '09120000000',
            'returnUrl' => 'https://merchant.example/callback',
        ], $this->requestJson(1));
        $options = $this->httpHistory[1]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(30, $options['timeout']);
    }

    public function testOptionalFieldsAndExplicitOrderAreSentWithoutExtraApplicationData(): void
    {
        $driver = $this->bajet(['callbackUrl' => '']);
        $driver->detail([
            'orderId' => 'custom-order', 'nationalId' => '0012345678',
            'basketItems' => [['brand' => 'test', 'productType' => 2, 'count' => 1, 'private' => 'omit']],
        ]);
        $this->fakeHttp($driver, [$this->auth(), $this->order()]);
        $driver->purchase();
        $body = $this->requestJson(1);
        $this->assertSame('custom-order', $body['orderId']);
        $this->assertSame('0012345678', $body['nationalId']);
        $this->assertSame([['brand' => 'test', 'productType' => 2, 'count' => 1]], $body['basketItems']);
        $this->assertArrayNotHasKey('returnUrl', $body);
    }

    #[DataProvider('currencies')]
    public function testCurrencyConversionIsExplicit(mixed $source, string $target, int $amount, int $expected): void
    {
        $driver = $this->bajet(['currency' => $source, 'apiCurrency' => $target]);
        $this->fakeHttp($driver, [$this->auth(), $this->order()]);
        $driver->amount($amount)->purchase();
        $this->assertSame($expected, $this->requestJson(1)['amount']);
    }

    public static function currencies(): array
    {
        return [
            [IranCurrency::TOMAN, 'R', 1000, 10000],
            [IranCurrency::RIAL, 'R', 1000, 1000],
            [IranCurrency::RIAL, 'T', 1000, 100],
            [IranCurrency::TOMAN, 'T', 1000, 1000],
            ['T', 'R', 1000, 10000],
            ['R', 'T', 1000, 100],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsFailBeforeAnyRequest(mixed $amount, array $settings): void
    {
        $driver = $this->bajet($settings);
        $this->fakeHttp($driver, []);
        $driver->amount($amount);
        try {
            $driver->purchase();
            $this->fail('Invalid amount accepted.');
        } catch (PurchaseFailedException $exception) {
            $this->assertSame(0, $this->requestCount());
        }
    }

    public static function invalidAmounts(): array
    {
        return [
            [0, []], [-1, []], [1.5, []], [PHP_INT_MAX, []],
            [11, ['currency' => 'R', 'apiCurrency' => 'T']],
            [1000, ['apiCurrency' => null]], [1000, ['currency' => 'USD']],
        ];
    }

    #[DataProvider('invalidPurchaseInputs')]
    public function testMissingCredentialsOrMalformedDetailsFailBeforeNetwork(array $settings, array $details): void
    {
        $driver = $this->bajet($settings)->detail($details);
        $this->fakeHttp($driver, []);
        try {
            $driver->purchase();
            $this->fail('Invalid purchase accepted.');
        } catch (PurchaseFailedException $exception) {
            $this->assertSame(0, $this->requestCount());
        }
    }

    public static function invalidPurchaseInputs(): array
    {
        return [
            [['username' => ''], []], [['password' => ''], []], [['terminalId' => ''], []],
            [['apiUrl' => 'http://bajet.example'], []],
            [['apiUrl' => 'https://user:pass@bajet.example'], []],
            [['apiUrl' => 'https://bajet.example?x=1'], []],
            [[], ['mobile' => null]], [[], ['mobile' => ['invalid']]],
            [[], ['nationalId' => []]], [[], ['basketItems' => 'invalid']],
            [[], ['basketItems' => ['brand' => 'not a list']]],
            [[], ['basketItems' => ['not an object']]],
            [[], ['basketItems' => [['brand' => 'test', 'count' => 0, 'productType' => 2]]]],
            [[], ['basketItems' => [['brand' => 'test', 'count' => 1, 'productType' => null]]]],
        ];
    }

    public function testRedirectionPreservesQueryAndWorksWithARestoredInvoice(): void
    {
        $driver = $this->bajet();
        $this->fakeHttp($driver, [$this->auth(), $this->order()]);
        $driver->purchase();
        $restored = (new Invoice)->transactionId('ref-1')->detail($driver->getInvoice()->getDetails());
        $form = $this->bajet([], $restored)->pay();
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame('https://bajet.example/fa/login?id=ref-1&lang=fa', $form->getAction());
        $this->assertSame(['id' => 'ref-1', 'lang' => 'fa'], $form->getInputs());
        $this->assertStringContainsString('name="id" value="ref-1"', $form->render());
        $this->assertSame(2, $this->requestCount());
    }

    public function testPayWithoutPersistedRedirectFails(): void
    {
        $this->expectException(PurchaseFailedException::class);
        $this->bajet([], $this->invoice()->transactionId('ref-1'))->pay();
    }

    public function testPayRejectsNestedQueryParameters(): void
    {
        $invoice = $this->invoice()->transactionId('ref-1')
            ->detail('bajetReferUrl', 'https://bajet.example?id[]=ref-1');
        $this->expectException(PurchaseFailedException::class);
        $this->bajet([], $invoice)->pay();
    }

    #[DataProvider('invalidOrders')]
    public function testMalformedOrderResponseDoesNotSetTransactionId(array $result): void
    {
        $driver = $this->bajet();
        $this->fakeHttp($driver, [$this->auth(), $this->order($result)]);
        try {
            $driver->purchase();
            $this->fail('Malformed order accepted.');
        } catch (PurchaseFailedException $exception) {
            $this->assertNull($driver->getInvoice()->getTransactionId());
        }
    }

    public static function invalidOrders(): array
    {
        return [
            [['referenceId' => null]], [['referenceId' => '']], [['referUrl' => null]],
            [['referUrl' => 'javascript:alert(1)']], [['referUrl' => 'http://bajet.example']],
        ];
    }

    public function testVerifyChecksInquiryBeforeSettlingAndReturnsSplitAmounts(): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1')->detail('orderId', 'order-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(), $this->verified()]);
        $receipt = $driver->verify();
        $this->assertSame('bajet', $receipt->getDriver());
        $this->assertSame('ref-1', $receipt->getReferenceId());
        $this->assertSame('order-1', $receipt->getDetail('orderId'));
        $this->assertSame(8000, $receipt->getDetail('creditAmount'));
        $this->assertSame(2000, $receipt->getDetail('cashAmount'));
        $this->assertSame(IranCurrency::RIAL, $receipt->getDetail('apiCurrency'));
        $this->assertSame(3, $this->requestCount());
        foreach ([1 => 'inquiry', 2 => 'verify'] as $index => $operation) {
            $this->assertRequestedUrl('https://bajet.example/api/v1/jetpay/'.$operation, $index);
            $this->assertSame(['referenceId' => 'ref-1'], $this->requestJson($index));
            $this->assertSame('Bearer test-token', $this->request($index)->getHeaderLine('Authorization'));
        }
    }

    public function testCallbackCannotSubstituteTheStoredReferenceOrAmount(): void
    {
        $this->fakeRequest(['id' => 'attacker-ref', 'orderId' => 'attacker-order', 'status' => 'true', 'amount' => 1]);
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(), $this->verified()]);
        $this->assertSame('ref-1', $driver->verify()->getReferenceId());
        $this->assertSame(['referenceId' => 'ref-1'], $this->requestJson(1));
    }

    public function testCallbackAloneCannotVerifyAPayment(): void
    {
        $this->fakeRequest(['id' => 'ref-1', 'status' => 'true']);
        $driver = $this->bajet();
        $this->fakeHttp($driver, []);
        $this->expectException(InvalidPaymentException::class);
        $driver->verify();
    }

    #[DataProvider('invalidInquiries')]
    public function testVerifyRejectsUnpaidOrMismatchedTransactionsBeforeSettlement(array $result): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1')->detail('orderId', 'order-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse($result)]);
        try {
            $driver->verify();
            $this->fail('Invalid inquiry accepted.');
        } catch (InvalidPaymentException $exception) {
            $this->assertSame(2, $this->requestCount());
        }
    }

    public static function invalidInquiries(): array
    {
        return [
            [['finalStatus' => 'PEND']], [['finalStatus' => 'FAILED']], [['finalStatus' => 'REVERSE']],
            [['finalStatus' => 'REFUND']], [['finalStatus' => 'INIT']], [['finalStatus' => null]],
            [['referenceId' => 'other']], [['orderId' => 'other']], [['orderId' => null]],
            [['amount' => 1]], [['amount' => null]], [['amount' => true]],
        ];
    }

    public function testPreviouslyVerifiedIsReportedWithoutRepeatingSettlement(): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(['finalStatus' => 'VERIFIED'])]);
        $this->expectException(PreviouslyVerifiedException::class);
        $driver->verify();
    }

    #[DataProvider('invalidVerifications')]
    public function testMalformedOrMismatchedSettlementCannotProduceAReceipt(array $result): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(), $this->verified($result)]);
        $this->expectException(InvalidPaymentException::class);
        $driver->verify();
    }

    public static function invalidVerifications(): array
    {
        return [
            [['referenceId' => 'other']], [['orderId' => 'other']], [['creditAmount' => -1]],
            [['creditAmount' => 11000]], [['creditAmount' => null]], [['cashAmount' => null]],
            [['cashAmount' => 1999]], [['cashAmount' => 1.5]], [['cashAmount' => []]],
        ];
    }

    public function testInquiryDoesNotSettleTheTransaction(): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(['finalStatus' => 'PEND'])]);
        $this->assertSame('PEND', $driver->inquiry()['finalStatus']);
        $this->assertSame(2, $this->requestCount());
    }

    public function testInquiryRejectsWrongReferences(): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(['referenceId' => 'other'])]);
        $this->expectException(InvalidPaymentException::class);
        $driver->inquiry();
    }

    #[DataProvider('badResponses')]
    public function testAuthenticationFailuresAreNormalizedWithoutLeakingSecrets(string $body, int $status): void
    {
        $driver = $this->bajet();
        $this->fakeHttp($driver, [$this->response($body, $status)]);
        try {
            $driver->purchase();
            $this->fail('Authentication failure accepted.');
        } catch (PurchaseFailedException $exception) {
            $this->assertStringNotContainsString('test-password', $exception->getMessage());
            $this->assertSame(1, $this->requestCount());
        }
    }

    public static function badResponses(): array
    {
        return [
            ['<html>down</html>', 502], ['null', 200], ['[]', 200],
            ['{"status":200,"success":"true","result":{"token":"token"}}', 200],
            ['{"status":200,"success":true,"result":{"token":"token"}}', 302],
            ['{"status":400,"success":true,"result":{"token":"token"}}', 200],
            ['{"status":200,"success":true,"result":null}', 200],
            ['{"status":200,"success":true,"result":[]}', 200],
            ['{"status":401,"success":false,"result":{"error":{"en":"test-password","code":1100006}}}', 401],
            ['{"status":400,"success":false,"result":{"error":{"code":"invalid"}}}', 400],
        ];
    }

    public function testOrderFailurePreservesGatewayErrorCode(): void
    {
        $driver = $this->bajet();
        $this->fakeHttp($driver, [$this->auth(), $this->jsonResponse([
            'status' => 400, 'success' => false, 'result' => ['error' => ['code' => 700001]],
        ], 400)]);
        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionCode(700001);
        $driver->purchase();
    }

    public function testVerificationErrorsUseInvalidPaymentException(): void
    {
        $driver = $this->bajet([], $this->invoice()->transactionId('ref-1'));
        $this->fakeHttp($driver, [$this->auth(), $this->inquiryResponse(), $this->jsonResponse([
            'status' => 400, 'success' => false, 'result' => ['error' => ['code' => 700007]],
        ], 400)]);
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionCode(700007);
        $driver->verify();
    }

    public function testNetworkFailureIsNotRetriedOrExposed(): void
    {
        $driver = $this->bajet();
        $error = new ConnectException('secret test-password test-token', new Request('POST', 'https://bajet.example'));
        $this->fakeHttp($driver, [$this->auth(), $error]);
        try {
            $driver->purchase();
            $this->fail('Network failure accepted.');
        } catch (PurchaseFailedException $exception) {
            $this->assertSame(2, $this->requestCount());
            $this->assertStringNotContainsString('test-password', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
