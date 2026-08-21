<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Behpardakht\Behpardakht;
use Shetabit\Multipay\Drivers\Parsian\Parsian;
use Shetabit\Multipay\Drivers\Saman\Saman;
use Shetabit\Multipay\Drivers\Yekpay\Yekpay;
use Shetabit\Multipay\Drivers\Zarinpal\Strategies\Zaringate;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\TestCase;
use ReflectionClass;
use Shetabit\Multipay\Request;

/**
 * These drivers talk SOAP to their gateway and build the soap client inside the
 * method that uses it (`new SoapClient($wsdl)`). Their purchase and their
 * successful verification can therefore not be covered without a reachable
 * gateway - and without `ext-soap`, which the package does not require.
 *
 * What is covered here is everything those drivers do without the soap client:
 * the redirection form and the checks that reject a callback before the gateway
 * is asked.
 */
class SoapDriversTest extends TestCase
{
    public function testTheDriversThatNeedSoapAreKnown(): void
    {
        $drivers = [Behpardakht::class, Parsian::class, Saman::class, Yekpay::class, Zaringate::class];

        foreach ($drivers as $driver) {
            $this->assertStringContainsString(
                'SoapClient',
                (string) file_get_contents(new ReflectionClass($driver)->getFileName()),
                $driver.' is expected to use SoapClient'
            );
        }
    }

    public function testBehpardakhtPostsTheReferenceIdToTheGateway(): void
    {
        $settings = $this->settingsOf('behpardakht');
        $driver = new Behpardakht((new Invoice)->transactionId('reference-1'), $settings);

        $form = $driver->pay();

        $this->assertSame($settings['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['RefId' => 'reference-1'], $form->getInputs());
    }

    public function testBehpardakhtSendsTheMobileNumberOfTheInvoice(): void
    {
        $driver = new Behpardakht((new Invoice)->transactionId('reference-1'), $this->settingsOf('behpardakht'));

        $form = $driver->detail('mobile', '09120000000')->pay();

        $this->assertSame(
            ['RefId' => 'reference-1', 'MobileNo' => '09120000000'],
            $form->getInputs()
        );
    }

    public function testBehpardakhtRefusesACallbackWithAnErrorCode(): void
    {
        $this->fakeRequest(['ResCode' => '17']);

        $driver = new Behpardakht(new Invoice, $this->settingsOf('behpardakht'));

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }

    public function testParsianRedirectsToTheGatewayWithTheToken(): void
    {
        $settings = $this->settingsOf('parsian');
        $driver = new Parsian((new Invoice)->transactionId('token-1'), $settings);

        $form = $driver->pay();

        $this->assertSame($settings['apiPaymentUrl'].'?Token=token-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['Token' => 'token-1'], $form->getInputs());
    }

    public function testParsianRefusesACallbackWithoutAToken(): void
    {
        $this->fakeRequest(['status' => '-1']);

        $driver = new Parsian(new Invoice, $this->settingsOf('parsian'));

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش توسط کاربر کنسل شده است.');

        $driver->verify();
    }

    public function testSamanPostsTheTokenAndTheCallbackUrlToTheGateway(): void
    {
        $settings = $this->settingsOf('saman');
        $driver = new Saman((new Invoice)->transactionId('token-1'), $settings);

        $form = $driver->pay();

        $this->assertSame($settings['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(
            ['Token' => 'token-1', 'RedirectUrl' => $settings['callbackUrl']],
            $form->getInputs()
        );
    }

    public function testYekpayRedirectsToTheGatewayWithTheAuthority(): void
    {
        $settings = $this->settingsOf('yekpay');
        $driver = new Yekpay((new Invoice)->transactionId('authority-1'), $settings);

        $form = $driver->pay();

        $this->assertSame($settings['apiPaymentUrl'].'authority-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame([], $form->getInputs());
    }

    private function settingsOf(string $driver) : array
    {
        return $this->config()['drivers'][$driver];
    }

    private function fakeRequest(array $input) : void
    {
        $reader = fn ($name) => $input[$name] ?? null;

        Request::overwrite('input', $reader);
        Request::overwrite('get', $reader);
        Request::overwrite('post', $reader);
    }
}
