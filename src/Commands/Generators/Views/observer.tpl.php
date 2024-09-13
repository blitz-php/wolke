<@php

namespace {namespace};

use {useStatement};
use BlitzPHP\Wolke\Attributes\Observe;

#[Observe({observe}::class)]
class {class}
{
	/**
     * Gérer l'événement "created" de {observe}.
     *
     * @return void
     */
    public function created({observe} {instance})
    {
        //
    }

    /**
     * Gérer l'événement "updated" de {observe}.
     *
     * @return void
     */
    public function updated({observe} {instance})
    {
        //
    }

    /**
     * Gérer l'événement "deleted" de {observe}.
     *
     * @return void
     */
    public function deleted({observe} {instance})
    {
        //
    }

    /**
     * Gérer l'événement "restored" de {observe}.
     *
     * @return void
     */
    public function restored({observe} {instance})
    {
        //
    }

    /**
     * Gérer l'événement "force deleted" de {observe}.
     *
     * @return void
     */
    public function forceDeleted({observe} {instance})
    {
        //
    }
}
