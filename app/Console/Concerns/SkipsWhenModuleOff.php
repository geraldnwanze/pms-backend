<?php

namespace App\Console\Concerns;

use App\Support\Modules;

/** A scheduled command that belongs to a module does nothing while that module is turned off. */
trait SkipsWhenModuleOff
{
    protected function moduleIsOff(string $module): bool
    {
        if (app(Modules::class)->enabled($module)) {
            return false;
        }

        $this->info('Skipped: the '.Modules::DEFINITIONS[$module]['label'].' module is turned off.');

        return true;
    }
}
