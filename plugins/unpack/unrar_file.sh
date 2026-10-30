#!/bin/sh
#
# $1 - unrar
# $2 - archive
# $3 - output directory with tail slash
# $5 - archive files to delete
# $6 - unpack temp dir
# $7-$10 - for place.php, see stage.sh

. "$(dirname "$0")/stage.sh"

# -ol-: unrar creates no symbolic links. stage.sh lists them first and
# refuses an archive that holds one, as for zip.
unpack_rar_links "$1" "$2"
unpack_extract "" "$1" x -ai -c- -kb -o+ -ol- -p- -y -v -- "$2"
ret=$?

if [ $ret -eq 0 ] && [ "$5" != '' ] ; then
	rm "$5"
fi

exit $ret
