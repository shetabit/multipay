<?php

namespace Shetabit\Multipay;

use Ramsey\Uuid\Uuid;
use Shetabit\Multipay\Traits\HasDetail;
use Exception;

class Invoice
{
    use HasDetail;

    /**
     * invoice's unique universal id (uuid)
     */
    protected string|int $uuid;

    /**
     * Amount
     */
    protected int|float|string $amount = 0;

    /**
     * invoice's transaction id
     */
    protected string|int|null $transactionId = null;

    /**
     * Name of the driver the invoice is paid with.
     */
    protected string|null $driver = null;

    /**
     * Invoice constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->uuid();
    }

    /**
     * Set invoice uuid
     *
     * @throws \Exception
     */
    public function uuid(string|int|null $uuid = null): static
    {
        if (empty($uuid)) {
            $uuid = Uuid::uuid4()->toString();
        }

        $this->uuid = $uuid;

        return $this;
    }

    /**
     * Get invoice uuid
     */
    public function getUuid(): string|int
    {
        return $this->uuid;
    }

    /**
     * Set the amount of invoice
     *
     * @throws \Exception
     */
    public function amount(mixed $amount): static
    {
        if (!is_numeric($amount)) {
            throw new Exception('Amount value should be a number (integer or float).');
        }

        $this->amount = $amount;

        return $this;
    }

    /**
     * Get the value of invoice
     */
    public function getAmount(): int|float|string
    {
        return $this->amount;
    }

    /**
     * set transaction id
     */
    public function transactionId(string|int|null $id): static
    {
        $this->transactionId = $id;

        return $this;
    }

    /**
     * Get the value of transaction's id
     */
    public function getTransactionId(): string|int|null
    {
        return $this->transactionId;
    }

    /**
     * Set the value of driver
     */
    public function via(string $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Get the value of driver
     */
    public function getDriver(): string|null
    {
        return $this->driver;
    }
}
