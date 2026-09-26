#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <signal.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#ifndef __WALL
#define __WALL 0x40000000
#endif

static volatile sig_atomic_t cancel_requested;

static void request_cancel(int signal_number)
{
    (void)signal_number;
    cancel_requested = 1;
}

static bool write_atomic(const char *dir, const char *name, const char *data)
{
    char path[PATH_MAX], temporary[PATH_MAX];
    int fd;
    size_t size = strlen(data), offset = 0;
    if (snprintf(path, sizeof(path), "%s/%s", dir, name) >= (int)sizeof(path)
        || snprintf(temporary, sizeof(temporary), "%s/%s.tmp", dir, name)
            >= (int)sizeof(temporary)) return false;
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC, 0666);
    if (fd < 0) return false;
    if (fchmod(fd, 0666) != 0) goto fail;
    while (offset < size) {
        ssize_t written = write(fd, data + offset, size - offset);
        if (written <= 0) goto fail;
        offset += (size_t)written;
    }
    if (fsync(fd) != 0) goto fail;
    if (close(fd) != 0) {
        fd = -1;
        goto fail;
    }
    fd = -1;
    if (rename(temporary, path) != 0) goto fail;
    /* The task result is a crash-recovery claim, so persist the directory entry. */
    int directory_fd = open(dir, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
    if (directory_fd < 0) {
        unlink(path);
        return false;
    }
#ifdef RTASK_SUPERVISOR_TEST_HOOK
    bool durable = !(strcmp(name, "supervisor.complete") == 0
        && getenv("RTASK_SUPERVISOR_TEST_FAIL_COMPLETE_FSYNC"))
        && fsync(directory_fd) == 0;
#else
    bool durable = fsync(directory_fd) == 0;
#endif
    if (!durable) {
        /* Never leave an authorizing marker after a failed durability check. */
        unlink(path);
        fsync(directory_fd);
    }
    close(directory_fd);
    return durable;
fail:
    if (fd >= 0) close(fd);
    unlink(temporary);
    return false;
}

static void log_failure(const char *dir, const char *message)
{
    char path[PATH_MAX];
    int fd;
    if (snprintf(path, sizeof(path), "%s/errors", dir) >= (int)sizeof(path)) return;
    fd = open(path, O_WRONLY | O_CREAT | O_APPEND | O_CLOEXEC, 0666);
    if (fd >= 0) {
        dprintf(fd, "rtask: supervisor: %s\n", message);
        close(fd);
    }
}

static bool publish_identity(const char *dir)
{
    char boot[128], stat_line[4096], record[4224], pid_text[64], path[128];
    FILE *input = fopen("/proc/sys/kernel/random/boot_id", "r");
    if (!input) return false;
    bool ok = fgets(boot, sizeof(boot), input) != NULL;
    fclose(input);
    if (!ok) return false;
    snprintf(path, sizeof(path), "/proc/%ld/stat", (long)getpid());
    input = fopen(path, "r");
    if (!input) return false;
    ok = fgets(stat_line, sizeof(stat_line), input) != NULL;
    fclose(input);
    if (!ok || snprintf(record, sizeof(record), "%s%s", boot, stat_line)
        >= (int)sizeof(record)) return false;
    snprintf(pid_text, sizeof(pid_text), "%ld\n", (long)getpid());
    return write_atomic(dir, "supervisor.version", "1\n")
        && write_atomic(dir, "pid.identity", record)
        && write_atomic(dir, "pid", pid_text);
}

static int open_pidfd(pid_t pid)
{
    return (int)syscall(SYS_pidfd_open, pid, 0);
}

