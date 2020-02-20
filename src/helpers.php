<?php

use Symfony\Component\Finder\Finder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

if (!function_exists('register_class')) {
    /**
     * @param array|string $paths
     * @param string $parentClassName
     * @param callable|null $register
     * @return object[]
     */
    function register_classes($paths, $parentClassName = null, callable $register = null)
    {
        $classNames = get_classes($paths, $parentClassName);
        $objects = [];

        foreach ($classNames as $className) {
            $objects[$className] = $object = new $className;
            $register($object);
        }

        return $objects;
    }
}

if (!function_exists('get_classes')) {
    /**
     * Register all of the commands in the given directory.
     *
     * @param array|string $paths
     * @param string $parentClassName
     * @return string[]
     */
    function get_classes($paths, $parentClassName = null)
    {
        $paths = array_unique((array)$paths);
        $namespace = get_namespace();
        $appPath = realpath(app_path()).DIRECTORY_SEPARATOR;
        $delimiter = '/';
        $pattern = preg_quote($appPath, $delimiter);
        $files = (new Finder)->in($paths)->files();
        $classNames = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($files as $fileInfo) {
            $fullPath = $fileInfo->getPathname();
            $fileName = preg_replace("$delimiter^$pattern$delimiter", '', $fullPath);
            $className = $namespace.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $fileName);

            try {
                $isAbstract = (new ReflectionClass($className))->isAbstract();
            } catch (ReflectionException $e) {
                $isAbstract = false;
            }

            if ((!$parentClassName || is_subclass_of($className, $parentClassName)) && !$isAbstract) {
                $classNames[] = $className;
            }
        }

        return $classNames;
    }
}

if (!function_exists('app_path')) {
    /**
     * @param string $path
     * @return string
     */
    function app_path($path = '')
    {
        $basePath = base_path() . DIRECTORY_SEPARATOR . 'app';
        $path = $path ? DIRECTORY_SEPARATOR.$path : $path;
        $fullPath = $basePath.$path;

        return $fullPath;
    }
}

if (!function_exists('base_path')) {
    /**
     * @param string $path
     * @return string
     */
    function base_path($path = '')
    {
        $basePath = $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__, 4);
        $path = $path ? DIRECTORY_SEPARATOR . $path : $path;
        $fullPath = $basePath . $path;

        return $fullPath;
    }
}

if (!function_exists('get_namespace')) {
    /**
     * @return string
     * @see Illuminate\Foundation\Application::getNamespace()
     */
    function get_namespace()
    {
        $composerJsonPath = base_path('composer.json');
        $composerJsonContent = file_get_contents($composerJsonPath);
        $composerJson = $composerJsonContent ? json_decode($composerJsonContent) : (object)[];
        $autoload = $composerJson->autoload ?? (object)[];
        $psr4 = $autoload->{'psr-4'} ?? [];

        foreach ($psr4 as $namespace => $path) {
            foreach ((array)$path as $pathChoice) {
                if (realpath(app_path()) === realpath(base_path($pathChoice))) {
                    return $namespace;
                }
            }
        }

        return '\\';
    }
}

if (!function_exists('relate_models')) {
    /**
     * @param Model $model
     * @param Model $relatedModel
     * @param string $relationName
     */
    function relate_models(Model $model, Model $relatedModel, $relationName)
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

        if ($relation instanceof Relations\BelongsTo) {
            $relation->associate($relatedModel);
            $model->save();
        } elseif ($relation instanceof Relations\HasOneOrMany) {
            $relation->save($relatedModel);

            $relationships = $model->relationLoaded($relationName) ? $model->getRelation($relationName)
                : new EloquentCollection;
            $relationships = ($relationships instanceof Model) ? new EloquentCollection([$relationships])
                : $relationships;
            $relationships->push($relatedModel);

            $relation->match([$model], $relationships, $relationName);
        } elseif ($relation instanceof Relations\BelongsToMany) {
            $relation->attach($relatedModel);
        } else {
            throw new InvalidArgumentException('Unsupported relation type ' . get_class($relation));
        }
    }
}

if (!function_exists('detach_delete')) {
    /**
     * @param Model $model
     * @param bool $forceDelete
     */
    function detach_delete(Model $model, $forceDelete = false)
    {
        $relationNames = array_keys($model->getRelations());

        foreach ($relationNames as $relationName) {
            /** @var Relations\Relation $relation */
            $relation = $model->$relationName();

            if ($relation instanceof Relations\BelongsToMany) {
                $relation->sync([]);
            }

            $forceDelete ? $model->forceDelete() : $model->delete();
        }
    }
}
