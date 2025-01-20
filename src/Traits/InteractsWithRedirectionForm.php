<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\RedirectionForm;

trait InteractsWithRedirectionForm
{
    /**
     * Set view path of redirection form.
     *
     *
     */
    public static function setRedirectionFormViewPath(string $path): void
    {
        RedirectionForm::setViewPath($path);
    }

    /**
     * Retrieve default view path of redirection form.
     */
    public static function getRedirectionFormDefaultViewPath() : string
    {
        return RedirectionForm::getDefaultViewPath();
    }

    /**
     * Retrieve current view path of redirection form.
     */
    public static function getRedirectionFormViewPath() : string
    {
        return RedirectionForm::getViewPath();
    }

    /**
     * Set view renderer
     */
    public static function setRedirectionFormViewRenderer(callable $renderer): void
    {
        RedirectionForm::setViewRenderer($renderer);
    }
}
