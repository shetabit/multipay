<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Drivers\Stripe\Stripe;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class StripeTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'stripe';
    }

    protected function driverClass() : string
    {
        return Stripe::class;
    }

    /**
     * The driver talks to the stripe api with relative urls.
     */
    protected function fakeHttp(Driver $driver, array $responses, array $clientOptions = []) : void
    {
        parent::fakeHttp($driver, $responses, $clientOptions + ['base_uri' => 'https://api.stripe.com/v1/']);
    }

    public function testPurchaseReturnsTheCheckoutSessionOfStripe(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1']),
        ]);

        $this->assertSame('cs_test_1', $driver->amount(2000)->purchase());
        $this->assertSame('cs_test_1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl('https://api.stripe.com/v1/checkout/sessions');
    }

    public function testPurchaseSendsTheLineItemOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => 'eur']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1']),
        ]);

        $driver->amount(2000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('card', $form['payment_method_types'][0]);
        $this->assertSame('eur', $form['line_items'][0]['price_data']['currency']);
        $this->assertSame('2000', $form['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('payment', $form['mode']);
        $this->assertStringStartsWith($this->settings()['success_url'], $form['success_url']);
        $this->assertStringStartsWith($this->settings()['cancel_url'], $form['cancel_url']);
    }

    public function testPurchaseFailsWhenStripeReturnsNoCheckoutSession(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['error' => ['message' => 'the key is not valid']])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطا در دریافت اطلاعات پرداخت Stripe');

        $driver->amount(2000)->purchase();
    }

    public function testPayRedirectsToTheCheckoutUrlOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1']),
        ]);

        $driver->amount(2000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://checkout.stripe.com/pay/cs_test_1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame([], $form->getInputs());
    }

    public function testVerifyReturnsAReceiptWithThePaymentIntent(): void
    {
        $this->fakeRequest(['session_id' => 'cs_test_1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'cs_test_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1']),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('stripe', $receipt->getDriver());
        $this->assertSame('pi_test_1', $receipt->getReferenceId());
        $this->assertRequestedUrl('https://api.stripe.com/v1/checkout/sessions/cs_test_1');
        $this->assertSame('GET', $this->request()->getMethod());
    }

    public function testVerifyFallsBackToTheSessionIdAsReferenceId(): void
    {
        $this->fakeRequest(['session_id' => 'cs_test_1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['id' => 'cs_test_1', 'payment_status' => 'paid'])]);

        $this->assertSame('cs_test_1', $driver->verify()->getReferenceId());
    }

    public function testVerifyFailsWithoutASessionId(): void
    {
        $this->fakeRequest([]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Session ID یافت نشد.');

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheSessionWasNotPaid(): void
    {
        $this->fakeRequest(['session_id' => 'cs_test_1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['id' => 'cs_test_1', 'payment_status' => 'unpaid'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت موفق نبود.');

        $driver->verify();
    }
}
