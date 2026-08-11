<?php

namespace Shetabit\Multipay\Tests;

use Carbon\Carbon;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\DriverNotFoundException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Tests\Drivers\BarDriver;
use Shetabit\Multipay\Tests\Drivers\NotADriver;
use Shetabit\Multipay\Tests\Mocks\MockPaymentManager;

class PaymentTest extends TestCase
{
    public function testItHasDefaultDriver(): void
    {
        $config = $this->config();
        $manager = $this->getManagerFreshInstance();

        $this->assertEquals($config['default'], $manager->getDriver());
    }

    public function testItFallsBackToThePackageConfigWhenNoConfigIsGiven(): void
    {
        $packageConfig = require Payment::getDefaultConfigPath();

        $manager = new MockPaymentManager();

        $this->assertSame($packageConfig['default'], $manager->getDriver());
        $this->assertSame($packageConfig['map'], $manager->getConfig()['map']);
    }

    public function testTheDefaultConfigPathPointsToAnExistingFile(): void
    {
        $this->assertFileExists(Payment::getDefaultConfigPath());
    }

    public function testItWontAcceptInvalidDriver(): void
    {
        $this->expectException(\Exception::class);

        $manager = $this->getManagerFreshInstance();
        $manager->via('none_existance_driver_name');
    }

    public function testItThrowsADriverNotFoundExceptionForAnUnknownDriver(): void
    {
        $this->expectException(DriverNotFoundException::class);
        $this->expectExceptionMessage('Driver not found in config file. Try updating the package.');

        $this->getManagerFreshInstance()->via('none_existance_driver_name');
    }

    public function testItThrowsADriverNotFoundExceptionWhenTheDriverClassIsMissing(): void
    {
        $config = $this->config();
        $config['drivers']['ghost'] = ['callbackUrl' => '/callback'];
        $config['map']['ghost'] = 'Shetabit\Multipay\Drivers\Ghost\Ghost';

        $this->expectException(DriverNotFoundException::class);
        $this->expectExceptionMessage('Driver source not found. Please update the package.');

        (new MockPaymentManager($config))->via('ghost');
    }

    public function testItRefusesADriverThatDoesNotImplementTheDriverContract(): void
    {
        $config = $this->config();
        $config['drivers']['invalid'] = ['callbackUrl' => '/callback'];
        $config['map']['invalid'] = NotADriver::class;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Driver must be an instance of Contracts\DriverInterface.');

        (new MockPaymentManager($config))->via('invalid');
    }

    public function testConfigCanBeModified(): void
    {
        $manager = $this->getManagerFreshInstance();

        $manager->config('foo', 'bar');

        $config = $manager->getCurrentDriverSetting();

        $this->assertArrayHasKey('foo', $config);
        $this->assertSame('bar', $config['foo']);
    }

    public function testMultipleConfigsCanBeModifiedAtOnce(): void
    {
        $manager = $this->getManagerFreshInstance();

        $manager->config(['foo' => 'bar', 'john' => 'doe']);

        $config = $manager->getCurrentDriverSetting();

        $this->assertSame('bar', $config['foo']);
        $this->assertSame('doe', $config['john']);
    }

    public function testSwitchingTheDriverResetsTheDriverSettings(): void
    {
        $manager = $this->getManagerFreshInstance();

        $manager->via('bar')->config('foo', 'bar');
        $manager->via('local');

        $this->assertArrayNotHasKey('foo', $manager->getCurrentDriverSetting());
    }

    public function testCallbackUrlCanBeModified(): void
    {
        $manager = $this->getManagerFreshInstance();
        $manager->callbackUrl('/random_url');

        $this->assertEquals('/random_url', $manager->getCallbackUrl());
    }

    public function testAmountCanBeSetted(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();
        $manager->amount($amount);

        $this->assertSame($amount, $manager->getInvoice()->getAmount());
    }

    public function testAnInvalidAmountIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Amount value should be a number (integer or float).');

