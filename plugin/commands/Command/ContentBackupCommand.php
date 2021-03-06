<?php

namespace Sidpt\CommandsBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Claroline\CoreBundle\Library\Configuration\PlatformConfigurationHandler;



/**
 * Set/update the hierarchy layout
 */
class ContentBackupCommand extends Command
{

    private $connection;
    private $projectFolder;
    private $configHandler;

    
    public function __construct(
        $connection,
        $projectFolder,
        PlatformConfigurationHandler $configHandler
    ){
        $this->connection = $connection;
        $this->projectFolder = $projectFolder;
        $this->configHandler = $configHandler;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Backup the claroline platform content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $backupDir = $this->configHandler->getParameter('backup.defaultLocation') ?? $this->projectFolder."/backups";
        if(!is_dir($backupDir)) {
            $output->writeln("Creating $backupDir");
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
        
        $backupFileName = "backup_claroline_".$timestamp.".tar.xz";
        $path = sprintf('%s%s%s%s', $dir, DIRECTORY_SEPARATOR, "backup_claroline_", $timestamp);
        $output->writeln("Creating $path");
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
        $output->writeln("Backing up database to $path/db.sql ...");
        $verbose = $output->isVerbose() ? "-v" : "";
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("mysqldump --no-tablespaces -h {$database['host']} --port={$database['port']} -u {$database['user']} -p{$database['password']} {$database['name']}") . " > '$path/db.sql'");
        }
        system(escapeshellcmd("mysqldump --no-tablespaces -h {$database['host']} --port={$database['port']} -u {$database['user']} -p{$database['password']} {$database['name']}") . " > '$path/db.sql'"); #, $dumpOutput, $retval
        
        $folderName = basename($this->projectFolder);
        # backup the claroline files directory in the temp folder
        # cp $verbose -r "$projectFolder/files" "$folder/$(basename $projectFolder)/"
        if(!is_dir("$path/$folderName")){
            mkdir("$path/$folderName");
        }
        $output->writeln("Backing up {$this->projectFolder}/files folder to $path/ ...");
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("cp {$verbose} -r \"{$this->projectFolder}/files\" \"$path/\""));
        }
        system(escapeshellcmd("cp {$verbose} -r \"{$this->projectFolder}/files\" \"$path/\"")); #, $dumpOutput, $retval
        # compress the temp folder (using system tar instead of php extensions to get the )
        
        $output->writeln("Archiving to $path.tar.xz ...");
        if($output->isVerbose()){
            $output->writeln(escapeshellcmd("tar $verbose -C \"$path\" -cJf \"$path.tar.xz\" ."));
        }
        system(escapeshellcmd("tar $verbose -C \"$path\" -cJf \"$path.tar.xz\" .")); #, $dumpOutput, $retval
        # move it to the backups folder
        $output->writeln("Copying to $backupDir ...");
        copy("$path.tar.xz", "$backupDir/".basename("$path.tar.xz") );
        
        # if set, also back it up to a other locations, possibly a remote one
        $otherLocations = $this->configHandler->getParameter('backup.locationsURL') ?? [];
        if(!empty($otherLocations)){
            foreach ($otherLocations as $key => $url) {
                $copyPath = $url.(str_ends_with($url,'/') ? '' : '/').$backupFileName ;
                $output->writeln("Copying to $copyPath ...");
                $scheme = explode(":",$url);
                if(empty(array_intersect(["file", "ssh2.sftp", "ssh2.scp", "ftp", "ftps"],[$scheme]))){
                    if(is_dir($url)){ // Check if the url is a simple path to an existing directory
                        
                        copy("$path.tar.xz",$copyPath);
                    } else {
                        $output->writeln($url." - directory not found or scheme not supported (only file://, ssh2.sftp://, ftp:// and ftps:// schemes are supported)");
                    }
                } else {
                    copy("$path.tar.xz",$copyPath);
                }
                # code...
            }
        }
        $output->writeln("-- Finished -- ");
        return 0;
    }
}