<?php

namespace Concept\Overrides\Illuminate\Database\Eloquent;

use Illuminate\Database\Eloquent\Factory as BaseFactory;

/**
 * Class Factory
 * @package Concept\Overrides\Illuminate\Database\Eloquent
 */
class Factory extends BaseFactory
{
    /**
     * @var array
     */
    protected $sources = [];

    /**
     * {@inheritDoc}
     */
    public function of($class)
    {
        return (new FactoryBuilder(
            $class, $this->definitions, $this->states,
            $this->afterMaking, $this->afterCreating, $this->faker
        ))->setSources($this->sources);
    }

    /**
     * {@inheritDoc}
     */
    public function define($class, callable $attributes)
    {
        [$caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $file = $caller['file'] ?? null;
        $line = $caller['line'] ?? null;
        $location =  implode(':', array_filter([$file, $line]));
        $this->sources[$class] = $location;

        $this->definitions[$class] = $attributes;

        return $this;
    }
}
