<?php

namespace Concept\Generators;

use BadMethodCallException;
use InvalidArgumentException;
use UnexpectedValueException;
use Concept\Logging\ConceptBucket;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Collection;
use Concept\Exceptions\InvalidDefinitionException;
use Concept\Overrides\Illuminate\Database\Eloquent\FactoryBuilder;

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
     * @var Model[]
     */
    protected $relatedModels = [];

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
        $relationNames = $this->load();
        $this->with($relationNames);
        $relatedModels = $this->getLoaded();
        $model = $this->createOrUpdate($attributes);
        $this->relateModels($model, $relatedModels);

        return $model;
    }

    /**
     * @return Model[]
     */
    public function getLoaded()
    {
        return $this->relatedModels;
    }

    /**
     * @param array $attributes
     * @return Model
     */
    public function createOrUpdate(array $attributes = [])
    {
        $attributes = array_merge($this->attributes(), $attributes);
        $model = $this->getModel();
        $model->exists || $model = $this->createFirstFromFactory($this->modelName, $attributes);
        $model->update($attributes);
        $this->setModel($model);

        return $model;
    }

    /**
     * @param Model $model
     * @param Model[]|Collection[] $relatedModels
     * @return Concept
     */
    public function relateModels(Model $model, array $relatedModels)
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

        return $this;
    }

    /**
     * @param string[] $relationNames
     * @return $this
     */
    public function with(array $relationNames)
    {
        foreach ($relationNames as $relationName => $relationAlias) {
            $relationName = is_int($relationName) ? $relationAlias : $relationName;
            $this->relatedModels[$relationName] = $this->createRelationships($relationName, $relationAlias);
            $this->appendLibrary($this->relatedModels[$relationName], $relationAlias);
        }

        return $this;
    }

    /**
     * @param Model $model
     * @param Model $relatedModel
     * @param string $relationName
     */
    protected function relateModel(Model $model, Model $relatedModel, $relationName)
    {
        $before = $model->refresh()->getAttributes();
        try {
            relate_models($model, $relatedModel, $relationName);
        } catch (BadMethodCallException $error) {
            //
        }
        $after = $model->getAttributes();

        $bucket = $this->getActionLog();
        $bucket->addAction($model, $before, $after);
    }

    /**
     * @return string[]
     */
    public function load()
    {
        return $this->load;
    }

    /**
     * @param string[] $load
     * @return $this
     */
    public function setLoad(array $load)
    {
        $this->load = $load;

        return $this;
    }

    /**
     * @param string $relationName
     * @param string|null $relationAlias
     * @return Collection|Model
     */
    public function createRelationships($relationName, $relationAlias = null)
    {
        $model = $this->getModel();
        /** @var Relations\Relation $relation */
        $relation = $this->hasRelatedModel($relationName) ? $model->$relationName() : null;
        $isMany = ($relation && ($relation instanceof Relations\HasMany
                || $relation instanceof Relations\MorphMany
                || $relation instanceof Relations\BelongsToMany));

        $collection = new Collection;
        $counter = ($this->instances > 0) ? $this->instances : ($isMany ? 2 : 1);

        do {
            $relationModel = $this->createRelationship($relationName, $relationAlias);
            ($relationModel instanceof Model) ? $collection->push($relationModel)
                : $collection = $collection->merge($relationModel);
        } while ($isMany && --$counter);

        return $isMany ? $collection : $collection->first();
    }

    /**
     * @param string $relationName
     * @return bool
     */
    public function relationLoaded($relationName)
    {
        $library = $this->getModelLibrary();

        return array_key_exists($relationName, $library);
    }

    /**
     * @param string $relationName
     * @return bool
     */
    public function hasRelation($relationName)
    {
        $load = $this->load();
        $relationAlias = $load[$relationName] ?? $relationName;

        return $this->relationLoaded($relationAlias) || $this->hasRelatedConcept($relationAlias)
            || $this->hasRelatedModel($relationName);
    }

    /**
     * @param string $relationName
     * @param string|null $relationAlias
     * @return Model|Collection
     */
    public function createRelationship($relationName, $relationAlias = null)
    {
        $relationAlias = $relationAlias ?: $relationName;

        $relatedModel = $this->getFromLibrary($relationAlias);
        $relatedModel || (!$this->isRecursive()
            && $this->hasRelatedConcept($relationAlias)
            && $relatedModel = $this->createRelationFromConcept($relationAlias));
        $relatedModel || ($this->hasRelatedModel($relationName)
            && $relatedModel = $this->createRelationFromFactory($relationName));

        if (!$relatedModel) {
            throw new UnexpectedValueException();
        };

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
     * @return Model|Collection|null
     */
    public function getFromLibrary($relationName, ?int $index = null)
    {
        $library = $this->getModelLibrary();
        $relatedModel = $library[$relationName] ?? null;
        (!is_null($index) && $relatedModel instanceof Collection) && $relatedModel = $relatedModel->get($index);

        return $relatedModel;
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @param int|null $index
     * @return Model|Collection|null
     */
    public function createFromLibrary($relationName, array $attributes = [], ?int $index = 0)
    {
        $relatedModels = $this->getFromLibrary($relationName, $index);

        if ($relatedModels instanceof Model) {
            $relatedModels = clone $relatedModels;
            $relatedModels->update($attributes);
        } elseif ($relatedModels instanceof Collection) {
            $relatedModels->transform(function (Model $relatedModel) use ($attributes) {
                $relatedModel = clone $relatedModel;
                $relatedModel->update($attributes);

                return $relatedModel;
            });
        }

        return $relatedModels;
    }

    /**
     * @param string $relationAlias
     * @return bool
     */
    public function hasRelatedConcept($relationAlias)
    {
        return method_exists($this, $relationAlias);
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @param bool $includeLibrary
     * @return Model|null
     */
    public function createRelationFromConcept($relationName, array $attributes = [], $includeLibrary = true)
    {
        $concept = $this->$relationName();

        if ($concept instanceof Model) {
            return $concept;
        } elseif (!($concept instanceof Concept)) {
            $call = get_class($this).'::'.$relationName;
            $type = (($type = gettype($concept)) == 'object') ? get_class($concept) : $type;

            throw new UnexpectedValueException("Response type for '$call' expected to be '".Concept::class."'. "
                ."'$type' given.");
        }

        $modelLibrary = $this->getFromLibrary();
        $concept = $concept->setModelLibrary($includeLibrary ? $modelLibrary : []);
        $relatedModel = $concept->create($attributes);
        $includeLibrary && $this->mergeLibrary($concept);

        return $relatedModel;
    }

    /**
     * @param $relationName
     * @param Model|null $model
     * @return bool
     */
    public function hasRelatedModel($relationName, Model $model = null)
    {
        $model = $model ?: $this->getModel();

        return method_exists($model, $relationName);
    }

    /**
     * @param string $relationName
     * @param array $attributes
     * @return Model|null
     */
    public function createRelationFromFactory($relationName, array $attributes = [])
    {
        $relatedModel = null;
        $model = $this->getModel();
        /** @var Relations\Relation $relation */
        $relation = $model->$relationName();
        $relationModelName = get_class($relation->getModel());
        $relatedModel = $this->createFirstFromFactory($relationModelName, $attributes);

        return $relatedModel;
    }

    /**
     * @param string $modelName
     * @param array $attributes
     * @return Model
     */
    public function createFirstFromFactory($modelName, array $attributes = [])
    {
        /** @var FactoryBuilder $factoryBuilder */
        $factoryBuilder = factory($modelName);

        try {
            /** @var Model $model */
            $model = $factoryBuilder->make($attributes);
            $model = ($model instanceof Collection) ? $model->first() : $model;
            $bucket = $this->getActionLog();
            $bucket->addAction($model);
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
        $modelLibrary = $this->getModelLibrary();
        $relatedLibrary = $concept->getModelLibrary();
        $modelLibrary = array_merge($modelLibrary, $relatedLibrary);
        $this->setModelLibrary($modelLibrary);

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
     * @param Model|Collection $relatedModel
     * @param string $relationAlias
     * @return $this
     */
    public function appendLibrary($relatedModel, $relationAlias)
    {
        if (!($relatedModel instanceof Model) && !($relatedModel instanceof Collection)) {
            $type = (($type = gettype($relatedModel)) == 'object') ? get_class($relatedModel) : $type;
            throw new InvalidArgumentException('First argument must be an instance of '.Model::class. ' or '
                .Collection::class.". '$type' given.");
        }

        $this->modelLibrary[$relationAlias] = $relatedModel;

        return $this;
    }

    /**
     * @return Model[]|Collection[]
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

    /**
     * @void
     */
    public function __clone()
    {
        $library = $this->getModelLibrary();

        foreach ($library as $relationAlias => $relatedModel) {
            $relatedModel = ($relatedModel instanceof Collection) ? $relatedModel->map(function (Model $relatedModel) {
                return clone $relatedModel;
            }) : clone $relatedModel;

            $this->appendLibrary($relatedModel, $relationAlias);
        }

        $model = clone $this->getModel();
        $this->setModel($model);
    }
}
