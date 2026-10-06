#define _GNU_SOURCE
#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <signal.h>
#include <string.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <unistd.h>

#ifndef SYS_renameat2
#error "The atomic no-replace helper requires Linux renameat2"
#endif
#ifndef RENAME_NOREPLACE
#define RENAME_NOREPLACE 1
#endif

static int rename_no_replace(int from_fd, const char *from, int to_fd, const char *to)
{
#ifdef RUTORRENT_RENAME_TEST_ERRNO
    (void)from_fd; (void)from; (void)to_fd; (void)to;
    errno = RUTORRENT_RENAME_TEST_ERRNO;
    return -1;
#else
    return (int)syscall(SYS_renameat2, from_fd, from, to_fd, to, RENAME_NOREPLACE);
#endif
}

/* A link/unlink or check/rename fallback cannot atomically remove the exact
 * source name under a concurrent writer. Preserve both the source and no-replace
 * guarantee by refusing unsupported filesystems before consumers stop a torrent. */
static int error_status(int error)
{
    const char *reason = "rename-noreplace-error";
    int status = 4;
    if (error == EEXIST || error == ENOTEMPTY) {
        reason = "rename-noreplace-collision";
        status = 1;
    } else if (error == EINVAL || error == ENOSYS || error == EOPNOTSUPP) {
        reason = "rename-noreplace-unsupported";
        status = 3;
    }
    fprintf(stderr, "%s: errno=%d %s\n", reason, error, strerror(error));
    return status;
}

