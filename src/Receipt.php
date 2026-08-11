<?php

namespace Shetabit\Multipay;

use Shetabit\Multipay\Abstracts\Receipt as ReceiptAbstract;
use Shetabit\Multipay\Traits\HasDetail;

class Receipt extends ReceiptAbstract
{
    use HasDetail;

    /**
     * Add given value into details
     */
    public function __set(string $name, mixed $value) : void
    {
        $this->detail($name, $value);
    }

    /**
     * Retrieve given value from details
     */
    public function __get(string $name) : mixed
    {
        return $this->getDetail($name);
    }
}
