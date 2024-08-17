<?php

namespace Shetabit\Multipay\Drivers\Pasargad\PasargadHolder;

class PasargadHolder
{

    protected $urlId;


    public function urlId(string $id): PasargadHolder
    {
        $this->urlId = $id;
        return $this;
    }


    public function getUrlId(): string
    {
        return $this->urlId;
    }
}
