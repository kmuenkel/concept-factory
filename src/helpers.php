<?php

use Symfony\Component\Finder\Finder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

if (!function_exists('load')) {
    /**
     * Register all of the commands in the given directory.
     *
     * @param array|string $paths
     * @param string $parentClassName
     * @param callable|null $register
     * @see Illuminate\Foundation\Console\Kernel::load()
     */
    function load($paths, $parentClassName = null, callable $register = null)
    {
        $paths = array_unique(Arr::wrap($paths));
        $paths = array_filter($paths, function ($path) {
            return is_dir($path);
        });

        if (empty($paths)) {
            return;
        }

        $namespace = app()->getNamespace();

        /** @var SplFileInfo $fileInfo */
        foreach ((new Finder)->in($paths)->files() as $fileInfo) {
            $className = $namespace.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($fileInfo->getPathname(), realpath(app_path()).DIRECTORY_SEPARATOR)
            );

            if ((!$parentClassName || is_subclass_of($className, $parentClassName)) &&
                !app(ReflectionClass::class, ['argument' => $className])->isAbstract()
            ) {
                $class = app($className);
                $register && $register($class);
            }
        }
    }
}

if (!function_exists('relate_models')) {
    /**
     * @param Model $model
     * @param Model $relatedModel
     * @param string $relationName
     * @param bool $suppressEvents
     */
    function relate_models(Model $model, Model $relatedModel, $relationName, $suppressEvents = true)
    {
        /** @var Relations\Relation $relation */
        $relation = $model->$relationName();
        $expectedModel = $relation->getModel();
        if (!($relatedModel instanceof $expectedModel)) {
            $relation = get_class($model)."::$relationName()";
            $expectedModel = get_class($expectedModel);
            $givenModel = get_class($relatedModel);

            throw new InvalidArgumentException("$relation expects the related model to be a $expectedModel."
                ." $givenModel given."
            );
        }

        $save = function (Model $model) {
            return function () use ($model) {
                $model::unguarded(function () use ($model) {
                    $model->save();
                });
            };
        };

        if ($relation instanceof Relations\BelongsTo) {
            $relation->associate($relatedModel);
            $suppressEvents ? $model::withoutEvents($save($model)) : $save($model);
        } elseif ($relation instanceof Relations\HasOneOrMany) {
            $foreignKeyName = $relation->getForeignKeyName();
            $localKeyName = $relation->getLocalKeyName();
            $localKey = $model->getAttribute($localKeyName);
            $relatedModel->$foreignKeyName = $localKey;

            if ($relation instanceof Relations\MorphOneOrMany) {
                $morphTypeField = $relation->getMorphType();
                $morphType = $relation->getMorphClass();
                $relatedModel->$morphTypeField = $morphType;
            }

            $suppressEvents ? $relatedModel::withoutEvents($save($relatedModel)) : $save($relatedModel);

            $relationship = ($relation instanceof Relations\HasMany || $relation instanceof Relations\MorphMany) ?
                app(EloquentCollection::class, ['items' => [$relatedModel]]) : $relatedModel;
            $model->setRelation($relationName, $relationship);
        } elseif ($relation instanceof Relations\BelongsToMany) {
            $relation->attach($relatedModel);
        } else {
            throw new InvalidArgumentException('Unsupported relation type ' . get_class($relation));
        }
    }
}
