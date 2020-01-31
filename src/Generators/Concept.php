<?php

namespace Concept\Generators;

use Arr;
use Str;
use InvalidArgumentException;
use UnexpectedValueException;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Concept\Exceptions\InvalidDefinitionException;
use Concept\Overrides\Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Class Concept
 * @package Concept\Generators
 */
abstract class Concept
{
    /**
     * @var string[]
     */
    protected static $registry = [];

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var string
     */
    protected $modelName;

    /**
     * @var string[]
     */
    protected $load = [];

    /**
     * @var array
     */
    protected $attributes = [];

    /**
     * @var Model[]
     */
    protected $modelLibrary = [];

    /**
     * Concept constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $attributes && $this->setAttributes($attributes);
        $this->attributes = $this->attributes();
    }

    /**
     * @param array $attributes
     * @return $this
     */
    public function setAttributes(array $attributes = [])
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return $this->attributes;
    }

    /**
     * @param Concept $concept
     */
    public static function register(self $concept)
    {
        $name = $concept->getName();
        self::$registry[$name] = get_class($concept);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return Str::snake(class_basename(static::class), '-');
    }

    /**
     * @param $name
     * @return string|null
     */
    public static function findInRegistry($name)
    {
        return Arr::get(self::$registry, $name);
    }

    /**
     * @return string[]
     */
    public static function getRegistry()
    {
        return self::$registry;
    }

    /**
     * @return Model
     */
    public function create()
    {
        $model = $this->createModel();

        foreach ($this->load() as $relationName => $relationAlias) {
            $relationName = is_int($relationName) ? $relationAlias : $relationName;
            $relatedModel = $this->createRelationship($relationName, $relationAlias);
            relate_models($model, $relatedModel, $relationName);
            $this->modelLibrary[$relationAlias] = $relatedModel;
        }

        return $model;
    }

    /**
     * @return Model
     */
    protected function createModel()
    {
        if ($this->model) {
            return tap($this->model, function (Model $model) {
                $model->update($this->attributes);
            });
        }

        return $this->model = $this->createFirstFromFactory($this->modelName, $this->attributes);
    }

    /**
     * @return string[]
     */
    public function load()
    {
        return $this->load;
    }

    /**
     * @param string $relationName
     * @param string|null $relationAlias
     * @return Model
     */
    public function createRelationship($relationName, $relationAlias = null)
    {
        $relationAlias = $relationAlias ?: $relationName;

        $relatedModel = $this->getFromLibrary($relationAlias);
        $relatedModel || $relatedModel = $this->createFromRelatedConcept($relationAlias);
        $relatedModel || $relatedModel = $this->createFromFactory($relationName);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @return Model|null
     */
    public function getFromLibrary($relationName)
    {
        /** @var Model|null $relatedModel */
        $relatedModel = Arr::get($this->modelLibrary, $relationName);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param bool $includeLibrary
     * @return Model|null
     */
    public function createFromRelatedConcept($relationName, $includeLibrary = true)
    {
        if (!method_exists($this, $relationName)) {
            return null;
        }

        $concept = $this->$relationName();

        if ($concept instanceof Model) {
            return $concept;
        } elseif (!($concept instanceof Concept)) {
            $call = get_class($this).'::'.$relationName;
            $type = (($type = gettype($concept)) == 'object') ? get_class($concept) : $type;
            throw new UnexpectedValueException("Response type for '$call' expected to be '".Concept::class."'. "
                ."'$type' given.");
        }

        $concept = $concept->setModelLibrary($includeLibrary ? $this->modelLibrary : []);
        $relatedModel = $concept->create();
        $includeLibrary && $this->appendLibrary($concept);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @return Model
     */
    public function createFromFactory($relationName)
    {
        /** @var Relations\Relation $relation */
        $relation = $this->model->$relationName();
        $relationModelName = get_class($relation->getModel());
        $relatedModel = $this->createFirstFromFactory($relationModelName);

        return $relatedModel;
    }

    /**
     * @param string $modelName
     * @param array $attributes
     * @return Model|mixed
     */
    public function createFirstFromFactory($modelName, array $attributes = [])
    {
        /** @var FactoryBuilder $factoryBuilder */
        $factoryBuilder = factory($modelName);

        try {
            /** @var Model $relatedModel */
            $model = $factoryBuilder->make($attributes);
            $model = ($model instanceof EloquentCollection) ? $model->first() : $model;
            $model->save();
        } catch (QueryException $error) {
            if (!in_array($error->getCode(), [
                22003,  //Number out of range
                23000,  //Duplicate key value
                22001   //String too long
            ])) {
                throw $error;
            }

            $source = $factoryBuilder->getLastUsedSource();

            throw InvalidDefinitionException::make($source['class'], $source['name'], $source['location'], $error);
        }

        return $model;
    }

    /**
     * @param Concept $concept
     * @return $this
     */
    public function appendLibrary(Concept $concept)
    {
        $this->modelLibrary = array_merge($this->modelLibrary, $concept->getModelLibrary()->all());

        return $this;
    }

    /**
     * @param Model[] $modelLibrary
     * @return $this
     */
    public function setModelLibrary(array $modelLibrary)
    {
        $this->modelLibrary = $modelLibrary;

        return $this;
    }

    /**
     * @return Model[]|Collection
     */
    public function getModelLibrary()
    {
        return collect($this->modelLibrary);
    }

    /**
     * @return Model
     */
    public function getModel()
    {
        return $this->model ?: $this->createModel();
    }

    /**
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model)
    {
        if (!($model instanceof $this->modelName)) {
            throw new InvalidArgumentException("Model must be an instance of $this->modelName. "
                .get_class($model).' given.');
        }

        $this->model = $model;

        return $this;
    }
}
