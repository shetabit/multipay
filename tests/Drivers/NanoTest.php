<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Nano\Nano;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class NanoTest extends DriverTestCase
{
    /**
     * The vector of xno-binding v1, option C: order-0001, paid with 0.05 XNO to the burn account.
     */
    private const PAY_TO = 'nano_1111111111111111111111111111111111111111111111111111hifc8npp';
    private const AMOUNT_RAW = '50000000000000000000000000000';
    private const ORDER = 'order-0001';
    private const REPRESENTATIVE = 'nano_33q9dassq66es6mcay83n5kypqgpzjikr9bkc9ope9xch1borfphgehj4ozj';
    private const PAYER = 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3';
    private const HASH = '991CF190094C00F0B68E2E5F75F6BEE95A2E0BD93CEAA4A6734DB9F19B728918';

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->makeTemporaryDirectory('nano');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    protected function driverName() : string
    {
        return 'nano';
    }

    protected function driverClass() : string
    {
        return Nano::class;
    }

    public function testTheDriverIsOffUntilAnAccountIsConfigured(): void
    {
        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('no valid payTo account');

        $this->driver()->amount('0.05')->purchase();
    }

    public function testPurchaseRefusesAnAccountWithAWrongChecksum(): void
    {
        $this->expectException(PurchaseFailedException::class);

        $this->driver(['payTo' => substr(self::PAY_TO, 0, -1).'q'])->amount('0.05')->purchase();
    }

    public function testPurchaseBindsTheInvoiceToItsRepresentative(): void
    {
        $driver = $this->configuredDriver();
        $this->fakeHttp($driver, []);

        $this->assertSame(self::ORDER, $driver->detail('orderId', self::ORDER)->amount('0.05')->purchase());
        $this->assertSame(self::ORDER, $driver->getInvoice()->getTransactionId());
        $this->assertSame(self::AMOUNT_RAW, $driver->getInvoice()->getDetail('nanoAmountRaw'));
        $this->assertSame(self::REPRESENTATIVE, $driver->getInvoice()->getDetail('nanoRepresentative'));
        $this->assertSame(0, $this->requestCount(), 'A purchase does not talk to the node.');
    }

    public function testPurchaseTakesTheUuidOfTheInvoiceAsTheOrderByDefault(): void
    {
        $invoice = new Invoice();

        $this->assertSame((string) $invoice->getUuid(), $this->configuredDriver([], $invoice)->amount(1)->purchase());
    }

    public function testPurchaseRefusesAnAmountOfZeroOrBelowOneRaw(): void
    {
        $this->expectException(PurchaseFailedException::class);

        $this->configuredDriver()->amount('0.'.str_repeat('0', 30).'1')->purchase();
    }

    public function testAFloatAmountIsDerivedWithoutTheNoiseOfFloatArithmetic(): void
    {
        $driver = $this->configuredDriver();
        $driver->amount(0.03 + 0.02)->purchase();

        $this->assertSame(self::AMOUNT_RAW, $driver->getInvoice()->getDetail('nanoAmountRaw'));
    }

    public function testThePaymentTermsAreAnExactRequirementOnNanoMainnet(): void
    {
        $driver = $this->configuredDriver();
        $driver->detail('orderId', self::ORDER)->amount('0.05')->purchase();

        $this->assertSame(
            [
                'scheme' => 'exact',
                'network' => 'nano:mainnet',
                'asset' => 'XNO',
                'amount' => self::AMOUNT_RAW,
                'payTo' => self::PAY_TO,
                'extra' => [
                    'binding' => 'xno-binding/v1/rep',
                    'orderId' => self::ORDER,
                    'representative' => self::REPRESENTATIVE,
                ],
            ],
            $driver->paymentTerms()
        );
    }

    public function testPayRedirectsToThePaymentPageWithTheTerms(): void
    {
        $driver = $this->configuredDriver(['paymentPageUrl' => 'https://example.com/pay']);
        $driver->detail('orderId', self::ORDER)->amount('0.05')->purchase();

        $form = $driver->pay();

        $this->assertSame('https://example.com/pay', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(self::AMOUNT_RAW, $form->getInputs()['amount']);
        $this->assertSame(self::REPRESENTATIVE, $form->getInputs()['representative']);
        $this->assertSame(self::PAY_TO, $form->getInputs()['account']);
    }

    public function testPayFailsBeforeTheInvoiceIsPurchased(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $this->configuredDriver()->pay();
    }

    public function testVerifyReturnsAReceiptForABoundExactSend(): void
    {
        $driver = $this->verifyingDriver();
        $this->fakeHttp($driver, [$this->jsonResponse($this->block())]);
        $this->fakeRequest(['hash' => strtolower(self::HASH)]);

        $receipt = $driver->verify();

        $this->assertSame('nano', $receipt->getDriver());
        $this->assertSame(self::HASH, $receipt->getReferenceId());
        $this->assertSame(self::AMOUNT_RAW, $receipt->getDetail('amountRaw'));
        $this->assertSame(self::PAYER, $receipt->getDetail('payer'));
        $this->assertRequestedUrl('https://node.example.com/rpc');
        $this->assertSame(
            ['action' => 'block_info', 'json_block' => 'true', 'hash' => self::HASH],
            $this->requestJson()
        );
    }

    public function testVerifyRefusesASendOfAnotherAmount(): void
    {
        $driver = $this->verifyingDriver();
        $this->fakeHttp($driver, [$this->jsonResponse($this->block(['amount' => '49999999999999999999999999999']))]);
        $this->fakeRequest(['hash' => self::HASH]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('amount');

        $driver->verify();
    }

    public function testVerifyRefusesASendMadeForAnotherInvoice(): void
    {
        $driver = $this->verifyingDriver([], 'order-0002');
        $this->fakeHttp($driver, [$this->jsonResponse($this->block())]);
        $this->fakeRequest(['hash' => self::HASH]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('representative');

        $driver->verify();
    }

    public function testVerifyRefusesAnUnconfirmedSend(): void
    {
        $driver = $this->verifyingDriver();
        $this->fakeHttp($driver, [$this->jsonResponse($this->block(['confirmed' => 'false']))]);
        $this->fakeRequest(['hash' => self::HASH]);

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }

    public function testVerifyFailsWithTheErrorOfTheNode(): void
    {
        $driver = $this->verifyingDriver();
        $this->fakeHttp($driver, [$this->jsonResponse(['error' => 'Block not found'])]);
        $this->fakeRequest(['hash' => self::HASH]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Block not found');

        $driver->verify();
    }

    public function testVerifyRefusesTheSameBlockTwiceAlsoAfterARestart(): void
    {
        $first = $this->verifyingDriver();
        $this->fakeHttp($first, [$this->jsonResponse($this->block())]);
        $this->fakeRequest(['hash' => self::HASH]);
        $first->verify();

        // A new driver reads the claims file again, as a new process would.
        $second = $this->verifyingDriver();
        $this->fakeHttp($second, [$this->jsonResponse($this->block())]);

        $this->expectException(PreviouslyVerifiedException::class);

        $second->verify();
    }

    public function testVerifyNeedsABlockHash(): void
    {
        $driver = $this->verifyingDriver();
        $this->fakeHttp($driver, []);
        $this->fakeRequest(['hash' => 'not-a-hash']);

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }

    public function testVerifyNeedsAClaimsFile(): void
    {
        $driver = $this->verifyingDriver(['claimsFile' => '']);
        $this->fakeHttp($driver, []);
        $this->fakeRequest(['hash' => self::HASH]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('claims file');

        $driver->verify();
    }

    private function configuredDriver(array $settings = [], Invoice|null $invoice = null): Nano
    {
        return $this->driver(array_merge([
            'payTo' => self::PAY_TO,
            'nodeUrl' => 'https://node.example.com/rpc',
            'claimsFile' => $this->directory.'claims.log',
        ], $settings), $invoice);
    }

    private function verifyingDriver(array $settings = [], string $order = self::ORDER): Nano
    {
        $invoice = (new Invoice())->amount('0.05')->transactionId($order);

        return $this->configuredDriver($settings, $invoice);
    }

    /**
     * A block_info answer of a node for the send of the vector.
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'block_account' => self::PAYER,
            'amount' => self::AMOUNT_RAW,
            'confirmed' => 'true',
            'subtype' => 'send',
            'local_timestamp' => (string) time(),
            'contents' => [
                'type' => 'state',
                'link_as_account' => self::PAY_TO,
                'representative' => self::REPRESENTATIVE,
            ],
        ], $overrides);
    }
}