static int create_file(int directory, const char *name)
{
    int fd = openat(directory, name, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    if (fd < 0) return -1;
    int result = close(fd);
#ifdef RUTORRENT_RENAME_TEST_STOP_AFTER_CREATE
    if (!result) raise(SIGSTOP);
#endif
    return result;
}

/* A private workspace and flock allow the next probe to recover process-death
 * residue without interpreting any payload entry. Only the
 * fixed, attested probe entries are eligible for recovery; unknown bytes stay. */
static int attest_entry(int directory, const char *name, mode_t type, mode_t mode)
{
    struct stat entry;
    if (fstatat(directory, name, &entry, AT_SYMLINK_NOFOLLOW) != 0)
        return errno == ENOENT ? 0 : -1;
    if ((entry.st_mode & S_IFMT) != type || (entry.st_mode & 07777) != mode
        || entry.st_uid != geteuid()
        || entry.st_nlink != (type == S_IFDIR ? 2 : 1)) {
        errno = EPERM;
        return -1;
    }
    return 1;
}

static int directory_empty(int parent, const char *name)
{
    int fd = openat(parent, name, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (fd < 0) return -1;
    DIR *listing = fdopendir(fd);
    if (!listing) { int error = errno; close(fd); errno = error; return -1; }
    int error = 0;
    struct dirent *entry;
    errno = 0;
    while ((entry = readdir(listing))) {
        if (strcmp(entry->d_name, ".") != 0 && strcmp(entry->d_name, "..") != 0) {
            error = EPERM;
            break;
        }
    }
    if (!error && errno) error = errno;
    closedir(listing);
    if (error) { errno = error; return -1; }
    return 0;
}

static int clean_workspace(int directory)
{
    int duplicate = dup(directory);
    if (duplicate < 0) return -1;
    DIR *listing = fdopendir(duplicate);
    if (!listing) { int error = errno; close(duplicate); errno = error; return -1; }
    rewinddir(listing);
    int error = 0;
    struct dirent *entry;
    errno = 0;
    while ((entry = readdir(listing))) {
        const char *name = entry->d_name;
        if (strcmp(name, ".") == 0 || strcmp(name, "..") == 0 || strcmp(name, ".lock") == 0)
            continue;
        int is_file = strcmp(name, "from-file") == 0 || strcmp(name, "to-file") == 0;
        int is_directory = strcmp(name, "from-dir") == 0 || strcmp(name, "to-dir") == 0;
        if ((!is_file && !is_directory)
            || attest_entry(directory, name, is_file ? S_IFREG : S_IFDIR,
                is_file ? 0600 : 0700) != 1
            || (is_directory && directory_empty(directory, name) != 0)) {
            error = EPERM;
            break;
        }
        errno = 0;
    }
    if (!error && errno) error = errno;
    closedir(listing);
    if (error) { errno = error; return -1; }
    const char *names[] = { "from-file", "to-file", "from-dir", "to-dir" };
    for (size_t i = 0; i < sizeof(names) / sizeof(names[0]); i++) {
        if (unlinkat(directory, names[i], i < 2 ? 0 : AT_REMOVEDIR) != 0 && errno != ENOENT)
            return -1;
    }
    return 0;
}

static int workspace_name(const char *name)
{
    const char *prefix = ".rutorrent-rename-probe-";
    if (strncmp(name, prefix, strlen(prefix)) != 0) return 0;
    const char *tail = name + strlen(prefix);
    const char *first = tail;
    while (*tail >= '0' && *tail <= '9') tail++;
    if (tail == first || *tail++ != '-' || strlen(tail) != 16) return 0;
    return strspn(tail, "0123456789abcdef") == 16;
}

/* A dead probe owns no payload: its finite fixed entries may be removed only
 * after directory/lock attestation and a nonblocking lock prove it is idle. */
static int open_workspace(int parent, const char *name, int *directory, int *lock)
{
    struct stat entry, opened;
    if (fstatat(parent, name, &entry, AT_SYMLINK_NOFOLLOW) != 0) return -1;
    if (!S_ISDIR(entry.st_mode) || (entry.st_mode & 07777) != 0700
        || entry.st_uid != geteuid()) { errno = EPERM; return -1; }
    *directory = openat(parent, name, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (*directory < 0 || fstat(*directory, &opened) != 0) return -1;
    if (opened.st_dev != entry.st_dev || opened.st_ino != entry.st_ino) { errno = EPERM; return -1; }
    *lock = openat(*directory, ".lock", O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    if (*lock < 0 && errno == EEXIST)
        *lock = openat(*directory, ".lock", O_WRONLY | O_NOFOLLOW | O_CLOEXEC);
    if (*lock < 0) return -1;
    struct stat lock_entry, lock_opened;
    if (attest_entry(*directory, ".lock", S_IFREG, 0600) != 1
        || fstatat(*directory, ".lock", &lock_entry, AT_SYMLINK_NOFOLLOW) != 0
        || fstat(*lock, &lock_opened) != 0 || lock_opened.st_size != 0
        || lock_entry.st_dev != lock_opened.st_dev || lock_entry.st_ino != lock_opened.st_ino) {
        errno = EPERM; return -1;
    }
    if (flock(*lock, LOCK_EX | LOCK_NB) != 0)
        return errno == EWOULDBLOCK ? 1 : -1;
    return 0;
}

static int recover_workspaces(int parent)
{
    int duplicate = dup(parent);
    if (duplicate < 0) return -1;
    DIR *listing = fdopendir(duplicate);
    if (!listing) { int error = errno; close(duplicate); errno = error; return -1; }
    rewinddir(listing);
    int error = 0;
    unsigned count = 0;
    struct dirent *entry;
    errno = 0;
    while ((entry = readdir(listing))) {
        if (!workspace_name(entry->d_name)) continue;
        if (++count > 64) { error = EOVERFLOW; break; }
        int directory = -1, lock = -1;
        int status = open_workspace(parent, entry->d_name, &directory, &lock);
        if (status < 0) error = errno;
        if (!error && status == 0) {
            if (clean_workspace(directory) != 0
                || unlinkat(directory, ".lock", 0) != 0
                || unlinkat(parent, entry->d_name, AT_REMOVEDIR) != 0) error = errno;
        }
        if (lock >= 0) close(lock);
        if (directory >= 0) close(directory);
        if (error) break;
        errno = 0;
    }
    if (!error && errno) error = errno;
    closedir(listing);
    if (error) { errno = error; return -1; }
    return 0;
}

static int probe(const char *path)
{
    int parent = open(path, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (parent < 0) return error_status(errno);
    /* Serialize recovery and publication on the actual parent inode. A per-
     * workspace lock alone leaves a gap after its name is unlinked, and before
     * a new workspace obtains its lock. Process death releases this flock. */
    if (flock(parent, LOCK_EX) != 0) { int error = errno; close(parent); return error_status(error); }
    if (recover_workspaces(parent) != 0) { int error = errno; close(parent); return error_status(error); }
    unsigned char random_bytes[8];
    int random_fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (random_fd < 0) { int error = errno; close(parent); return error_status(error); }
    ssize_t count = read(random_fd, random_bytes, sizeof(random_bytes));
    int random_error = errno;
    close(random_fd);
    if (count != (ssize_t)sizeof(random_bytes)) {
        close(parent); return error_status(count < 0 ? random_error : EIO);
    }
    char name[96];
    snprintf(name, sizeof(name), ".rutorrent-rename-probe-%ld-%02x%02x%02x%02x%02x%02x%02x%02x",
        (long)getpid(), random_bytes[0], random_bytes[1], random_bytes[2], random_bytes[3],
        random_bytes[4], random_bytes[5], random_bytes[6], random_bytes[7]);
    if (mkdirat(parent, name, 0700) != 0) {
        int error = errno; close(parent); return error_status(error);
    }
    int directory = -1, lock = -1;
    int status = open_workspace(parent, name, &directory, &lock);
    int error = status == 0 ? 0 : (status < 0 ? errno : EBUSY);
    int ready = !error;
    if (!error && (create_file(directory, "from-file") != 0
        || rename_no_replace(directory, "from-file", directory, "to-file") != 0
        || create_file(directory, "from-file") != 0)) error = errno;
    if (!error) {
        if (rename_no_replace(directory, "from-file", directory, "to-file") == 0)
            error = EOPNOTSUPP;
        else if (errno != EEXIST && errno != ENOTEMPTY) error = errno;
    }
    if (!error && (mkdirat(directory, "from-dir", 0700) != 0
        || rename_no_replace(directory, "from-dir", directory, "to-dir") != 0
        || mkdirat(directory, "from-dir", 0700) != 0)) error = errno;
    if (!error) {
        if (rename_no_replace(directory, "from-dir", directory, "to-dir") == 0)
            error = EOPNOTSUPP;
        else if (errno != EEXIST && errno != ENOTEMPTY) error = errno;
    }
    if (ready && (clean_workspace(directory) != 0
        || unlinkat(directory, ".lock", 0) != 0
        || unlinkat(parent, name, AT_REMOVEDIR) != 0) && !error) error = errno;
    if (lock >= 0) close(lock);
    if (directory >= 0) close(directory);
    close(parent);
    return error ? error_status(error) : 0;
}

int main(int argc, char **argv)
{
    if (argc == 3 && strcmp(argv[1], "--probe") == 0 && argv[2][0] == '/')
        return probe(argv[2]);
    if (argc != 3 || argv[1][0] != '/' || argv[2][0] != '/'
        || strcmp(argv[1], argv[2]) == 0) {
        fprintf(stderr, "rename-noreplace-invalid-arguments\n");
        return 2;
    }
    return rename_no_replace(AT_FDCWD, argv[1], AT_FDCWD, argv[2]) == 0 ? 0 : error_status(errno);
}
