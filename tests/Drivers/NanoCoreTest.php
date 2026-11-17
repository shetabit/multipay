<?php

namespace Shetabit\Multipay\Tests\Drivers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shetabit\Multipay\Drivers\Nano\Core\Address;
use Shetabit\Multipay\Drivers\Nano\Core\Amount;
use Shetabit\Multipay\Drivers\Nano\Core\Binding;
use Shetabit\Multipay\Drivers\Nano\Core\Blake2b;
use Shetabit\Multipay\Drivers\Nano\Core\ClaimsFile;
use Shetabit\Multipay\Drivers\Nano\Core\Verifier;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;

/**
 * The parts of the Nano driver that need neither a node nor an HTTP client: the address codec, the amounts in raw,
 * the binding of a block to an invoice, the check of a block and the claims file.
 */
class NanoCoreTest extends TestCase
{
    /**
     * The vector of xno-binding v1, option C: order-0001, paid with 0.05 XNO to the burn account.
     */
    private const BURN = 'nano_1111111111111111111111111111111111111111111111111111hifc8npp';
    private const PRICE_RAW = '50000000000000000000000000000';
    private const ORDER = 'order-0001';
    private const REPRESENTATIVE = 'nano_33q9dassq66es6mcay83n5kypqgpzjikr9bkc9ope9xch1borfphgehj4ozj';
    private const PAYER = 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3';

