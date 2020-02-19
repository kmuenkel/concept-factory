<?php

namespace Concept\Overrides\Illuminate\Database\Eloquent;

use Illuminate\Database\Eloquent\FactoryBuilder as BaseFactoryBuilder;

/**
 * Class FactoryBuilder
 * @package Concept\Overrides\Illuminate\Database\Eloquent
 */
class FactoryBuilder extends BaseFactoryBuilder
{
    /**
     * @var array
     */
    protected $lastDefinitionUsed = [];

    /**
     * @var array
     */
    protected $sources = [];

    /**
     * {@inheritDoc}
     */
    protected function getRawAttributes(array $attributes = [])
    {
        $this->lastDefinitionUsed = [$this->class, $this->name];

        return parent::getRawAttributes($attributes);
    }

    /**
     * @param array $sources
     * @return FactoryBuilder
     */
    public function setSources(array $sources)
    {
        $this->sources = $sources;

        return $this;
    }

    /**
     * @return array
     */
    public function getLastUsedSource()
    {
        /**
         * @var string $class
         * @var string $name
         */
        list($class, $name) = $this->lastDefinitionUsed;
        $location = $this->sources[$class] ?? [];
        $location = $location[$name] ?? null;

        return compact('class', 'name', 'location');
    }
}
