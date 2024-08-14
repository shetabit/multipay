<?php

namespace Shetabit\Multipay\Drivers\Pasargad\PasargadReducer;

class PasargadReducer
{

    protected $urlId;


    public function urlId(string $id): PasargadReducer
    {
        $this->urlId = $id;
        return $this;
    }


    public function getUrlId(): string
    {
        return $this->urlId;
    }
}
