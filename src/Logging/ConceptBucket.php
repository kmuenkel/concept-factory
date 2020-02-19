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
     * @param bool $fresh
     * @return static
     */
    public static function make($fresh = false)
    {
        return self::$bucket = (self::$bucket && !$fresh) ? self::$bucket : new static;
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
        $clean = array_intersect_key($dirty, $clean);

        $dirty != $clean && $actions[] = [
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
     * @param bool $suppressEvents
     * @return $this
     */
    public function rollback($suppressEvents = true)
    {
        $actions = array_reverse($this->flushActions());

        foreach ($actions as $action) {
            /**
             * @var Model $model
             * @var array $before
             * @var array $ater
             */
            list($model, $before, $after) = $action;

            $save = function (Model $model) use ($before, $suppressEvents) {
                return function () use ($model, $before, $suppressEvents) {
                    $model::unguarded(function () use ($model, $before, $suppressEvents) {
                        $before ? $model->update($before) : detach_delete($model, true, $suppressEvents);
                    });
                };
            };

            $suppressEvents ? $model::withoutEvents($save($model)) : $save($model);
        }

        return $this;
    }
}
