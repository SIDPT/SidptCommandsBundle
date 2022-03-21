#!/bin/bash

# retrieve the production server state to dev server

# Parsing args code from https://stackoverflow.com/a/29754866

# More safety, by turning some bugs into errors.
# Without `errexit` you don’t need ! and can replace
# PIPESTATUS with a simple $?, but I don’t do that.
set -o errexit -o pipefail -o noclobber -o nounset

realpath() (
  OURPWD=$PWD
  cd "$(dirname "$1")"
  LINK=$(readlink "$(basename "$1")")
  while [ "$LINK" ]; do
    cd "$(dirname "$LINK")"
    LINK=$(readlink "$(basename "$1")")
  done
  REALPATH="$PWD/$(basename "$1")"
  cd "$OURPWD"
  echo "$REALPATH"
)

SCRIPT=`realpath $0`
SCRIPTPATH=`dirname $SCRIPT`

productionSudoer="npavie"
productionWebUser="www"
productionSSHRemote="braillenet.org"
productionSSHPort="22017"
productionBackupScript="/var/webapp/backup-claroline.sh"
productionBackupFolder="/var/webapp/backups"
# assuming SidptCommandsBundle folder is on the same level that Claroline
# folder on dev machine
devDestinationFolder="$SCRIPTPATH/../../../backups"
devInstallFolder="$SCRIPTPATH/../../../Claroline"
database="claroline"

sshCommand="ssh ${productionSudoer}@${productionSSHRemote} -p $productionSSHPort"

echo "backing up and retrieve Claroline resource files and database"
# launch backup script on remote
eval $sshCommand "sudo $productionBackupScript"
lastBackupName=$(eval $sshCommand "ls -Art $productionBackupFolder | tail -n 1")
# retrieve backup : change owner on backup, copy backup, reset owner
eval $sshCommand "sudo chown $productionSudoer ${productionBackupFolder}/${lastBackupName}"
scp -P $productionSSHPort \
  ${productionSudoer}@${productionSSHRemote}:${productionBackupFolder}/${lastBackupName} \
  $devDestinationFolder/$lastBackupName
eval $sshCommand "sudo chown $productionWebUser ${productionBackupFolder}/${lastBackupName}"
#untar the result
echo "Extracting backup..."
rm -rf ${devDestinationFolder}/Claroline
rm ${devDestinationFolder}/db.sql
tar xf $devDestinationFolder/$lastBackupName -C $devDestinationFolder

echo "Reloading database..."
sudo mysql -e "DROP DATABASE $database ; CREATE DATABASE $database ;"
sudo mysql $database < $devDestinationFolder/db.sql
echo "Syncing Claroline folders..."
rsync -avzh --ignore-errors $devDestinationFolder/Claroline/ $devInstallFolder

#replace production url to dev url
sed -i '' 's,https:\\/\\/sidpt.braillenet.org,http:\\/\\/localhost:8000,g'  $devInstallFolder/files/config/platform_options.json
sed -i '' 's,sidpt.braillenet.org,localhost:8000,g'  $devInstallFolder/files/config/platform_options.json

echo "Don't forget to deactivate the ssl in the platform $devInstallFolder/files/config/platform_options.json"
