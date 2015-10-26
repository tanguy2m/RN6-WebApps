#!/bin/sh
# Utilities script to be included in packages routines

LOGFILE=/apps/toolbox/log
_log() {
	if [ ! -f "$LOGFILE" ]; then
		touch ${LOGFILE}
		chown admin:admin ${LOGFILE}
	fi
	msg="$(date +'%Y/%m/%d %H:%M:%S') [$APPNAME] $*"
	echo "$msg" >> ${LOGFILE}
}

error_exit() {
	_log "$1"
	exit 1
}
