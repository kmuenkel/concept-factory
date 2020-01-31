<?php

namespace Concept\Generators;

use Illuminate\Console\Command;

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
     * @void
     */
    public function handle()
    {
        $name = $this->argument('name');
        $class = Concept::findInRegistry($name);

        if (!$class || $this->option('list')) {
            $this->list();
        } else {
            $this->generate($class);
        }
    }

    /**
     * @param string $class
     */
    protected function generate($class)
    {
        /** @var Concept $concept */
        $concept = app($class);
        $model = $concept->create();
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $key = $model->getKey();
        $this->info(PHP_EOL."Created '$table' record where '$keyName' = '$key'.".PHP_EOL);
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
