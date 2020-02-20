<?php

namespace Concept\Generators;

use BadMethodCallException;
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
     * @var int
     */
    protected $instances = 0;

    /**
     * Concept constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = null)
    {
        $this->bucket = ConceptBucket::make();
        $this->attributes = !is_null($attributes) ? $attributes : $this->attributes;
        $placeholderModel = app($this->modelName);
        $this->setModel($placeholderModel);
    }

    /**
     * @return ConceptBucket
     */
    public function getActionLog()
    {
        return $this->bucket;
    }

    /**
     * @param int $instances
     * @return $this
     */
    public function setInstances(int $instances)
    {
        $this->instances = ($instances > 0) ? $instances : $this->instances;

        return $this;
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
        $relatedModels = $this->loadRelations($this->load());
        $attributes = array_merge($this->attributes(), $attributes);
        $model = $this->createFirstFromFactory($this->modelName, $attributes);
        $this->setModel($model);
        $this->relateModels($model, $relatedModels);

        return $model;
    }

    /**
     * @param Model $model
     * @param Model[]|EloquentCollection[] $relatedModels
     */
    protected function relateModels(Model $model, array $relatedModels)
    {
        foreach ($relatedModels as $relationName => $relatedModel) {
            if ($relatedModel instanceof Model) {
                $this->relateModel($model, $relatedModel, $relationName);

                continue;
            }

            $relatedModel->each(function ($relatedModel) use ($model, $relationName) {
                $this->relateModel($model, $relatedModel, $relationName);
            });
        }
    }

    /**
     * @param string[] $relationNames
     * @return Model[]|EloquentCollection[]
     */
    protected function loadRelations(array $relationNames)
    {
        $relatedModels = [];

        foreach ($relationNames as $relationName => $relationAlias) {
            $relationName = is_int($relationName) ? $relationAlias : $relationName;
            $relatedModels[$relationName] = $this->createRelationships($relationName, $relationAlias);
            $this->appendLibrary($relatedModels[$relationName], $relationAlias);
        }
        ;
        return $relatedModels;
    }

    /**
     * @param Model $model
     * @param Model $relatedModel
     * @param string $relationName
     * @return $this
     */
    public function relateModel(Model $model, Model $relatedModel, $relationName)
    {
        $before = $model->refresh()->getAttributes();
        try {
            relate_models($model, $relatedModel, $relationName);
        } catch (BadMethodCallException $error) {
            //
        }
        $after = $model->getAttributes();

        $this->bucket->addAction($model, $before, $after);

        return $this;
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
     * @return EloquentCollection|Model
     */
    public function createRelationships($relationName, $relationAlias = null)
    {
        $relation = $this->getModelRelation($this->getModel(), $relationName);
        $isMany = ($relation && ($relation instanceof Relations\HasMany
                || $relation instanceof Relations\MorphMany
                || $relation instanceof Relations\BelongsToMany));

        $collection = new EloquentCollection;
        $counter = $this->instances ?: ($isMany ? 2 : 1);

        do {
            $relationModel = $this->createRelationship($relationName, $relationAlias);
            ($relationModel instanceof Model) ? $collection->push($relationModel)
                : $collection = $collection->merge($relationModel);
        } while ($isMany && --$counter);

        return $isMany ? $collection : $collection->first();
    }

    /**
     * @param Model $model
     * @param string $relationName
     * @return Relations\Relation|null
     */
    protected function getModelRelation(Model $model, $relationName)
    {
        try {
            /** @var Relations\Relation $relation */
            $relation = $model->$relationName();
        } catch (BadMethodCallException $error) {
            $relation = null;
        }

        return $relation;
    }

    /**
     * @param string $relationName
     * @param string|null $relationAlias
     * @return Model|EloquentCollection
     */
    public function createRelationship($relationName, $relationAlias = null)
    {
        $relationAlias = $relationAlias ?: $relationName;

        $relatedModel = $this->getFromLibrary($relationAlias);
        $relatedModel || (!$this->isRecursive() && $relatedModel = $this->createFromRelatedConcept($relationAlias));
        $relatedModel || $relatedModel = $this->createFromFactory($relationName);

        return $relatedModel;
    }

    /**
     * @return bool
     */
    protected function isRecursive()
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $classes = array_column($backtrace, 'class');

        while (end($classes) == static::class) {
            array_pop($classes);
        }

        $isRecursive = in_array(static::class, $classes);

        return $isRecursive;
    }

    /**
     * @param $relationName
     * @param int|null $index
     * @return Model|EloquentCollection|null
     */
    public function getFromLibrary($relationName, ?int $index = null)
    {
        $library = $this->getModelLibrary();
        $relatedModel = $library[$relationName] ?? null;
        (!is_null($index) && $relatedModel instanceof EloquentCollection) && $relatedModel = $relatedModel->get($index);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @param int|null $index
     * @return Model|EloquentCollection|null
     */
    public function createFromLibrary($relationName, array $attributes = [], ?int $index = 0)
    {
        $relatedModels = clone $this->getFromLibrary($relationName, $index);

        if ($relatedModels instanceof Model) {
            $relatedModels->update($attributes);
        } elseif ($relatedModels instanceof EloquentCollection) {
            $relatedModels->transform(function (Model $relatedModel) use ($attributes) {
                $relatedModel = clone $relatedModel;
                $relatedModel->update($attributes);

                return $relatedModel;
            });
        }

        return $relatedModels;
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
        $relatedModel = $concept->create($attributes);
        $includeLibrary && $this->mergeLibrary($concept);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @return Model|null
     */
    public function createFromFactory($relationName, array $attributes = [])
    {
        $relatedModel = null;

        if ($relation = $this->getModelRelation($this->getModel(), $relationName)) {
            $relationModelName = get_class($relation->getModel());
            $relatedModel = $this->createFirstFromFactory($relationModelName, $attributes);
        }

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
            /** @var Model $model */
            $model = $factoryBuilder->make($attributes);
            $model = ($model instanceof EloquentCollection) ? $model->first() : $model;
            $this->bucket->addAction($model);
            $model->save();
        } catch (QueryException $error) {
            if (!in_array($error->getCode(), [
                    22003,  //Number out of range
                    23000,  //Duplicate key value
                    22001   //String too long
                ]) || !method_exists($factoryBuilder, 'getLastUsedSource')) {
                throw $error;
            }

            $source = $factoryBuilder->getLastUsedSource();
            $source = array_map(function ($item) {
                return (string)$item;
            }, $source);

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
     * @param Model|EloquentCollection $relatedModel
     * @param string $relationAlias
     * @return $this
     */
    public function appendLibrary($relatedModel, $relationAlias)
    {
        if (!($relatedModel instanceof Model) && !($relatedModel instanceof EloquentCollection)) {
            $type = (($type = gettype($relatedModel)) == 'object') ? get_class($relatedModel) : $type;
            throw new InvalidArgumentException('First argument must be an instance of '.Model::class. ' or '
                .EloquentCollection::class.". '$type' given.");
        }

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
        return $this->model;
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
