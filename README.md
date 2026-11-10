<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SKERNEL

`super-kernel/skernel` is SKERNEL, the command-line project tool for SuperKernel applications. It is itself built as a SuperKernel console application and is distributed as a standalone binary.

> [!NOTE]
> SKERNEL is under active development. It provides the `new`, `run`, `build`, `package`, `clean`, and `self-update` commands. Its console also offers SKERNEL's `help` and `list` commands, Symfony Console's `completion` command, and the `--version` option. Binaries are published through this repository's [GitHub Releases](https://github.com/super-kernel/skernel/releases).

## Installation

Download the binary for your platform, one of `linux-x86_64`, `linux-aarch64`, `macos-aarch64`, and `macos-x86_64`, from the [latest release](https://github.com/super-kernel/skernel/releases/latest). It needs no PHP.

```bash
curl -fsSL -o skernel https://github.com/super-kernel/skernel/releases/latest/download/skernel-linux-x86_64
chmod +x skernel
./skernel --version
```

## Console

Running SKERNEL without a command, or with `list`, prints the SKERNEL logo above the plain-text overview of the available commands. The raw, JSON, XML, and Markdown listings, namespace listings, and command help omit the logo. `--version` prints `SKERNEL`, followed by the version when the package declares one.

SKERNEL writes log records of WARNING and above, including the framework's exception reports, to STDERR, so STDOUT carries only command output; for example, `list --format=json` writes plain JSON to STDOUT.

A usage error, such as an unknown command or option, prints a short message on STDERR, followed by the running command's synopsis when a command's input is invalid, and exits with status 1. Add `-v` to also print the exception class and trace.

Commands work on the SuperKernel application in the current directory, or in the directory given with the global `-d`/`--working-dir` option.

## Configuration file

Options left off the command line are read from `skernel.json` in the project's root directory, or from `skernel.json.dist` when `skernel.json` does not exist; the two files are never merged. Commit `skernel.json.dist` with the project's shared settings and keep machine-specific overrides in an ignored `skernel.json`.

```json
{
    "$schema": "https://raw.githubusercontent.com/super-kernel/skernel/main/skernel.schema.json",
    "php": "8.4",
    "package": {
        "runtime": "build/micro.sfx",
        "output": "target/my-app",
        "composer": "composer",
        "dev": false,
        "phar": false
    }
}
```

The keys mirror the command options: `php` applies to every command, and the `package` section to `package`. Command-line options take precedence over the file, and the file over the built-in defaults. Relative paths in the file resolve against the project's root directory, while relative paths on the command line resolve against the current directory. An unknown key or a value of the wrong type stops the command with a message that names the file and the key. [`skernel.schema.json`](skernel.schema.json) describes the file for editors that support JSON Schema.

## Managed PHP

SKERNEL runs and packages a project with a static PHP build of its own rather than with the PHP on your system. A PHP version is given as `major.minor`, such as `8.4`, which selects that branch's latest release on php.net, or as `major.minor.patch`, such as `8.4.27`. Without a version, SKERNEL selects the latest supported PHP release that satisfies the root package's `require.php`.

The build contains the PHP extensions that the project's installation requires through `ext-*` requirements in the root manifest, in its development requirements when they are installed, and in every installed package, together with `phar`. SKERNEL builds it once per platform, version, and extension set with a pinned release of [static-php-cli](https://github.com/crazywhalecc/static-php-cli), producing a static PHP CLI and a micro runtime for standalone binaries, and reuses it afterwards. The micro runtime reports `cli` as `PHP_SAPI`, so a standalone binary starts what requires the CLI, such as a Swoole server. On Linux, both are linked statically against musl and compiled for the architecture's baseline CPU, so they do not depend on the Linux distribution; SKERNEL builds them with a pinned release of [Zig](https://ziglang.org), which it verifies and installs into its home on first use. As the Zig Software Foundation asks of tools, Zig is downloaded from the [community mirrors](https://ziglang.org/download/community-mirrors/) in random order, moving on from a mirror that is too slow, and from ziglang.org only when no mirror serves it.

When the installation limits the version of `ext-swoole` or `ext-redis`, through a requirement or a conflict, SKERNEL builds the latest stable PECL release that it accepts. Any other extension is built at the version static-php-cli selects, and a build that ends up with an extension version the installation does not accept fails instead of being used.

Everything SKERNEL downloads and builds lives in its home directory, `~/.skernel`, or the directory given by the `SKERNEL_HOME` environment variable:

```text
~/.skernel/
├── cache/                       php.net and PECL release answers and Zig mirrors, refreshed daily
├── downloads/                   source archives, shared by every build
├── pkgroot/<platform>/          Zig and the other packages static-php-cli installs
├── runtimes/<platform>/php-<version>/<extension-hash>/
│   ├── php                      the static PHP CLI
│   ├── micro.sfx                the micro runtime
│   └── manifest.json
├── tools/spc/<version>/         static-php-cli
└── work/<job>/                  sources and build output of one build
```

The first build of a runtime needs network access and the build tools that static-php-cli requires, such as a C compiler, `perl`, `re2c`, `autoconf`, `automake`, `cmake`, `libtool`, and `gettext`. SKERNEL never installs system packages; when a build fails, static-php-cli's output on STDERR names what is missing, and the job's directory is kept for inspection. The static-php-cli binary and Zig are verified against their pinned SHA-256 digests before they run, so a mirror can neither alter nor replace Zig. php.net and PECL answers and the Zig mirror list are cached for a day and reused when those sites cannot be reached.

Downloads go through the proxy that the environment names, as with curl: `https_proxy` or `HTTPS_PROXY` for HTTPS, `http_proxy` or `HTTP_PROXY` for HTTP, and `no_proxy` or `NO_PROXY` for the comma-separated hosts, with their subdomains, that bypass it. static-php-cli and Composer, which SKERNEL runs, read the same variables.

## Commands

### new

```text
new <directory> [--composer=<binary>]
```

`new` creates a project in `<directory>`, a path relative to the global `-d`/`--working-dir` option or the current directory, from the [`super-kernel/skeleton`](https://github.com/super-kernel/skeleton) package with Composer's `create-project` command. Platform requirements are not checked against the PHP that runs Composer, because the project runs on its managed PHP.

With `--composer`, that binary is used. Otherwise SKERNEL uses the first working Composer 2 it finds: `composer` or `composer.phar` in `PATH`, then `~/.local/bin/composer`, `~/bin/composer`, `~/.config/composer/vendor/bin/composer`, and `~/.composer/vendor/bin/composer`. When there is none, it downloads a pinned Composer release, verified against its SHA-256 digest, into its home directory and runs it with a managed PHP, which it builds the first time.

```text
Project created in /path/to/my-app.
```

A directory that exists and is not empty, a Composer that is not found or fails, or a managed PHP that cannot be provided prints a short message on STDERR and exits with status 1.

### run

```text
run [-d|--working-dir=<dir>] [--php=<version>] [--watch] [--] [<arguments>...]
```

`run` runs the project from source with its managed PHP, in a separate process in the project directory. `<arguments>` go to the application, so `run swoole:start` starts its Swoole server; put options for the application after `--`. Without `--watch`, SKERNEL exits with the application's exit status.

With `--watch`, SKERNEL restarts the application whenever a project file changes. It checks once a second for changes to PHP files, `.env` and `.env.*` files, and `composer.lock`, leaving out the vendor directory, `target/`, and directories whose name starts with a dot, so the logs and caches a running application writes do not restart it. It sends the application SIGTERM and then SIGKILL after 10 seconds. An application that exits on its own is started again by the next change.

SIGINT and SIGTERM stop the application before SKERNEL exits. The failures of `build`, and an application that cannot start, print a short message on STDERR and exit with status 1.

### clean

```text
clean [-d|--working-dir=<dir>]
```

`clean` removes the project's `target/` directory and its `<vendor>/.cache` scan caches, and says what it removed. A package written elsewhere with `--output` or the `package.output` setting stays. The project's dependencies do not have to be installed.

### self-update

```text
self-update
```

`self-update` replaces a standalone SKERNEL with the binary of the [latest GitHub Release](https://github.com/super-kernel/skernel/releases/latest), named `skernel-<platform>`, such as `skernel-linux-x86_64`. The download is verified against the SHA-256 digest that GitHub reports for it, and the binary keeps its permissions. When SKERNEL already has that version, nothing is downloaded; any other version is replaced.

Running from source, a latest release that cannot be read or has no verified binary for the platform, or a binary that cannot be replaced prints a short message on STDERR and exits with status 1.

### build

```text
build [-d|--working-dir=<dir>] [--php=<version>]
```

`build` runs a full source scan of the project and writes the build artifacts that restore-only and Phar execution load to the project's `<vendor>/.cache` directory, honouring its `config.vendor-dir`: the scan snapshots, and whatever the project's components write when the build publishes `SuperKernel\Event\ApplicationBuild`, such as the prepared proxies of `super-kernel/aop`. The project must have its dependencies installed, including `super-kernel/context` and `super-kernel/di`, and the scan covers the packages of that installation.

The build runs in a separate process of the managed PHP CLI. That process loads the project's own autoloader, bootstraps its container, and then publishes `ApplicationBuild` through the project's PSR-14 event dispatcher, when the project has both the event and a dispatcher, without resolving or running the application. Everything the process writes, including the project's scan diagnostics, goes to STDERR, and on success STDOUT carries one line:

```text
Build artifacts written to /path/to/my-app/vendor/.cache.
```

A missing project or dependencies, an invalid configuration file or `--php` value, a managed PHP that cannot be provided, or a failed build prints a short message on STDERR and exits with status 1.

### package

```text
package [-d|--working-dir=<dir>] [--php=<version>] [--phar] [--dev] [--output=<path>]
        [--composer=<binary>] [--runtime=<micro.sfx>]
```

`package` turns the project into a standalone executable binary, or into a PHAR with `--phar`. It works on a temporary copy of the project, so the project itself is not changed:

1. It copies the project without its vendor directory, VCS metadata such as `.git`, its `target/` directory, and the `.env` and `.env.*` files in its root directory, which usually hold machine-local secrets; a packaged application reads its environment from the process environment.
2. It installs the copy's dependencies from the project's `composer.lock` with the Composer that [`new`](#new) would use: the binary given with `--composer`, a path or a name looked up in `PATH`, or else the first working Composer 2 it finds, or else its own. Development dependencies are left out unless `--dev` is given. The project must have a `composer.lock`, but its own dependencies need not be installed. Composer does not check platform requirements against the PHP that runs it, because the managed PHP is the package's platform.
3. It runs `build` on the copy with the managed PHP of that installation.
4. It archives the copy into a PHAR whose stub loads the copy's autoloader, obtains the application container, and runs the application, so no entry script is needed.
5. It writes the managed micro runtime, or the file given with `--runtime`, followed by the PHAR to the output path, or only the PHAR with `--phar`, and makes the result executable. A micro runtime given with `--runtime` must report `cli` as `PHP_SAPI` for the result to start a Swoole server; with static-php-cli, build it with `--with-micro-fake-cli`.

The output path is given with `--output`, or defaults to `target/<name>`, or `target/<name>.phar` with `--phar`, where `<name>` is the last segment of the root package's name, or the project directory's name when the package has none. Everything the copy's Composer, build, and archive processes write goes to STDERR, and on success STDOUT carries one line:

```text
Package written to /path/to/my-app/target/my-app.
```

A missing `composer.lock`, a runtime file that is not found, a failed installation, build, or archive, or any of the failures of `build` prints a short message on STDERR and exits with status 1.

## Releases

Pushing a tag that is a [SemVer 2.0](https://semver.org) version, such as `1.2.3` or `1.3.0-rc.1`, without a `v` prefix, publishes a [GitHub Release](https://github.com/super-kernel/skernel/releases) with a binary for each of `linux-x86_64`, `linux-aarch64`, `macos-aarch64`, and `macos-x86_64`. Each binary is checked by creating, packaging, and serving a project before anything is published. Any other tag fails.

The newest tag is always the [latest release](https://github.com/super-kernel/skernel/releases/latest), which `self-update` installs, whatever its version says. SKERNEL keeps no older version line and does not support moving back to an older version.

## Requirements

- PHP `>=8.4.27 <8.5` with the `openssl`, `pcntl`, `phar`, and `zlib` extensions, only to run SKERNEL from source; Composer checks the required PHP extensions during installation.
- Network access and static-php-cli's build tools the first time a managed PHP is built, as described under [Managed PHP](#managed-php).

## Running from source

To work on SKERNEL itself, run it from a clone of this repository. SuperKernel detects an application's root from the working directory, so start SKERNEL from its own directory and select the project to work on with `-d`:

```bash
git clone https://github.com/super-kernel/skernel.git
cd skernel
composer install --no-dev
php bin/bootstrap.php list
php bin/bootstrap.php build -d ../my-app
php bin/bootstrap.php package -d ../my-app
```

## License

SKERNEL is released under the [MIT License](LICENSE).