    private string $claimsPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimsPath = sys_get_temp_dir().'/multipay-nano-claims-'.bin2hex(random_bytes(6)).'.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->claimsPath)) {
            unlink($this->claimsPath);
        }

        parent::tearDown();
    }

    public function testBlake2bMatchesTheVectorOfRfc7693(): void
    {
        $this->assertSame(
            'ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d1'
            .'7d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923',
            bin2hex(Blake2b::hash('abc', 64))
        );
    }

    public function testAnAddressRoundTripsThroughItsPublicKey(): void
    {
        $this->assertTrue(Address::isValid(self::BURN));
        $this->assertSame(str_repeat('0', 64), Address::publicKey(self::BURN));
        $this->assertSame(self::BURN, Address::fromPublicKey(str_repeat('0', 64)));
        $this->assertTrue(Address::isValid(self::PAYER));
    }

    public function testAnAddressWithAWrongChecksumIsRefused(): void
    {
        $this->assertFalse(Address::isValid('nano_1111111111111111111111111111111111111111111111111111hifc8npq'));
        $this->assertFalse(Address::isValid('nano_111'));
        $this->assertFalse(Address::isValid(''));
    }

    public function testThePublicKeyOfAMalformedAddressThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Address::publicKey('nano_111');
    }

    public function testThePublicKeyOfAnAddressWithAWrongChecksumThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Address::publicKey('nano_1111111111111111111111111111111111111111111111111111hifc8npq');
    }

    public function testAnInvalidAddressMatchesNothing(): void
    {
        $this->assertFalse(Address::matches('nano_111', self::BURN));
        $this->assertFalse(
            Address::matches(self::BURN, 'nano_1111111111111111111111111111111111111111111111111111hifc8npq')
        );
        $this->assertFalse(Address::matches('nano_111', 'nano_111'));
    }

    public function testTheXrbAndNanoFormsOfOneAccountMatch(): void
    {
        $this->assertTrue(
            Address::matches(self::BURN, 'xrb_1111111111111111111111111111111111111111111111111111hifc8npp')
        );
        $this->assertFalse(Address::matches(self::BURN, self::PAYER));
        $this->assertFalse(Address::matches(null, self::BURN));
    }

    public function testRawIsDerivedFromXno(): void
    {
        $this->assertSame(self::PRICE_RAW, Amount::xnoToRaw('0.05'));
        $this->assertSame('1'.str_repeat('0', 30), Amount::xnoToRaw('1'));
        $this->assertSame('1', Amount::xnoToRaw('0.'.str_repeat('0', 29).'1'));
        $this->assertSame('0', Amount::xnoToRaw('0'));
    }

    public function testAnAmountFinerThanOneRawIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::xnoToRaw('0.'.str_repeat('0', 30).'1');
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::xnoToRaw('-1');
    }

    public function testRawAmountsCompareAsIntegers(): void
    {
        $this->assertTrue(Amount::equals('050', '50'));
        $this->assertFalse(Amount::equals('49999999999999999999999999999', self::PRICE_RAW));
        $this->assertFalse(Amount::isRaw('1.5'));
        $this->assertFalse(Amount::isRaw(str_repeat('9', 40)));
    }

    public function testTheBindingReproducesThePublishedVector(): void
    {
        $this->assertSame(
            '86e75a339b908cc926a478c1a0e5eb5dd6fc612c1d3251eb661faa78135c36cf',
            Binding::commitmentHex(self::ORDER, self::BURN, self::PRICE_RAW)
        );
        $this->assertSame(
            self::REPRESENTATIVE,
            Binding::representativeAccount(self::ORDER, self::BURN, self::PRICE_RAW)
        );
    }

    public function testAnotherOrderOrAmountGivesAnotherRepresentative(): void
    {
        $this->assertNotSame(
            self::REPRESENTATIVE,
            Binding::representativeAccount('order-0002', self::BURN, self::PRICE_RAW)
        );
        $this->assertNotSame(
            self::REPRESENTATIVE,
            Binding::representativeAccount(self::ORDER, self::BURN, '50000000000000000000000000001')
        );
    }

    public function testTheVerifierAcceptsAConfirmedBoundExactSend(): void
    {
        $result = $this->verify($this->block());

        $this->assertTrue($result['ok']);
        $this->assertSame(self::PRICE_RAW, $result['amount']);
        $this->assertSame(self::PAYER, $result['payer']);
    }

    public function testTheVerifierRefusesAnUnconfirmedSend(): void
    {
        $this->assertFalse($this->verify($this->block(['confirmed' => 'false']))['ok']);
    }

    public function testTheVerifierRefusesABlockThatIsNotASend(): void
    {
        $this->assertFalse($this->verify($this->block(['subtype' => 'receive']))['ok']);
    }

    public function testTheVerifierRefusesASendToAnotherAccount(): void
    {
        $this->assertFalse($this->verify($this->block(['contents' => ['link_as_account' => self::PAYER]]))['ok']);
    }

    public function testTheVerifierRefusesASendToAMalformedAccount(): void
    {
        $result = $this->verify($this->block(['contents' => ['link_as_account' => 'nano_111']]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('configured Nano account', $result['error']);
    }

    public function testTheVerifierRefusesAnAmountShortOrOver(): void
    {
        $this->assertFalse($this->verify($this->block(['amount' => '49999999999999999999999999999']))['ok']);
        $this->assertFalse($this->verify($this->block(['amount' => '50000000000000000000000000001']))['ok']);
    }

    public function testTheVerifierRefusesASendThatIsNotBoundToThisInvoice(): void
    {
        $result = $this->verify($this->block(['contents' => ['representative' => self::BURN]]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('representative', $result['error']);
    }

    public function testTheVerifierRefusesASendOlderThanTheWindow(): void
    {
        $result = Verifier::verify($this->block(), self::BURN, self::PRICE_RAW, self::REPRESENTATIVE, 3600, 1763299999);

        $this->assertFalse($result['ok']);
    }

    public function testAClaimIsRecordedOnceForItsInvoice(): void
    {
        $claims = new ClaimsFile($this->claimsPath);

        $this->assertTrue($claims->claim(str_repeat('a', 64), 'invoice-1'));
        $this->assertFalse($claims->claim(str_repeat('A', 64), 'invoice-1'));
    }

    public function testAClaimedBlockIsRefusedForAnotherInvoice(): void
    {
        $claims = new ClaimsFile($this->claimsPath);
        $claims->claim(str_repeat('B', 64), 'invoice-1');

        $this->expectException(InvalidPaymentException::class);

        $claims->claim(str_repeat('B', 64), 'invoice-2');
    }

    public function testAnInvoiceIsNotPaidTwiceByTwoBlocks(): void
    {
        $claims = new ClaimsFile($this->claimsPath);
        $claims->claim(str_repeat('C', 64), 'invoice-1');

        $this->expectException(InvalidPaymentException::class);

        $claims->claim(str_repeat('D', 64), 'invoice-1');
    }

    public function testAClaimSurvivesARestart(): void
    {
        (new ClaimsFile($this->claimsPath))->claim(str_repeat('E', 64), 'invoice-1');

        $reloaded = new ClaimsFile($this->claimsPath);

        $this->assertTrue($reloaded->isClaimed(str_repeat('E', 64)));
        $this->assertFalse($reloaded->claim(str_repeat('E', 64), 'invoice-1'));

        $this->expectException(InvalidPaymentException::class);

        $reloaded->claim(str_repeat('E', 64), 'invoice-2');
    }

    public function testAClaimMadeByAnotherProcessIsSeen(): void
    {
        $first = new ClaimsFile($this->claimsPath);
        $second = new ClaimsFile($this->claimsPath);

        $first->claim(str_repeat('F', 64), 'invoice-1');

        $this->expectException(InvalidPaymentException::class);

        $second->claim(str_repeat('F', 64), 'invoice-2');
    }

    public function testAClaimNeedsABlockHash(): void
    {
        $this->expectException(InvalidPaymentException::class);

        (new ClaimsFile($this->claimsPath))->claim('not-a-hash', 'invoice-1');
    }

    public function testAnOrderIdThatCouldForgeALineIsRefused(): void
    {
        $this->expectException(InvalidPaymentException::class);

        (new ClaimsFile($this->claimsPath))->claim(str_repeat('A', 64), "invoice-1\n".str_repeat('B', 64)."\tx");
    }

    public function testTheClaimsFileKeepsOneLinePerClaim(): void
    {
        $claims = new ClaimsFile($this->claimsPath);
        $claims->claim(str_repeat('a', 64), 'invoice-1');
        $claims->claim(str_repeat('b', 64), 'invoice-2');

        $this->assertSame(
            str_repeat('A', 64)."\tinvoice-1\n".str_repeat('B', 64)."\tinvoice-2\n",
            file_get_contents($this->claimsPath)
        );
    }

    /**
     * A block_info answer of a node for the send of the vector.
     */
    private function block(array $overrides = []): array
    {
        $block = [
            'block_account' => self::PAYER,
            'amount' => self::PRICE_RAW,
            'confirmed' => 'true',
            'subtype' => 'send',
            'local_timestamp' => '1763200000',
            'contents' => [
                'link_as_account' => self::BURN,
                'representative' => self::REPRESENTATIVE,
            ],
        ];

        $contents = array_merge($block['contents'], $overrides['contents'] ?? []);
        unset($overrides['contents']);

        return array_merge($block, $overrides, ['contents' => $contents]);
    }

    private function verify(array $block): array
    {
        return Verifier::verify($block, self::BURN, self::PRICE_RAW, self::REPRESENTATIVE, 3600, 1763200100);
    }
}
