#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <string.h>
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
    fprintf(stderr, "%s: %s\n", reason, strerror(error));
    return status;
}

static int create_file(int directory, const char *name)
{
    int fd = openat(directory, name, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    if (fd < 0) return -1;
    return close(fd);
}

static int probe(const char *path)
{
    int parent = open(path, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (parent < 0) return error_status(errno);
    unsigned char random_bytes[8];
    int random_fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (random_fd < 0) { int error = errno; close(parent); return error_status(error); }
    ssize_t count = read(random_fd, random_bytes, sizeof(random_bytes));
    int random_error = errno;
    close(random_fd);
    if (count != (ssize_t)sizeof(random_bytes)) {
        close(parent);
        return error_status(count < 0 ? random_error : EIO);
    }
    char name[96];
    snprintf(name, sizeof(name), ".rutorrent-rename-probe-%ld-%02x%02x%02x%02x%02x%02x%02x%02x",
        (long)getpid(), random_bytes[0], random_bytes[1], random_bytes[2], random_bytes[3],
        random_bytes[4], random_bytes[5], random_bytes[6], random_bytes[7]);
    if (mkdirat(parent, name, 0700) != 0) {
        int error = errno; close(parent); return error_status(error);
    }
    int directory = openat(parent, name, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    int error = directory < 0 ? errno : 0;
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
    if (directory >= 0) {
        unlinkat(directory, "from-file", 0);
        unlinkat(directory, "to-file", 0);
        unlinkat(directory, "from-dir", AT_REMOVEDIR);
        unlinkat(directory, "to-dir", AT_REMOVEDIR);
        close(directory);
    }
    if (unlinkat(parent, name, AT_REMOVEDIR) != 0 && !error) error = errno;
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