static bool signal_direct_children(void)
{
    char path[128], *line = NULL, *token, *save = NULL;
    size_t capacity = 0;
    FILE *input;
    bool ok = true;
    snprintf(path, sizeof(path), "/proc/%ld/task/%ld/children",
        (long)getpid(), (long)getpid());
    input = fopen(path, "r");
    if (!input) return false;
    if (getline(&line, &capacity, input) < 0 && !feof(input)) ok = false;
    fclose(input);
#ifdef RTASK_SUPERVISOR_TEST_HOOK
    const char *ready = getenv("RTASK_SUPERVISOR_TEST_READY");
    const char *go = getenv("RTASK_SUPERVISOR_TEST_GO");
    if (ready && go) {
        FILE *marker = fopen(ready, "w");
        if (!marker) ok = false;
        else fclose(marker);
        for (int attempt = 0; ok && attempt < 5000 && access(go, F_OK) != 0; ++attempt)
            usleep(1000);
        if (access(go, F_OK) != 0) ok = false;
    }
#endif
    if (ok && line) {
        for (token = strtok_r(line, " \t\r\n", &save); token;
             token = strtok_r(NULL, " \t\r\n", &save)) {
            char *end;
            long number;
            int fd;
            errno = 0;
            number = strtol(token, &end, 10);
            if (errno || *end || number <= 1 || number > INT_MAX) {
                ok = false;
                break;
            }
            fd = open_pidfd((pid_t)number);
            if (fd < 0) {
                if (errno != ESRCH) ok = false;
                continue;
            }
            /* A reused numeric PID may name an unrelated process after enumeration.
               A pidfd wait is permitted only for this supervisor's own child. */
            siginfo_t child_info = {0};
            if (waitid((idtype_t)3 /* P_PIDFD */, (id_t)fd, &child_info,
                       WEXITED | WNOHANG | WNOWAIT | __WALL) != 0) {
                if (errno != ECHILD) ok = false;
                close(fd);
                continue;
            }
            if (child_info.si_pid == 0
                && syscall(SYS_pidfd_send_signal, fd, SIGKILL, NULL, 0) != 0
                && errno != ESRCH) ok = false;
            close(fd);
        }
    }
    free(line);
    return ok;
}

static bool wait_for_tree(const char *dir, pid_t shell, int *shell_status)
{
    bool shell_reaped = false, signal_failed = false;
    int status;
    while (true) {
        pid_t child;
        if (cancel_requested && !signal_direct_children()) {
            signal_failed = true;
            log_failure(dir, "could not signal every direct child; completion withheld");
        }
        while ((child = waitpid(-1, &status, WNOHANG | __WALL)) > 0) {
            if (child == shell) {
                shell_reaped = true;
                *shell_status = WIFEXITED(status) ? WEXITSTATUS(status) : 1;
            }
        }
        if (child < 0 && errno == ECHILD) {
            if (!shell_reaped) log_failure(dir, "shell was not reaped; completion withheld");
            if (signal_failed) log_failure(dir, "a child signal failed; completion withheld");
            return shell_reaped && !signal_failed;
        }
        if (child < 0 && errno != EINTR) {
            log_failure(dir, "waitpid failed; completion withheld");
            return false;
        }
        struct timespec pause = { .tv_sec = 0, .tv_nsec = 10000000 };
        nanosleep(&pause, NULL);
    }
}

static void mark_notification_failure(const char *dir, const char *reason,
                                      const char *message)
{
    if (!write_atomic(dir, "supervisor.notify-failed", reason))
        log_failure(dir, "could not record notifier failure marker; pending work retained");
    log_failure(dir, message);
}

static bool send_normal_notification(const char *php, const char *notify,
                                     const char *dir, const char *user, int status)
{
    pid_t child = fork();
    if (child == 0) {
        char result[32], errors[PATH_MAX];
        sigset_t empty_mask;
        sigemptyset(&empty_mask);
        sigprocmask(SIG_SETMASK, &empty_mask, NULL);
        snprintf(result, sizeof(result), "%d", status);
        int null = open("/dev/null", O_WRONLY);
        if (null >= 0) {
            dup2(null, STDOUT_FILENO);
            close(null);
        }
        if (snprintf(errors, sizeof(errors), "%s/errors", dir) < (int)sizeof(errors)) {
            int error_fd = open(errors, O_WRONLY | O_CREAT | O_APPEND, 0666);
            if (error_fd >= 0) {
                dup2(error_fd, STDERR_FILENO);
                close(error_fd);
            }
        }
        pid_t notifier = fork();
        if (notifier == 0) {
            char log_option[PATH_MAX + 16];
            if (snprintf(log_option, sizeof(log_option), "error_log=%s/errors", dir)
                >= (int)sizeof(log_option)) _exit(127);
            execlp(php, php, "-d", "log_errors=1", "-d", log_option,
                   notify, result, dir, user, (char *)NULL);
            mark_notification_failure(dir, "exec-failed\n", "notifier exec failed; pending work retained");
            _exit(127);
        }
        if (notifier < 0) {
            mark_notification_failure(dir, "fork-failed\n", "notifier fork failed; pending work retained");
            _exit(127);
        }
        int result_status;
        while (waitpid(notifier, &result_status, 0) < 0) {
            if (errno == EINTR) continue;
            mark_notification_failure(dir, "wait-failed\n", "notifier wait failed; pending work retained");
            _exit(127);
        }
        if (!WIFEXITED(result_status) || WEXITSTATUS(result_status) != 0) {
            if (WIFEXITED(result_status) && WEXITSTATUS(result_status) == 127) {
                /* The exec child recorded its own classified failure. */
                char failed[PATH_MAX];
                if (snprintf(failed, sizeof(failed), "%s/supervisor.notify-failed", dir)
                    < (int)sizeof(failed) && access(failed, F_OK) == 0) _exit(127);
            }
            mark_notification_failure(dir, "exit-failed\n", "notifier exited unsuccessfully; pending work retained");
        }
        _exit(0);
    }
    if (child < 0)
        mark_notification_failure(dir, "fork-failed\n", "notifier monitor fork failed; pending work retained");
    return child > 0;
}

