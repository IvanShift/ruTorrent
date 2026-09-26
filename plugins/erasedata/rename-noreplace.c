#define _GNU_SOURCE
#include <fcntl.h>
#include <string.h>
#include <sys/syscall.h>
#include <unistd.h>

#ifndef RENAME_NOREPLACE
#define RENAME_NOREPLACE 1
#endif

/* Move one captured inode back to a public name without replacing an occupant. */
int main(int argc, char **argv)
{
    if (argc != 3 || argv[1][0] != '/' || argv[2][0] != '/'
        || strcmp(argv[1], argv[2]) == 0)
        return 2;
#ifdef SYS_renameat2
    return syscall(SYS_renameat2, AT_FDCWD, argv[1], AT_FDCWD, argv[2],
        RENAME_NOREPLACE) == 0 ? 0 : 1;
#else
    return 1;
#endif
}
