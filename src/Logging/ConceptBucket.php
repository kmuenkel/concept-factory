<?php

namespace Concept\Logging;

use Illuminate\Database\Eloquent\Model;

/**
 * Class ConceptBucket
 * @package Concept\Logging
 */
class ConceptBucket
{
    /**
     * @var static
     */
    protected static $bucket = null;

    /**
     * @var array[]
     */
    protected $actions = [];

    /**
     * ConceptBucket constructor.
     */
    protected function __construct()
    {
        self::$bucket = $this;
    }

    /**
     * @return static
     */
    public static function make()
    {
        return self::$bucket ?: new static;
    }

    /**
     * @param Model $model
     * @param array|null $before
     * @param array|null $after
     * @return $this
     */
    public function addAction(Model $model, array $before = null, array $after = null)
    {
        $dirty = !is_null($after) ? $after : $model->getDirty();
        $clean = !is_null($before) ? $before : $model->getOriginal();

        $dirty = array_diff_assoc($dirty, $clean);
        $clean = array_intersect_key($clean, $dirty);

        $dirty != $clean && $this->actions[] = [
            'model' => $model,
            'before' => $clean,
            'after' => $dirty
        ];

        return $this;
    }

    /**
     * @param array $actions
     * @return $this
     */
    public function setActions(array $actions)
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @return array[]
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * @return array[]
     */
    public function flushActions()
    {
        $actions = $this->getActions();
        $this->setActions([]);

        return $actions;
    }

    /**
     * @return $this
     */
    public function rollback()
    {
        $actions = array_reverse($this->flushActions());

        foreach ($actions as $action) {
            /**
             * @var Model $model
             * @var array $before
             */
            list('model' => $model, 'before' => $before) = $action;

            $model::unguarded(function () use ($model, $before) {
                $before ? $model->update($before) : detach_delete($model, true);
            });
        }

        return $this;
    }
}
