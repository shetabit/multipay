<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\RedirectionForm;
use Error;
use Exception;

class RedirectionFormTest extends TestCase
{
    public function testItKeepsTheGivenActionInputsAndMethod(): void
    {
        $form = new RedirectionForm('https://example.com/pay', ['amount' => 10000], 'GET');

        $this->assertSame('https://example.com/pay', $form->getAction());
        $this->assertSame(['amount' => 10000], $form->getInputs());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testItUsesPostAndAnEmptyInputListByDefault(): void
    {
        $form = new RedirectionForm('https://example.com/pay');

        $this->assertSame([], $form->getInputs());
        $this->assertSame('POST', $form->getMethod());
    }

    public function testGetUrlIsAnAliasOfGetAction(): void
    {
        $form = new RedirectionForm('https://example.com/pay');

        $this->assertSame($form->getAction(), $form->getUrl());
    }

    public function testItFallsBackToTheDefaultViewPath(): void
    {
        $this->assertSame(RedirectionForm::getDefaultViewPath(), RedirectionForm::getViewPath());
        $this->assertFileExists(RedirectionForm::getDefaultViewPath());
    }

    public function testTheViewPathCanBeChanged(): void
    {
        RedirectionForm::setViewPath($this->customViewPath());

        $this->assertSame($this->customViewPath(), RedirectionForm::getViewPath());
    }

    public function testItRendersTheDefaultView(): void
    {
        $form = new RedirectionForm('https://example.com/pay', ['amount' => 10000], 'GET');

        $output = $form->render();

        $this->assertStringContainsString('method="GET"', $output);
        $this->assertStringContainsString('action="https://example.com/pay"', $output);
        $this->assertStringContainsString('name="amount"', $output);
        $this->assertStringContainsString('value="10000"', $output);
    }

    public function testItRendersACustomView(): void
    {
        RedirectionForm::setViewPath($this->customViewPath());

        $form = new RedirectionForm('https://example.com/pay', ['amount' => 10000, 'token' => 'abc'], 'GET');

        $this->assertStringContainsString(
            'custom-view:GET:https://example.com/pay:amount,token',
            $form->render()
        );
    }

    public function testItRendersUsingACustomRenderer(): void
    {
        $received = [];

        RedirectionForm::setViewRenderer(
            function (string $view, string $action, array $inputs, string $method) use (&$received): string {
                $received = ['view' => $view, 'action' => $action, 'inputs' => $inputs, 'method' => $method];

                return 'rendered-by-custom-renderer';
            }
        );

        $form = new RedirectionForm('https://example.com/pay', ['amount' => 10000], 'GET');

        $this->assertSame('rendered-by-custom-renderer', $form->render());
        $this->assertSame(RedirectionForm::getViewPath(), $received['view']);
        $this->assertSame('https://example.com/pay', $received['action']);
        $this->assertSame(['amount' => 10000], $received['inputs']);
        $this->assertSame('GET', $received['method']);
    }

    public function testARendererIsCalledWithNamedArguments(): void
    {
        // The renderer is invoked with named arguments, so it has to declare the
        // $view, $action, $inputs and $method parameters.
        RedirectionForm::setViewRenderer($this->rendererReturning('ok'));

        $this->assertSame('ok', new RedirectionForm('https://example.com/pay')->render());

        RedirectionForm::setViewRenderer(fn (): string => 'never-called');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $view');

        new RedirectionForm('https://example.com/pay')->render();
    }

    public function testItIsRenderedWhenCastedToString(): void
    {
        RedirectionForm::setViewRenderer($this->rendererReturning('rendered'));

        $form = new RedirectionForm('https://example.com/pay');

        $this->assertSame('rendered', $form->toString());
        $this->assertSame('rendered', (string) $form);
    }

    public function testItIsSerializedToJson(): void
    {
        $form = new RedirectionForm('https://example.com/pay', ['amount' => 10000], 'GET');

        $this->assertSame(
            [
                'action' => 'https://example.com/pay',
                'inputs' => ['amount' => 10000],
                'method' => 'GET',
            ],
            $form->jsonSerialize()
        );

        $this->assertSame(
            '{"action":"https:\/\/example.com\/pay","inputs":{"amount":10000},"method":"GET"}',
            json_encode($form)
        );
    }

    public function testToJsonReturnsTheJsonRepresentation(): void
    {
        $form = new RedirectionForm('https://example.com/pay', ['description' => 'پرداخت'], 'GET');

        // Unicode is kept readable, slashes are escaped by json_encode()'s defaults.
        $this->assertSame(
            '{"action":"https:\/\/example.com\/pay","inputs":{"description":"پرداخت"},"method":"GET"}',
            $form->toJson()
        );
    }

    public function testToJsonThrowsWhenTheDataCanNotBeEncoded(): void
    {
        $form = new RedirectionForm('https://example.com/pay', ['broken' => "\xB1\x31"]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');

        $form->toJson();
    }

    public function testTheStaticViewStateDoesNotLeakBetweenTests(): void
    {
        // testTheViewPathCanBeChanged() and testItRendersACustomView() both set a custom
        // view path. The base test case has to reset it after each test.
        $this->assertSame(RedirectionForm::getDefaultViewPath(), RedirectionForm::getViewPath());
    }

    private function customViewPath() : string
    {
        return __DIR__.'/Fixtures/views/custom-form.php';
    }

    /**
     * A view renderer with the parameter names the package expects.
     */
    private function rendererReturning(string $output) : callable
    {
        return fn (string $view, string $action, array $inputs, string $method): string => $output;
    }
}