        $this->getManagerFreshInstance()->amount('not-a-number');
    }

    public function testDeteilCanBeSetted(): void
    {
        $manager = $this->getManagerFreshInstance();

        // array style
        $manager->detail(['foo' => 'bar']);

        // normal style
        $manager->detail('john', 'doe');

        $invoice = $manager->getInvoice();

        $this->assertEquals('bar', $invoice->getDetail('foo'));
        $this->assertEquals('doe', $invoice->getDetail('john'));
    }

    public function testTransactionIdCanBeSetted(): void
    {
        $manager = $this->getManagerFreshInstance();
        $manager->transactionId('transaction-1');

        $this->assertSame('transaction-1', $manager->getInvoice()->getTransactionId());
    }

    public function testDriverCanBeChanged(): void
    {
        $driverName = 'bar';
        $manager = $this->getManagerFreshInstance();
        $manager->via($driverName);

        $this->assertEquals($driverName, $manager->getDriver());
    }

    public function testTheSelectedDriverIsSetOnTheInvoice(): void
    {
        $manager = $this->getManagerFreshInstance();
        $manager->via('bar');

        $this->assertSame('bar', $manager->getInvoice()->getDriver());
    }

    public function testPurchase(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $manager
            ->via('bar')
            ->amount($amount)
            ->purchase(null, function ($driver, $transactionId) use ($amount): void {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($amount, $driver->getInvoice()->getAmount());
            });
    }

    public function testPurchaseIsFluent(): void
    {
        $manager = $this->getManagerFreshInstance();

        $this->assertSame($manager, $manager->via('bar')->amount(10000)->purchase());
    }

    public function testCustomInvoiceCanBeUsedInPurchase(): void
    {
        $manager = $this->getManagerFreshInstance();

        $invoice = new Invoice;
        $invoice->amount(10000);

        $manager
            ->via('bar')
            ->purchase($invoice, function ($driver, $transactionId) use ($invoice): void {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($invoice->getAmount(), $driver->getInvoice()->getAmount());
            });

        $this->assertSame($invoice, $manager->getInvoice());
    }

    public function testPay(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $redirectionForm = $manager->amount($amount)->via('bar')->purchase()->pay();
        $inputs = $redirectionForm->getInputs();

        $this->assertInstanceOf(RedirectionForm::class, $redirectionForm);
        $this->assertEquals($inputs['amount'], $amount);
    }

    public function testPayRunsTheGivenCallbackOnTheDriver(): void
    {
        $manager = $this->getManagerFreshInstance();

        $manager->amount(10000)->via('bar')->purchase()->pay(function ($driver): void {
            $driver->amount(20000);
        });

        $this->assertSame(20000, $manager->getInvoice()->getAmount());
    }

    public function testVerify(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $receipt = $manager->amount($amount)->via('bar')->transactionId(BarDriver::TRANSACTION_ID)->verify();

        $this->assertSame(BarDriver::REFERENCE_ID, $receipt->getReferenceId());
        $this->assertInstanceOf(Carbon::class, $receipt->getDate());
    }

    public function testVerifyRunsTheGivenCallbackWithTheReceipt(): void
    {
        $manager = $this->getManagerFreshInstance();
        $received = [];

        $manager
            ->via('bar')
            ->transactionId(BarDriver::TRANSACTION_ID)
            ->verify(function ($receipt, $driver) use (&$received): void {
                $received = [$receipt, $driver];
            });

        $this->assertInstanceOf(ReceiptInterface::class, $received[0]);
        $this->assertInstanceOf(BarDriver::class, $received[1]);
    }

    public function testPurchaseDispatchesThePurchaseEvent(): void
    {
        $events = [];

        Payment::addPurchaseListener(function ($driver, $invoice) use (&$events): void {
            $events[] = [$driver, $invoice];
        });

        $this->getManagerFreshInstance()->via('bar')->amount(10000)->purchase();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(BarDriver::class, $events[0][0]);
        $this->assertInstanceOf(Invoice::class, $events[0][1]);
    }

    public function testPayDispatchesThePayEvent(): void
    {
        $events = [];

        Payment::addPayListener(function ($driver, $invoice) use (&$events): void {
            $events[] = [$driver, $invoice];
        });

        $this->getManagerFreshInstance()->via('bar')->amount(10000)->purchase()->pay();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(BarDriver::class, $events[0][0]);
    }

    public function testVerifyDispatchesTheVerifyEvent(): void
    {
        $events = [];

        Payment::addVerifyListener(function ($receipt, $driver, $invoice) use (&$events): void {
            $events[] = [$receipt, $driver, $invoice];
        });

        $this->getManagerFreshInstance()->via('bar')->transactionId(BarDriver::TRANSACTION_ID)->verify();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(ReceiptInterface::class, $events[0][0]);
        $this->assertInstanceOf(BarDriver::class, $events[0][1]);
    }

    public function testEventListenersCanBeRemoved(): void
    {
        $calls = 0;
        $listener = function () use (&$calls): void {
            ++$calls;
        };

        Payment::addPurchaseListener($listener);
        Payment::removePurchaseListener($listener);

        $this->getManagerFreshInstance()->via('bar')->amount(10000)->purchase();

        $this->assertSame(0, $calls);
    }

    public function testAllListenersOfAnEventCanBeRemoved(): void
    {
        $calls = 0;
        $listener = function () use (&$calls): void {
            ++$calls;
        };

        Payment::addPayListener($listener);
        Payment::addVerifyListener($listener);
        Payment::removePayListener();
        Payment::removeVerifyListener();

        $this->getManagerFreshInstance()->via('bar')->amount(10000)->purchase()->pay();

        $this->assertSame(0, $calls);
    }

    public function testTheRedirectionFormViewCanBeConfiguredThroughTheManager(): void
    {
        $path = __DIR__.'/Fixtures/views/custom-form.php';

        Payment::setRedirectionFormViewPath($path);

        $this->assertSame($path, Payment::getRedirectionFormViewPath());
        $this->assertSame(RedirectionForm::getDefaultViewPath(), Payment::getRedirectionFormDefaultViewPath());
    }

    public function testTheRedirectionFormRendererCanBeConfiguredThroughTheManager(): void
    {
        Payment::setRedirectionFormViewRenderer(
            fn (string $view, string $action, array $inputs, string $method): string => 'rendered-by-the-manager'
        );

        $form = $this->getManagerFreshInstance()->via('bar')->amount(10000)->purchase()->pay();

        $this->assertSame('rendered-by-the-manager', $form->render());
    }

    protected function getManagerFreshInstance(): MockPaymentManager
    {
        return new MockPaymentManager($this->config());
    }
}
