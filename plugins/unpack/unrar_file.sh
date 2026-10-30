#!/bin/sh
#
# $1 - unrar
# $2 - archive
# $3 - output directory with tail slash
# $5 - archive files to delete
# $6 - unpack temp dir
# $7-$10 - for place.php, see stage.sh

. "$(dirname "$0")/stage.sh"

# -ol- skips symbolic links. unrar 7.23 accepts a chain of relative links
# one at a time that together lead out of its directory, and follows the
# chain for a hard link and for a file then written over that link.
unpack_rar_links "$1" "$2"
unpack_extract "" "$1" x -ai -c- -kb -o+ -ol- -p- -y -v -- "$2"
ret=$?

if [ $ret -eq 0 ] && [ "$5" != '' ] ; then
	rm "$5"
fi

exit $ret
