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
        {--list : List all possible concepts that can be generated}';

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
        $class = Concept::findInRegistry($name);

        if (!$class || $this->option('list')) {
            $this->list();
            return;
        }

        $concept = $this->generate($class);
        $model = $concept->getModel();
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        self::$eventCallback && (self::$eventCallback)($concept);
        $this->info(PHP_EOL."Created '$table' record where '$keyName' = '$key'.".PHP_EOL);
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
