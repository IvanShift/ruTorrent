#define _GNU_SOURCE
#include <signal.h>
#include <sys/stat.h>
#include <unistd.h>

/* Linked into the helper by rename-noreplace-test.sh. Whoever starts the helper
 * hands it their signal mask, so it starts here with SIGALRM blocked. */
__attribute__((constructor)) static void start_with_alarm_blocked(void)
{
    sigset_t alarm_signal;
    sigemptyset(&alarm_signal);
    sigaddset(&alarm_signal, SIGALRM);
    sigprocmask(SIG_BLOCK, &alarm_signal, NULL);
}

/* The workspace's mkdirat is the first step after the parent's lock is obtained:
 * no alarm may still be pending, since a signal can interrupt a slow filesystem. */
int __real_mkdirat(int directory, const char *name, mode_t mode);
int __wrap_mkdirat(int directory, const char *name, mode_t mode)
{
    if (alarm(0) != 0) _exit(9);
    return __real_mkdirat(directory, name, mode);
}
