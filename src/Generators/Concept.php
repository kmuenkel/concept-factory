<?php

namespace Concept\Generators;

use BadMethodCallException;
use Illuminate\Support\Str;
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
    protected $instances = 1;

    /**
     * @var Model[]
     */
    protected $relatedModels = [];

    /**
     * @var string
     */
    protected $name;

    /**
     * Concept constructor.
     * @param array $attributes
     * @param int $instances
     */
    public function __construct(array $attributes = [], int $instances = 0, $name = null)
    {
        $this->bucket = ConceptBucket::make();
        $this->setAttributes(array_merge($this->attributes, $attributes));
        $this->setModel(app($this->modelName));
        $this->setInstances($instances ?: $this->instances);
        $this->name = $name ?: Str::camel(class_basename(static::class));
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
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
        $this->instances = $this->normalizeInstanceCounter($instances);

        return $this;
    }

    /**
     * @param int|null $instances
     * @return int
     */
    protected function normalizeInstanceCounter(?int $instances = null)
    {
        $instances = (int)$instances;

        return ($instances > 0) ? $instances : 1;
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
     * @param int|null $instances
     * @return Collection|Model
     */
    public function create(array $attributes = [], int $instances = null)
    {
        $counter = $instances = $this->normalizeInstanceCounter($instances ?: $this->instances);
        $collection = new Collection;

        do {
            $relationNames = $this->load();
            $model = $this->createOrUpdate($attributes);
            $collection->push($model);

            $models = ($instances > 1) ? $collection : $model;
            $this->appendLibrary($models, $this->name);

            $relatedModels = $this->with($relationNames)->getLoaded();
            $this->relateModels($relatedModels);
        } while (--$counter);

        return $models;
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
        $model->refresh()->update($attributes);
        $this->setModel($model);

        return $model;
    }

    /**
     * @param Model[]|Collection[] $relatedModels
     * @return Concept
     */
    public function relateModels(array $relatedModels)
    {
        foreach ($relatedModels as $relationName => $relatedModel) {
            if ($relatedModel instanceof Model) {
                $this->relateModel($relationName, $relatedModel);
            } elseif ($relatedModel instanceof Collection) {
                $relatedModel->each(function ($relatedModel) use ($relationName) {
                    $this->relateModel($relationName, $relatedModel);
                });
            } else {
                $type = get_type($relatedModel);

                throw new UnexpectedValueException("All items for the second argument must be of type '".Model::class
                    ."' or '".Collection::class."'. '$type' given.");
            }
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
            $relationName = $this->inferRelationName($relationName);
            $relationAlias = $this->inferRelationAlias($relationAlias);

            $this->relatedModels[$relationName] = $this->createRelationship($relationName, $relationAlias);
            $this->appendLibrary($this->relatedModels[$relationName], $relationAlias);
        }

        return $this;
    }

    /**
     * @param string $relationName
     * @param Model $relatedModel
     * @return $this
     */
    public function relateModel($relationName, Model $relatedModel)
    {
        $model = $this->createOrUpdate();
        $before = $model->getAttributes();

        try {
            relate_models($model, $relatedModel, $relationName);
        } catch (BadMethodCallException $error) {
            //
        } catch (QueryException $error) {
            if ($error->getCode() != 23000) {
                throw $error;
            }
        }

        $after = $model->getAttributes();
        $bucket = $this->getActionLog();
        $bucket->addAction($model, $before, $after);

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
     * @return bool
     */
    public function relationIsMany($relationName)
    {
        $model = $this->getModel();
        /** @var Relations\Relation $relation */
        $relation = $this->hasRelatedModel($relationName) ? $model->$relationName() : null;
        $isMany = ($relation && ($relation instanceof Relations\HasMany
                || $relation instanceof Relations\MorphMany
                || $relation instanceof Relations\BelongsToMany));

        return $isMany;
    }

    /**
     * @param string $relationName
     * @return bool
     */
    public function relationLoaded($relationName)
    {
        $library = $this->getModelLibrary();

        return $library->has($relationName);
    }

    /**
     * @param string $relationAlias
     * @return bool
     */
    protected function inferRelationAlias($relationName)
    {
        $singular = Str::singular($relationName);
        $plural = Str::plural($relationName);

        $transformedName = $this->relationLoaded($relationName) ? $relationName : null;
        $transformedName = (!$transformedName && $this->relationLoaded($singular)) ? $singular : $transformedName;
        $transformedName = (!$transformedName && $this->relationLoaded($plural)) ? $plural : $transformedName;

        return $transformedName ?: $relationName;
    }

    /**
     * @param string $relationName
     * @param string|null $relationAlias
     * @return Model|Collection
     */
    public function createRelationship($relationName, $relationAlias = null)
    {
        $relationAlias = $relationAlias ?: $relationName;
        $relatedModel = null;

        if ($this->relationLoaded($relationAlias)) {
            $relatedModel = $this->getFromLibrary($relationAlias);
            if ($this->relationIsMany($relationName) && $relatedModel instanceof Model) {
                $relatedModel = new Collection([$relatedModel]);
            }
        } elseif ($this->hasRelatedConcept($relationAlias)) {
            $relatedModel = $this->createRelationFromConcept($relationAlias);
        } elseif ($this->hasRelatedModel($relationName)) {
            $relatedModel = $this->createRelationFromFactory($relationName);
        }

        if (!$relatedModel) {
            throw new UnexpectedValueException("Unable to find generator for Model relation '$relationAlias' in '".static::class."'.");
        }

        return $relatedModel;
    }

    /**
     * @param $relationName
     * @param int|null $index
     * @return Model|Collection|null
     */
    public function getFromLibrary($relationName, ?int $index = null)
    {
        $library = $this->getModelLibrary();
        $relatedModel = $library->get($relationName);
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
     * @param $relationName
     * @param Model|null $baseModel
     * @return Collection|Model
     */
    public function createRelationFromConcept($relationName, Model $baseModel = null)
    {
        $concept = $this->$relationName();

        if ($concept instanceof Model) {
            return $concept;
        } elseif (!($concept instanceof Concept)) {
            $call = get_class($this).'::'.$relationName.'()';
            $type = get_type($concept);

            throw new UnexpectedValueException("Response type for '$call' expected to be '".Concept::class."'. "
                ."'$type' given.");
        }

        $concept->setName($relationName);

        $instances = !$this->relationIsMany($relationName) ? 1
            : (($instances = $concept->getInstances()) == 1 ? 2 : $instances);

        $modelLibrary = $this->getModelLibrary();
        $concept = $concept->setModelLibrary($modelLibrary);
        $baseModel && $concept->setModel($baseModel);
        $relatedModel = $concept->create([], $instances);
        $this->mergeLibrary($concept);

        return $relatedModel;
    }

    /**
     * @return int
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * @param $relationName
     * @return bool
     */
    public function hasRelatedModel($relationName)
    {
        $model = $this->getModel();

        return method_exists($model, $relationName);
    }

    /**
     * @param string $relationName
     * @return bool
     */
    protected function inferRelationName($relationName)
    {
        $singular = Str::singular($relationName);
        $plural = Str::plural($relationName);

        $transformedName = $this->hasRelatedModel($relationName) ? $relationName : null;
        $transformedName = (!$transformedName && $this->hasRelatedModel($singular)) ? $singular : $transformedName;
        $transformedName = (!$transformedName && $this->hasRelatedModel($plural)) ? $plural : $transformedName;

        return $transformedName ?: $relationName;
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
        $instances = $this->relationIsMany($relationName) ? 2 : 1;
        $relatedModel = $this->createFirstFromFactory($relationModelName, $attributes, $instances);

        return $relatedModel;
    }

    /**
     * @param string $modelName
     * @param array $attributes
     * @param int $instances
     * @return Model
     */
    public function createFirstFromFactory($modelName, array $attributes = [], int $instances = 1)
    {
        /** @var FactoryBuilder $factoryBuilder */
        $factoryBuilder = factory($modelName, $instances);

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

            throw InvalidDefinitionException::make($source['class'], $source['location'], $error);
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
        $modelLibrary = $modelLibrary->merge($relatedLibrary);
        $this->setModelLibrary($modelLibrary->all());

        return $this;
    }

    /**
     * @param array|Collection|Model[]|Collection[] $modelLibrary
     * @return $this
     */
    public function setModelLibrary($modelLibrary)
    {
        $modelLibrary = $modelLibrary instanceof Collection ? $modelLibrary->all() : $modelLibrary;
        $this->modelLibrary = $modelLibrary;

        return $this;
    }

    /**
     * @param Model|Collection $relatedModel
     * @param string|null $relationAlias
     * @return $this
     */
    public function appendLibrary($relatedModel, $relationAlias = null)
    {
        if (!($relatedModel instanceof Model) && !($relatedModel instanceof Collection)) {
            $type = get_type($relatedModel);

            throw new InvalidArgumentException('First argument must be an instance of '.Model::class. ' or '
                .Collection::class.". '$type' given.");
        }

        $defaultAlias = get_class($relatedModel instanceof Model ? $relatedModel : $relatedModel->first());
        $relationAlias = $relationAlias ?: $defaultAlias;
        $this->modelLibrary[$relationAlias] = $relatedModel;

        return $this;
    }

    /**
     * @return Collection|Model[]|Collection[]
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
