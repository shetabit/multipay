<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Request;

class RequestTest extends TestCase
{

    protected $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new class extends Request
        {

            /**
             * Override Request constructor.
             */
            public function __construct()
            {
            }

            /**
             * Override HTTP request's data.
             *
             * @var array
             */
            public $requestData = [
                'test' => 'test1',
            ];

            /**
             * Override HTTP POST data.
             *
             * @var array
             */
            public $postData = [
                'test' => 'test2',
            ];

            /**
             * Override HTTP GET data.
             *
             * @var array
             */
            public $getData = [
                'test' => 'test3',
            ];

            /**
             * Override Overwritten methods
             * @var array
             */
            public static $overwrittenMethods = [];
        };
    }

    /** @test */
    public function it_should_passed_with_correct_data()
    {
        $this->assertEquals($this->request::input('test'), "test1");
        $this->assertNull($this->request::input('test1'));

        $this->assertEquals($this->request::post('test'), "test2");
        $this->assertNull($this->request::post('test1'));

        $this->assertEquals($this->request::get('test'), "test3");
        $this->assertNull($this->request::get('test1'));
    }

    /** @test */
    public function it_should_failed_with_incorrect_data()
    {
        $this->assertNotEquals($this->request::input('test'), "a");

        $this->assertNotEquals($this->request::get('test'), "b");

        $this->assertNotEquals($this->request::post('test'), "c");
    }


    /** @test */
    public function overwrite_methods_should_be_able_to_be_called()
    {
        $this->request::overwrite("input", function ($name) {
            return strtoupper($name);
        });

        $this->assertEquals($this->request->input('test'), "TEST");

        // you can use reflecation for visibility of protected properties
        // $arguments = $this->deCapsulationProperties(Request::class);
        // $result = $arguments["overwrittenMethods"]->getValue($this->request);

        $result = $this->request::$overwrittenMethods;

        $this->assertCount(1, $result);

        $this->assertArrayHasKey("input", $result);

        $this->assertEquals($result["input"], function ($name) {
            return strtoupper($name);
        });
    }

    /** @test */
    public function it_should_passed_when_use_overwritten_method()
    {
        $this->request::overwrite("input", function ($name) {
            return strtoupper($this->request->requestData[$name]);
        });

        $this->assertEquals($this->request->input('test'), "TEST1");

        $this->request::overwrite("post", function ($name) {
            return ucwords($this->request->postData[$name]);
        });

        $this->assertEquals($this->request->post('test'), "Test2");


        $this->request::overwrite("get", function ($name) {
            return strtolower($this->request->getData[$name]);
        });

        $this->assertEquals($this->request->get('test'), "test3");
    }
}