int main(int argc, char **argv)
{
    const char *dir, *php = NULL, *notify = NULL, *user = NULL;
    char script[PATH_MAX], status_path[PATH_MAX], content[32];
    struct sigaction action = { .sa_handler = request_cancel };
    pid_t shell;
    int status = 1;
    FILE *input;
    if (argc != 2 && argc != 5) return 2;
    dir = argv[1];
    if (argc == 5) { php = argv[2]; notify = argv[3]; user = argv[4]; }
    if (snprintf(script, sizeof(script), "%s/start.sh", dir) >= (int)sizeof(script)
        || snprintf(status_path, sizeof(status_path), "%s/status", dir)
            >= (int)sizeof(status_path)) return 2;
    struct sigaction default_child = { .sa_handler = SIG_DFL };
    if (prctl(PR_SET_CHILD_SUBREAPER, 1) != 0
        || sigemptyset(&action.sa_mask) != 0
        || sigaction(SIGCHLD, &default_child, NULL) != 0
        || sigaction(SIGUSR1, &action, NULL) != 0
        || !publish_identity(dir)) {
        log_failure(dir, "could not establish subreaper identity; task refused");
        return 4;
    }
    shell = fork();
    if (shell < 0) {
        log_failure(dir, "could not fork task shell; completion withheld");
        return 4;
    }
    if (shell == 0) {
        struct sigaction normal = { .sa_handler = SIG_DFL };
        sigaction(SIGUSR1, &normal, NULL);
        execl("/bin/sh", "sh", script, (char *)NULL);
        _exit(127);
    }
    if (!wait_for_tree(dir, shell, &status)) return 4;
    input = fopen(status_path, "r");
    if (input) {
        if (fgets(content, sizeof(content), input)) {
            char *end;
            long value = strtol(content, &end, 10);
            if (end != content && (*end == '\n' || *end == '\0') && value >= 0 && value <= 255)
                status = (int)value;
        }
        fclose(input);
    }
    /* Once the tree is gone, the terminal decision cannot be changed by SIGUSR1. */
    sigset_t cancel_signal, previous_mask;
    sigemptyset(&cancel_signal);
    sigaddset(&cancel_signal, SIGUSR1);
    if (sigprocmask(SIG_BLOCK, &cancel_signal, &previous_mask) != 0) {
        log_failure(dir, "could not protect terminal decision; completion withheld");
        return 4;
    }
    bool cancelled = cancel_requested != 0;
    if (cancelled) status = 1;
    snprintf(content, sizeof(content), "%d\n", status);
    if (!cancelled && php && notify && user
        && !write_atomic(dir, "supervisor.notify-pending", "1\n")) {
        log_failure(dir, "could not reserve normal notification; completion withheld");
        return 4;
    }
#ifdef RTASK_SUPERVISOR_TEST_HOOK
    const char *ready = getenv("RTASK_SUPERVISOR_TEST_COMMIT_READY");
    const char *go = getenv("RTASK_SUPERVISOR_TEST_COMMIT_GO");
    if (ready && go) {
        FILE *marker = fopen(ready, "w");
        if (!marker) return 4;
        fclose(marker);
        for (int attempt = 0; attempt < 5000 && access(go, F_OK) != 0; ++attempt)
            usleep(1000);
        if (access(go, F_OK) != 0) return 4;
    }
#endif
    if (!write_atomic(dir, "supervisor.outcome", cancelled ? "cancelled\n" : "normal\n")
        || !write_atomic(dir, "status", content)
        || !write_atomic(dir, "supervisor.complete", "1\n")) {
        log_failure(dir, "could not publish durable completion; task retained");
        return 4;
    }
    if (!cancelled && php && notify && user
        && !send_normal_notification(php, notify, dir, user, status))
        log_failure(dir, "could not launch normal notification; task retained");
    return 0;
}
