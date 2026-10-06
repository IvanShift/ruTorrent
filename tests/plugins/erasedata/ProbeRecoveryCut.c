#define _GNU_SOURCE
#include <signal.h>
#include <string.h>
#include <unistd.h>

/* Link-time cut only: stop after removing the old lock name while the helper
 * still holds its inode. The production source and syscall results are real. */
int __real_unlinkat(int directory, const char *name, int flags);
int __wrap_unlinkat(int directory, const char *name, int flags)
{
    static int stopped;
    int result = __real_unlinkat(directory, name, flags);
    if (result == 0 && !stopped && strcmp(name, ".lock") == 0) {
        stopped = 1;
        raise(SIGSTOP);
    }
    return result;
}
