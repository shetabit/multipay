<?php

namespace Shetabit\Multipay\Traits;

trait HasDetail
{
    /**
     * The details, as a pair of $name => $value.
     *
     * @var array<string, mixed>
     */
    protected array $details = [];

    /**
     * Set a piece of data to the details.
     *
     * @param array|string $key   a single detail's name, or a set of details
     * @param mixed        $value the value of a single detail
     */
    public function detail(array|string $key, mixed $value = null) : static
    {
        $this->details = array_merge($this->details, is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    /**
     * Retrieve detail using its name
     */
    public function getDetail(string $name) : mixed
    {
        return $this->details[$name] ?? null;
    }

    /**
     * Get the value of details
     *
     * @return array<string, mixed>
     */
    public function getDetails() : array
    {
        return $this->details;
    }
}
