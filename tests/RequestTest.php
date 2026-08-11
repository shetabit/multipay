<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Request;

class RequestTest extends TestCase
{
    /**
     * @var array
     */
    private $originalSuperGlobals = [];

    protected function setUp() : void
    {
        parent::setUp();

        $this->originalSuperGlobals = [
            'request' => $_REQUEST,
            'post' => $_POST,
            'get' => $_GET,
        ];
    }

    protected function tearDown() : void
    {
        $_REQUEST = $this->originalSuperGlobals['request'];
        $_POST = $this->originalSuperGlobals['post'];
        $_GET = $this->originalSuperGlobals['get'];

        parent::tearDown();
    }

    public function testItReadsTheRequestData(): void
    {
        $_REQUEST['transactionId'] = '123';

        $this->assertSame('123', Request::input('transactionId'));
    }

    public function testItReadsThePostData(): void
    {
        $_POST['transactionId'] = '456';

        $this->assertSame('456', Request::post('transactionId'));
    }

    public function testItReadsTheGetData(): void
    {
        $_GET['transactionId'] = '789';

        $this->assertSame('789', Request::get('transactionId'));
    }

    public function testMissingKeysAreNull(): void
    {
        $this->assertNull(Request::input('missing'));
        $this->assertNull(Request::post('missing'));
        $this->assertNull(Request::get('missing'));
    }

    public function testTheInputMethodCanBeOverwritten(): void
    {
        $_REQUEST['transactionId'] = 'from-super-global';

        Request::overwrite('input', fn ($name): string => 'overwritten:'.$name);

        $this->assertSame('overwritten:transactionId', Request::input('transactionId'));
    }

    public function testThePostMethodCanBeOverwritten(): void
    {
        Request::overwrite('post', fn ($name): string => 'overwritten:'.$name);

        $this->assertSame('overwritten:transactionId', Request::post('transactionId'));
    }

    public function testTheGetMethodCanBeOverwritten(): void
    {
        Request::overwrite('get', fn ($name): string => 'overwritten:'.$name);

        $this->assertSame('overwritten:transactionId', Request::get('transactionId'));
    }

    public function testOverwritingOneMethodDoesNotAffectTheOthers(): void
    {
        $_POST['transactionId'] = 'from-post';
        $_GET['transactionId'] = 'from-get';

        Request::overwrite('input', fn ($name): string => 'overwritten');

        $this->assertSame('overwritten', Request::input('transactionId'));
        $this->assertSame('from-post', Request::post('transactionId'));
        $this->assertSame('from-get', Request::get('transactionId'));
    }

    public function testOverwrittenMethodsDoNotLeakBetweenTests(): void
    {
        $_REQUEST['transactionId'] = 'from-super-global';

        $this->assertSame('from-super-global', Request::input('transactionId'));
    }
}
