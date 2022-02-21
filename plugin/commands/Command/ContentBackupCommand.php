<?php

namespace Sidpt\CommandsBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;



/**
 * Set/update the hierarchy layout
 */
class ContentBackupCommand extends Command
{

    private $connection;
    private $projectFolder;
    
    public function __construct(
        $connection,
        $projectFolder
    ){
        $this->connection = $connection;
        $this->projectFolder = $projectFolder;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Backup the claroline platform content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $backupDir = $this->projectFolder."/backups";
        if(!is_dir($backupDir)) {
            mkdir($backupDir);
        }


        $timestamp = date("YmdHis");
        # create a temp folder as $folder="$(mktemp -d)/backup_claroline_$(date +%Y%m%d%H%M%S)"
        $dir = sys_get_temp_dir();
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);

        if (!is_dir($dir) || !is_writable($dir))
        {
            return 1;
        }

        $path = sprintf('%s%s%s%s', $dir, DIRECTORY_SEPARATOR, "backup_claroline_", $timestamp);
        mkdir($path, 0700);
        
        # dump the database to the temp folder
        # to be retrieved from parameters
        #  params = yaml_parse_file("path/to/config/parameters.yml")
        $database = [
            'host' => $this->connection->getHost() ?? 'localhost',
            'port' => $this->connection->getPort() ?? '3306',
            'user' => $this->connection->getUsername() ?? 'claroline',
            'password' => $this->connection->getPassword() ?? 'claroline',
            'name' => $this->connection->getDatabase() ?? 'claroline'
        ];
        
        $verbose = $output->isVerbose() ? "-v" : "";
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("mysqldump -h {$database['host']} --port={$database['port']} -u {$database['user']} -p{$database['password']} {$database['name']}") . " > '$path/db.sql'");
        }
        system(escapeshellcmd("mysqldump -h {$database['host']} --port={$database['port']} -u {$database['user']} -p{$database['password']} {$database['name']}") . " > '$path/db.sql'"); #, $dumpOutput, $retval
        
        $folderName = basename($this->projectFolder);
        # backup the claroline files directory in the temp folder
        # cp $verbose -r "$projectFolder/files" "$folder/$(basename $projectFolder)/"
        if(!is_dir("$path/$folderName")){
            mkdir("$path/$folderName");
        }
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("cp {$verbose} -r \"{$this->projectFolder}/files\" \"$path/\""));
        }
        system(escapeshellcmd("cp {$verbose} -r \"{$this->projectFolder}/files\" \"$path/\"")); #, $dumpOutput, $retval
        # compress the temp folder (using system tar instead of php extensions to get the )
        
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("tar $verbose -C \"$path\" -cJf \"$path.tar.xz\" ."));
        }
        system(escapeshellcmd("tar $verbose -C \"$path\" -cJf \"$path.tar.xz\" .")); #, $dumpOutput, $retval
        # move it to the backups folder
        rename("$path.tar.xz", "$backupDir/".basename("$path.tar.xz") );
        
        # if set, also backit up to a secondary location, possibly a remote one
        return 0;
    }
}