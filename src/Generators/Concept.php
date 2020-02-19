<?php

namespace Concept\Generators;

use InvalidArgumentException;
use UnexpectedValueException;
use Concept\Logging\ConceptBucket;
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
     * @var ConceptBucket
     */
    protected $bucket;

    /**
     * Concept constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $attributes = array_merge($this->attributes(), $attributes);
        $this->setAttributes($attributes);

        $this->bucket = ConceptBucket::make();
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
        $className = basename(str_replace('\\', '/', static::class));
        $name = preg_replace('/(.)(?=[A-Z])/u', '$1-', $className);
        $name = strtolower($name);

        return $name;
    }

    /**
     * @param $name
     * @return string|null
     */
    public static function findInRegistry($name)
    {
        return self::$registry[$name] ?? null;
    }

    /**
     * @return string[]
     */
    public static function getRegistry()
    {
        return self::$registry;
    }

    /**
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes = [])
    {
        $attributes = array_merge($this->attributes(), $attributes);
        $model = $this->createModel($attributes);

        foreach ($this->load() as $relationName => $relationAlias) {
            $relationName = is_int($relationName) ? $relationAlias : $relationName;
            $relatedModel = $this->createRelationship($relationName, $relationAlias);
            $this->relateModels($model, $relatedModel, $relationName);
            $this->appendLibrary($relatedModel, $relationAlias);
        }

        return $model;
    }

    /**
     * @param Model $model
     * @param Model $relatedModel
     * @param string $relationName
     * @return $this
     */
    public function relateModels(Model $model, Model $relatedModel, $relationName)
    {
        $before = $model->getAttributes();
        relate_models($model, $relatedModel, $relationName);
        $after = $model->getAttributes();

        $this->bucket->addAction($model, $before, $after);

        return $this;
    }

    /**
     * @param array $attributes
     * @return Model
     */
    protected function createModel(array $attributes = [])
    {
        if ($this->model) {
            $this->model->update($attributes);

            return $this->model;
        }

        return $this->model = $this->createFirstFromFactory($this->modelName, $attributes);
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
        $relatedModel = $this->modelLibrary[$relationName] ?? null;

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @return Model|null
     */
    public function createFromLibrary($relationName, array $attributes = [])
    {
        $relatedModel = clone $this->getFromLibrary($relationName);
        $attributes && $relatedModel->update($attributes);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @param bool $includeLibrary
     * @return Model|null
     */
    public function createFromRelatedConcept($relationName, array $attributes = [], $includeLibrary = true)
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
        $attributes && $relatedModel->update($attributes);
        $includeLibrary && $this->mergeLibrary($concept);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @return Model
     */
    public function createFromFactory($relationName, array $attributes = [])
    {
        /** @var Relations\Relation $relation */
        $relation = $this->model->$relationName();
        $relationModelName = get_class($relation->getModel());
        $relatedModel = $this->createFirstFromFactory($relationModelName, $attributes);

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
            $this->bucket->addAction($model);
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
    public function mergeLibrary(Concept $concept)
    {
        $relatedLibrary = $concept->getModelLibrary();
        $this->modelLibrary = array_merge($this->modelLibrary, $relatedLibrary);

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
     * @param Model $relatedModel
     * @param string $relationAlias
     * @return $this
     */
    public function appendLibrary(Model $relatedModel, $relationAlias)
    {
        $this->modelLibrary[$relationAlias] = $relatedModel;

        return $this;
    }

    /**
     * @return Model[]
     */
    public function getModelLibrary()
    {
        return $this->modelLibrary;
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
