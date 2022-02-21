<?php

namespace Sidpt\CommandsBundle\Command;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Claroline\InstallationBundle\Command\PlatformUpdateCommand;

use Symfony\Component\Console\Input\ArrayInput;

/**
 * Set/update the hierarchy layout
 */
class ContentRestoreCommand extends Command
{

    private $connection;
    private $projectFolder;
    private $updateCommand;

    public function __construct(
        $connection,
        $projectFolder,
        PlatformUpdateCommand $updateCommand
    ){
        $this->connection = $connection;
        $this->projectFolder = $projectFolder;
        $this->updateCommand = $updateCommand;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Restore the claroline platform content from an archive')
            ->addArgument('backup_path', InputArgument::REQUIRED, 'path of the archive to restore');
        $this->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                'Set to true for debug environment'
            );   
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDebug = $input->getOption('debug') ?? false;
        $backup_path = $input->getArgument('backup_path');
        $verbose = $output->isVerbose() ? "-v" : "";
        # Create a temp folder for restoration
        $timestamp = date("YmdHis");
        $dir = sys_get_temp_dir();
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir) || !is_writable($dir))
        {
            return 1;
        }
        $path = sprintf('%s%s%s%s', $dir, DIRECTORY_SEPARATOR, "restore_claroline_", $timestamp);
        mkdir($path, 0700);

        # untar archive
        system(escapeshellcmd("tar xf $backup_path -C $path")); #, $dumpOutput, $retval
        # check if the archive contains a db.sql and a files folder
        if(!(is_dir("$path/files") && is_file("$path/db.sql"))){
            $output->writeln("backup archive seems invalid (no db.sql file and/or files folder present in it");
            return 1;
        }
        # then 
        # - Restore the database
        $database = [
            'host' => $this->connection->getHost() ?? 'localhost',
            'port' => $this->connection->getPort() ?? '3306',
            'user' => $this->connection->getUsername() ?? 'claroline',
            'password' => $this->connection->getPassword() ?? 'claroline',
            'name' => $this->connection->getDatabase() ?? 'claroline'
        ];
        $output->writeln("Restore database ...");
        $mysqlCommand = escapeshellcmd("mysql $verbose -h {$database['host']} --port={$database['port']} -u {$database['user']} -p{$database['password']} ");
        system( $mysqlCommand . " -e \"DROP DATABASE {$database['name']}; CREATE DATABASE {$database['name']};\""); #, $dumpOutput, $retval
        system( $mysqlCommand . " {$database['name']} < '$path/db.sql'"); #, $dumpOutput, $retval

        # - Restore the file folder
        $output->writeln("Restore file ...");
        system(escapeshellcmd("cp $verbose -r \"$path/files\" \"{$this->projectFolder}/files\"")); #, $dumpOutput, $retval
        # - if possible :
        #   - launch the claroline update command
        $output->writeln("Launch platform update ...");
        $updateInput = new ArrayInput([]);
        $this->updateCommand->run($updateInput,$output);
        #   - launch npm install --legacy-peer-deps
        system(escapeshellcmd("cd {$this->projectFolder} && npm install --legacy-peer-deps")); #, $dumpOutput, $retval
        #   - if env is prod, launch npm run webpack
        if(!$isDebug){
            $output->writeln("Rebuild frontend ...");
            system(escapeshellcmd("cd {$this->projectFolder} && npm run webpack")); #, $dumpOutput, $retval
        }
        #   - clear the cache folder
        $output->writeln("Clear the cache ...");
        system(escapeshellcmd("rm -rf \"{$this->projectFolder}/var/cache/*\"")); #, $dumpOutput, $retval
        # - If not possible to launch one of the previous command :
        #   - Ask the user to do a composer update -o (--no-dev for production)

        return 0;
    }
}