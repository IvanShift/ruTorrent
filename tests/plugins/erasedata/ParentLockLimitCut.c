#include <unistd.h>

/* Linked into the production helper by rename-noreplace-test.sh: the helper arms
 * its documented limit and clears it, and no other alarm exists. */
unsigned __real_alarm(unsigned seconds);
unsigned __wrap_alarm(unsigned seconds)
{
    if (seconds != 0 && seconds != 60) _exit(9);
    return __real_alarm(seconds);
}
