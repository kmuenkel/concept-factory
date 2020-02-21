<?php

namespace Concept\Generators;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Class GeneratorCommand
 * @package Concept\Generators
 */
class ConceptCommand extends Command
{
    protected $signature = 'concept:generate
        {name? : The name of the concept to represent with new database records.}
        {--list : List all possible concepts that can be generated}
        {--v : After generating a concept, list references to all records created rather than just the top-level one}';

    /**
     * @var callable|null
     */
    protected static $eventCallback = null;

    /**
     * @param callable $callback
     */
    public static function setCallback(callable $callback)
    {
        self::$eventCallback = $callback;
    }

    /**
     * @void
     */
    public function handle()
    {
        $name = $this->argument('name');
        $verbose = $this->option('v');
        $class = Concept::findInRegistry($name);

        if (!$class || $this->option('list')) {
            $this->list();
            return;
        }

        $concept = $this->generate($class);
        self::$eventCallback && (self::$eventCallback)($concept);

        $bucket = $concept->getActionLog();
        $actions = $bucket->flushActions();
        $actions = !$verbose ? [array_pop($actions)] : $actions;

        $newRecords = [];
        foreach ($actions as $action) {
            /**
             * @var Model $model
             * @var array $before
             */
            list('model' => $model, 'before' => $before) = $action;
            $action = $before ? 'Updated' : 'Created';
            $table = $model->getTable();
            $keyName = $model->getKeyName();
            $key = $model->getKey();

            if (!in_array("$table@$key", $newRecords)) {
                ($action == 'Created') && $newRecords[] = "$table@$key";
                $logged["$table@$table"] = $action;
                $this->info("$action '$table' record where '$keyName' = '$key'.");
            }
        }
    }

    /**
     * @param $class
     * @return Concept
     */
    protected function generate($class)
    {
        /** @var Concept $concept */
        $concept = app($class);
        $concept->create();

        return $concept;
    }

    /**
     * @void
     */
    protected function list()
    {
        $list = array_keys(Concept::getRegistry());
        $list = implode(PHP_EOL, $list);
        $this->info(PHP_EOL.$list.PHP_EOL);
    }
}
