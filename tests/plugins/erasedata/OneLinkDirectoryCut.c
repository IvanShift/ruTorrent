#define _GNU_SOURCE
#include <fcntl.h>
#include <sys/stat.h>

/* Link-time cut only: report every directory with st_nlink=1, as btrfs and
 * the WSL2 drvfs over 9p behind Docker Desktop's Windows drives do (measured
 * on the owner's host 2026-10-11). The production source and syscalls are real. */
int __real_fstatat(int directory, const char *name, struct stat *entry, int flags);
int __wrap_fstatat(int directory, const char *name, struct stat *entry, int flags)
{
    int result = __real_fstatat(directory, name, entry, flags);
    if (result == 0 && S_ISDIR(entry->st_mode)) entry->st_nlink = 1;
    return result;
}

int __real_fstat(int descriptor, struct stat *entry);
int __wrap_fstat(int descriptor, struct stat *entry)
{
    int result = __real_fstat(descriptor, entry);
    if (result == 0 && S_ISDIR(entry->st_mode)) entry->st_nlink = 1;
    return result;
}
