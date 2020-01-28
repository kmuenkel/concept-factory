<?php

namespace Concept\Overrides\Illuminate\Database\Eloquent;

use Arr;
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
    public function of($class, $name = 'default')
    {
        return (new FactoryBuilder(
            $class, $name, $this->definitions, $this->states,
            $this->afterMaking, $this->afterCreating, $this->faker
        ))->setSources($this->sources);
    }

    /**
     * {@inheritDoc}
     */
    public function define($class, callable $attributes, $name = 'default')
    {
        [$caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $location =  implode(':', array_filter([Arr::get($caller, 'file'), Arr::get($caller, 'line')]));
        $this->sources[$class][$name] = $location;

        $this->definitions[$class][$name] = $attributes;

        return $this;
    }
}
