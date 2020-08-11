<?php

namespace Concept\Exceptions;

use Throwable;
use RuntimeException;

/**
 * Class InvalidDefinitionException
 * @package Concept\Exceptions
 */
class InvalidDefinitionException extends RuntimeException
{
    /**
     * @var string
     */
    protected $class = '';

    /**
     * @var string
     */
    protected $location = '';

    /**
     * @param string $class
     * @param string $location
     * @param Throwable|null $previous
     * @return InvalidDefinitionException
     */
    public static function make($class, $location, Throwable $previous = null)
    {
        $instance = new static("Invalid attributes set by '$class' from '$location'.", 0, $previous);
        $instance->setClass($class)->setLocation($location);

        return $instance;
    }

    /**
     * @param string $class
     * @return $this
     */
    public function setClass(string $class)
    {
        $this->class = $class;

        return $this;
    }

    /**
     * @param string $location
     * @return $this
     */
    public function setLocation(string $location)
    {
        $this->location = $location;

        return $this;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * @return string
     */
    public function getLocation(): string
    {
        return $this->location;
    }
}
