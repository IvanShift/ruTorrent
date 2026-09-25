#!/bin/sh
# Validate the task shell's boot and birth tick in the process that signals it.
# Numeric kill still has a small PID reuse race; Linux pidfd would remove it.
set -f
pid=$1
identity=$2
case $pid in
	''|*[!0-9]*) exit 3 ;;
esac
[ "$pid" -gt 1 ] 2>/dev/null || exit 3
[ -r "$identity" ] || exit 3

# /proc/<pid>/stat field 22 is the birth tick. Split after the last ') '
# because comm can itself contain spaces and closing parentheses.
parse_stat()
{
	[ "${2%% *}" = "$1" ] || return 1
	rest=${2##*) }
	[ "$rest" != "$2" ] || return 1
	set -- $rest
	[ "$#" -ge 20 ] || return 1
	case ${20} in
		''|*[!0-9]*) return 1 ;;
	esac
	start_tick=${20}
	parent_pid=$2
}

exec 3< "$identity" || exit 3
IFS= read -r recorded_boot <&3 || exit 3
IFS= read -r recorded_stat <&3 || exit 3
exec 3<&-
parse_stat "$pid" "$recorded_stat" || exit 3
recorded_tick=$start_tick

matches_parent()
{
	IFS= read -r live_boot < /proc/sys/kernel/random/boot_id || return 1
	[ "$live_boot" = "$recorded_boot" ] || return 1
	IFS= read -r live_stat < "/proc/$pid/stat" || return 1
	parse_stat "$pid" "$live_stat" || return 1
	[ "$start_tick" = "$recorded_tick" ]
}

matches_parent || exit 3
children_path="/proc/$pid/task/$pid/children"
[ -r "$children_path" ] || exit 3
children=
IFS= read -r children < "$children_path" || :

# /proc/.../children is empty for a childless task. Never pass an empty
# expansion to kill; each listed child is checked against its parent.
for child in $children; do
	case $child in
		''|*[!0-9]*) continue ;;
	esac
	[ "$child" -gt 1 ] 2>/dev/null || continue
	matches_parent || exit 4
	IFS= read -r child_stat < "/proc/$child/stat" || continue
	parse_stat "$child" "$child_stat" || continue
	[ "$parent_pid" = "$pid" ] || continue
	kill -9 "$child" 2>/dev/null || :
done
matches_parent || { [ ! -e "/proc/$pid" ] && exit 0; exit 4; }
kill -9 "$pid" 2>/dev/null || { [ ! -e "/proc/$pid" ] && exit 0; exit 4; }
