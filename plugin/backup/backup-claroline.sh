#!/bin/bash
# Parsing args code from https://stackoverflow.com/a/29754866
# More safety, by turning some bugs into errors.
# Without `errexit` you don’t need ! and can replace
# PIPESTATUS with a simple $?, but I don’t do that.
set -o errexit -o pipefail -o noclobber -o nounset

# Default values
folder="$(mktemp -d)/backup_claroline_$(date +%Y%m%d%H%M%S)"
installFolder="/path/to/Claroline"
database="claroline"
destination="/path/to/backups"
verbose=
full=0
_help=0
keep_folder=0
webUser=www

# to be defined based on backup server
remoteHost= #192.168.1.2
remotePort= #12345
remoteUser= #backup
remotePath= #"~/ipip/"

OPTIONS=i:o:d:b:fvh
LONGOPTS=input:,output:,destination:,database:,full,verbose,help

if [[ $# -gt 0 ]]; then # options are provided

   ! PARSED=$(getopt --options=$OPTIONS --longoptions=$LONGOPTS --name "$0" -- "$@")
   if [[ ${PIPESTATUS[0]} -ne 0 ]]; then
       echo "An error occured while parsing options"
       exit 2
   fi
   eval set -- "$PARSED"

   while true; do
       case "$1" in
           -i|--input)
               installFolder="$2"
               shift 2
               ;;
           -o|--output)
               folder="$2"
          keep_folder=1
               shift 2
               ;;
           -v|--verbose)
               verbose=-v
               shift
               ;;
           -d|--destination)
               destination="$2"
               shift 2
               ;;
      -b|--database)
          database="$2"
          shift 2
          ;;
      -f|--full)
          full=1
          shift
          ;;
      -h|--help)
          _help=1
          shift
          ;;
           --)
               shift
               break
               ;;
           *)
               echo "$1 is not a valid option, please remove it and relaunch the command"
               exit 1
               ;;
       esac
   done
fi

# add full qualifier on folder name
if [[ $full -ne 0 ]]; then
    folder="${folder}_full"
fi

if [[ $_help -ne 0 ]]; then
    echo "Backing-up script for the IPIP platform based on Claroline LMS. By default, the script only backup the database."
    echo "(This script needs sudo rights)"
    echo "Options:"
    echo "-h | --help : display this message"
    echo "-i | --input : changes the Claroline distribution folder to backup"
    echo "-o | --output : changes the folder to use for files copy before bziping. Note that it also changes the archive name."
    echo "-d | --destination : changes the folder where the backup will be store"
    echo "-v | --verbose : display everything that is done by backing commands"
    echo "-f | --full : do a full backup, including claroline files"
    echo " "
    echo "Backed folder : $installFolder"
    echo "Backed database : $database"
    echo "Zipping folder : $folder"
    echo "Backups folder : $destination"
    echo "verbose option \(-v means yes\): $verbose"
    echo "full backup option \(1 for full, 0 for database only\) : $full"
    echo "Keeping zipping folder on disk when finished \(1 means yes\): $keep_folder"
    exit 0;
fi

mkdir "$folder"
if [ -d "$folder" ]
then
   echo "Backing up local app and database"
   echo "- Backing database into $folder/db.sql ..."
   mysqldump claroline > "$folder/db.sql"
   if [[ $full -ne 0 ]]; then
       echo "- Fully backing $installFolder into to $folder ..."
       cp $verbose -r "$installFolder" "$folder/"
   else
            echo "- Backing files and config of $installFolder into $folder/$(basename $installFolder)"
       mkdir -p "$folder/$(basename $installFolder)"
       cp $verbose -r "$installFolder/files" "$folder/$(basename $installFolder)/"
       cp $verbose -r "$installFolder/config" "$folder/$(basename $installFolder)/"
   fi
   echo "- Compressing $folder content into to $folder.tar.xz ..."
   tar $verbose -C "$folder" -cJf "$folder.tar.xz" .
   echo "- Moving to $folder.tar.xz to $destination"
   mv $verbose "$folder.tar.xz" "$destination/"
   if [[ $keep_folder -ne 1 ]]; then
       echo "- Removing folder $folder"
       rm $verbose -rf "$folder"
   fi
   if [[ ! -z "$remoteHost" ]]
   then
      # atempt to do remote backup of the archive
      echo "- Backing up also on secondary remote server"
      scp "$destination/$(basename $folder).tar.xz" $remoteUser@$remoteHost:$remotePath
   fi
   echo "- Updating archive rights" 
   chown $webUser "$destination/$(basename $folder).tar.xz"
   # protecting the backup from accidental removal
   chmod 440 "$destination/$(basename $folder).tar.xz"
   echo "Done"
   exit 0
else
   exit 1
fi

