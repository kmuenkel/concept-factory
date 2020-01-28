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
        {name : The name of the concept to represent with new database records.}
        {--list : List all possible concepts that can be generated}';

    /**
     * @void
     */
    public function handle()
    {
        $name = $this->argument('name');
        $class = Concept::findInRegistry($name);

        if (!$class || $this->argument('list')) {
            $this->list();
        }

        $this->generate($class);
    }

    /**
     * @param string $class
     */
    protected function generate($class)
    {
        $concept = app($class);
        $concept->create();
        $models = $concept->getModelLibrary();
        $this->info(PHP_EOL.'Done.'.PHP_EOL);
    }

    /**
     * @void
     */
    protected function list()
    {
        $list = Concept::getRegistry();
        $list = implode(PHP_EOL, $list);
        $this->info(PHP_EOL.$list.PHP_EOL);
    }
}
