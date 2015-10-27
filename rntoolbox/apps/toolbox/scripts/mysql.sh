#!/bin/sh
# Script creating web-apps MySQL databases
#
# Parameters
# - First argument:
#    'create': creates MySQL database and user
#    'delete': creates MySQL database and user
# - Second argument:
#    application name

APPNAME=$2
. $(dirname "$0")/utils.sh

MYSQL_DB='`'$APPNAME'`'
MYSQL_USER=$APPNAME
MYSQL_PASS=$APPNAME"31@"

mysqlSharePath=$(rn_nml -g shares | xmllint --xpath "string(//Share[@share-name='mysql']/@id)" -)
appDataDir=/$mysqlSharePath/$APPNAME

_log "MySQL script called with: [$@] by "$(whoami)
case "$1" in
	create)
		# Create database folder
		if [ ! -d "$appDataDir" ]; then
			mkdir -m 700 $appDataDir && chown mysql:mysql $appDataDir
		fi
		# Create the symlink to the current MySQL data folder
		mysqlDataDir=$(mysql -ss -e "SELECT @@datadir;")
		originDataDir=$mysqlDataDir/$APPNAME
		if [ ! -L "$originDataDir" ]; then
			ln -s $appDataDir $originDataDir
		fi
		# Create the MySQL database
		output=$(mysql --defaults-extra-file=/root/.my.cnf -e "CREATE DATABASE IF NOT EXISTS $MYSQL_DB;
			GRANT ALL ON $MYSQL_DB.* TO '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASS';
			FLUSH PRIVILEGES;" 2>&1) || error_exit "MySQL db creation: $output"
		_log "MySQL database created"
	;;

	remove)
		# Delete database (will also delete data dir and symlink if the database exists)
		mysql -e "DROP DATABASE IF EXISTS $MYSQL_DB;";
		# Delete the database folder (if not done on the previous line)
		rm -Rf $appDataDir
		# Delete user
		nbUser=$(mysql -ss -e "SELECT count(USER) FROM mysql.user WHERE User='$MYSQL_USER';")
		if [ $nbUser -gt 0 ]; then
			mysql -e "DROP USER '$MYSQL_USER'@'localhost';"
			_log "$MYSQL_USER: MySQL user deleted"
		fi
		_log "MySQL database deleted"
	;;

	*)
		_log "MySQL script called with an unknown argument '$1'"
		exit 1
	;;
esac

exit 0
