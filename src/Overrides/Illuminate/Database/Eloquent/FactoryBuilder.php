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
     * @var string
     */
    protected $lastDefinitionUsed = '';

    /**
     * @var array
     */
    protected $sources = [];

    /**
     * {@inheritDoc}
     */
    protected function getRawAttributes(array $attributes = [])
    {
        $this->lastDefinitionUsed = $this->class;

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
        $class = $this->lastDefinitionUsed;
        $location = $this->sources[$class] ?? [];

        return compact('class', 'location');
    }
}
