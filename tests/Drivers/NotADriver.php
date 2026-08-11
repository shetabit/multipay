<?php

namespace Shetabit\Multipay\Tests\Drivers;

/**
 * A class that is *not* a payment driver, used to assert that the payment
 * manager refuses to work with classes that do not implement the driver contract.
 */
class NotADriver
{
}
