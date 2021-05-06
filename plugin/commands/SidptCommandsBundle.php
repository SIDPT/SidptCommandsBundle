<?php

namespace Sidpt\CommandsBundle;

use Claroline\KernelBundle\Bundle\ExternalPluginBundle;
use Sidpt\CommandsBundle\Installation\AdditionalInstaller;

class SidptCommandsBundle extends ExternalPluginBundle
{

    public function getRequiredPlugins()
    {
        return [
            'Sidpt\\BinderBundle\\SidptBinderBundle',
        ];
    }

    public function hasMigrations():bool
    {
        // Considering the migrations directory is always "{MyVendorBundle}/Installation/Migrations/pdo_mysql"
        $migrationFolder = realpath($this->getPath()."/Installation/Migrations/pdo_mysql");
        return file_exists($migrationFolder)
            && is_readable($migrationFolder)
            && count(array_diff(scandir($migrationFolder), array('..', '.','.DS_Store'))) > 0;
    }
}
