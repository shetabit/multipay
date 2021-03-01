<?php

namespace Shetabit\Multipay\Abstracts;

use Carbon\Carbon;
use Shetabit\Multipay\Contracts\ReceiptInterface;

abstract class Receipt implements ReceiptInterface
{
    /**
     * A unique ID which is given to the customer whenever the payment is done successfully.
     * This ID can be used for financial follow up.
     *
     * @var string
     */
    protected $referenceId;

    /**
     * payment driver's name.
     *
     * @var string
     */
    protected $driver;

    /**
     * payment CardNumber.
     *
     * @var string
     */
    protected $card;

    /**
     * payment cardHash.
     *
     * @var string
     */
    protected $hash;

    /**
     * payment date
     *
     * @var Carbon
     */
    protected $date;

    /**
     * Receipt constructor.
     *
     * @param $driver
     * @param $referenceId
     * @param string $card
     * @param string $hash
     */
    public function __construct($driver, $referenceId,$card="",$hash="")
    {
        $this->driver = $driver;
        $this->referenceId = $referenceId;
        $this->card = $card;
        $this->hash = $hash;
        $this->date = Carbon::now();
    }

    /**
     * Retrieve driver's name
     *
     * @return string
     */
    public function getDriver() : string
    {
        return $this->driver;
    }

    /**
     * Retrieve payment reference code.
     *
     * @return string
     */
    public function getReferenceId() : string
    {
        return (string) $this->referenceId;
    }

    /**
     * Retrieve payment reference code.
     *
     * @return string
     */
    public function getCardNumber() : string
    {
        return (string) $this->card;
    }

    /**
     * Retrieve payment reference code.
     *
     * @return string
     */
    public function getCardHash() : string
    {
        return (string) $this->hash;
    }

    /**
     * Retrieve payment date
     *
     * @return Carbon|\Illuminate\Support\Carbon
     */
    public function getDate() : Carbon
    {
        return $this->date;
    }
}
