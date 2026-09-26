#define _GNU_SOURCE
#include <errno.h>
#include <limits.h>
#include <poll.h>
#include <signal.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <unistd.h>

/* Exit 3: identity refusal; exit 4: signal state uncertain. No numeric kill(2). */
struct process_identity {
    pid_t pid;
    pid_t parent;
    unsigned long long start_tick;
};

static bool parse_pid(const char *text, pid_t *pid)
{
    char *end;
    long value;
    if (!text || !*text) return false;
    for (const char *p = text; *p; ++p) {
        if (*p < '0' || *p > '9') return false;
    }
    errno = 0;
    value = strtol(text, &end, 10);
    if (errno || *end || value <= 1 || value > INT_MAX) return false;
    *pid = (pid_t)value;
    return true;
}

static bool parse_stat(char *line, struct process_identity *identity)
{
    char *end;
    char *closing = strrchr(line, ')');
    char *field;
    char *save = NULL;
    long value;
    int index = 0;
    if (!closing || closing[1] != ' ' || closing[2] == '\0') return false;
    errno = 0;
    value = strtol(line, &end, 10);
    if (errno || end == line || *end != ' ' || value <= 1 || value > INT_MAX)
        return false;
    identity->pid = (pid_t)value;
    for (field = strtok_r(closing + 2, " \t\r\n", &save);
         field; field = strtok_r(NULL, " \t\r\n", &save), ++index) {
        if (index == 1) {
            errno = 0;
            value = strtol(field, &end, 10);
            if (errno || end == field || *end || value < 0 || value > INT_MAX)
                return false;
            identity->parent = (pid_t)value;
        }
        if (index == 19) {
            errno = 0;
            identity->start_tick = strtoull(field, &end, 10);
            return !errno && end != field && *end == '\0';
        }
    }
    return false;
}

static bool read_stat(pid_t pid, struct process_identity *identity)
{
    char path[64];
    char line[4096];
    FILE *input;
    snprintf(path, sizeof(path), "/proc/%ld/stat", (long)pid);
    input = fopen(path, "r");
    if (!input) return false;
    bool ok = fgets(line, sizeof(line), input) && parse_stat(line, identity)
        && identity->pid == pid;
    fclose(input);
    return ok;
}

static bool read_recorded(const char *path, pid_t pid,
                          struct process_identity *identity)
{
    char boot[128], current_boot[128], line[4096];
    FILE *input = fopen(path, "r");
    FILE *current;
    bool ok;
    if (!input) return false;
    ok = fgets(boot, sizeof(boot), input) && fgets(line, sizeof(line), input)
        && parse_stat(line, identity) && identity->pid == pid;
    fclose(input);
    if (!ok) return false;
    current = fopen("/proc/sys/kernel/random/boot_id", "r");
    if (!current) return false;
    ok = fgets(current_boot, sizeof(current_boot), current)
        && strcmp(boot, current_boot) == 0;
    fclose(current);
    return ok;
}

static int open_pidfd(pid_t pid)
{
    return (int)syscall(SYS_pidfd_open, pid, 0);
}

/* A pidfd stays attached to its original process when its numeric PID is reused. */
static int send_kill(int fd, bool allow_gone)
{
    if (syscall(SYS_pidfd_send_signal, fd, SIGKILL, NULL, 0) == 0)
        return 0;
    if (allow_gone && errno == ESRCH) return 0;
    return 4;
}

static int has_exited(int fd)
{
    struct pollfd item = { .fd = fd, .events = POLLIN };
    int result = poll(&item, 1, 0);
    if (result < 0) return -1;
    return result > 0 && (item.revents & (POLLIN | POLLHUP | POLLERR));
}

#ifdef RTASK_KILL_TEST_HOOK
static void pause_for_test(const char *ready_name, const char *go_name)
{
    const char *ready = getenv(ready_name);
    const char *go = getenv(go_name);
    if (!ready || !go) return;
    FILE *marker = fopen(ready, "w");
    if (!marker) exit(4);
    fclose(marker);
    for (int i = 0; i < 2000 && access(go, F_OK) != 0; ++i)
        usleep(1000);
    if (access(go, F_OK) != 0) exit(4);
}
#endif

int main(int argc, char **argv)
{
    pid_t pid;
    struct process_identity recorded, live;
    char path[96];
    char *children = NULL, *child, *save = NULL;
    size_t capacity = 0;
    FILE *input;
    int parent_fd, status = 0;
    if (argc != 3 || !parse_pid(argv[1], &pid)
        || !read_recorded(argv[2], pid, &recorded)) return 3;

    /* Open before reading /proc: all later signals target this exact process. */
    parent_fd = open_pidfd(pid);
    if (parent_fd < 0) return errno == ESRCH ? 3 : 4;
    if (!read_stat(pid, &live) || live.start_tick != recorded.start_tick) {
        close(parent_fd);
        return 3;
    }
    status = has_exited(parent_fd);
    if (status != 0) {
        close(parent_fd);
        return 4; /* Descendants may survive an unobserved parent exit. */
    }
#ifdef RTASK_KILL_TEST_HOOK
    pause_for_test("RTASK_KILL_TEST_READY", "RTASK_KILL_TEST_GO");
#endif
    snprintf(path, sizeof(path), "/proc/%ld/task/%ld/children",
             (long)pid, (long)pid);
    input = fopen(path, "r");
    if (!input) {
        close(parent_fd);
        return 4;
    }
    if (getline(&children, &capacity, input) < 0 && !feof(input)) {
        fclose(input);
        free(children);
        close(parent_fd);
        return 4;
    }
    fclose(input);
    if (children) {
        for (child = strtok_r(children, " \t\r\n", &save); child;
             child = strtok_r(NULL, " \t\r\n", &save)) {
            pid_t child_pid;
            struct process_identity child_identity;
            int child_fd;
            if (!parse_pid(child, &child_pid) || child_pid == pid) continue;
            child_fd = open_pidfd(child_pid);
            if (child_fd < 0) {
                if (errno == ESRCH) continue;
                status = 4;
                break;
            }
            if (!read_stat(child_pid, &child_identity)
                || child_identity.parent != pid
                || child_identity.start_tick < recorded.start_tick) {
                close(child_fd);
                continue;
            }
            /* The parent must still be the pinned process when parentage is read. */
            int exited = has_exited(parent_fd);
            if (exited != 0) {
                close(child_fd);
                status = 4;
                break;
            }
            status = send_kill(child_fd, true);
            close(child_fd);
            if (status != 0) break;
        }
    }
    free(children);
#ifdef RTASK_KILL_TEST_HOOK
    pause_for_test("RTASK_KILL_TEST_AFTER_CHILDREN_READY",
                   "RTASK_KILL_TEST_AFTER_CHILDREN_GO");
#endif
    if (status == 0) {
        int exited = has_exited(parent_fd);
        status = exited == 0 ? send_kill(parent_fd, false) : 4;
    }
    close(parent_fd);
    return status;
}
