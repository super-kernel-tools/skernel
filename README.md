<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SKERNEL

`super-kernel/skernel` is SKERNEL, the command-line project tool for SuperKernel applications. It is itself built as a SuperKernel console application and is distributed as a standalone binary.

> [!NOTE]
> SKERNEL is under active development. It provides the `new`, `run`, `build`, `package`, `clean`, and `self-update` commands. Its console also offers SKERNEL's `help` and `list` commands, Symfony Console's `completion` command, and the `--version` option. Binaries are published through this repository's [GitHub Releases](https://github.com/super-kernel-tools/skernel/releases).

## Installation

Download the binary for your platform, one of `linux-x86_64`, `linux-aarch64`, `macos-aarch64`, and `macos-x86_64`, from the [latest release](https://github.com/super-kernel-tools/skernel/releases/latest). It needs no PHP.

```bash
curl -fsSL -o skernel https://github.com/super-kernel-tools/skernel/releases/latest/download/skernel-linux-x86_64
chmod +x skernel
./skernel --version
```

## Console

Running SKERNEL without a command, or with `list`, prints the SKERNEL logo above the plain-text overview of the available commands. The raw, JSON, XML, and Markdown listings, namespace listings, and command help omit the logo. `--version` prints `SKERNEL`, followed by the version when the package declares one.

SKERNEL writes log records of WARNING and above, including the framework's exception reports, to STDERR, so STDOUT carries only command output; for example, `list --format=json` writes plain JSON to STDOUT.

A usage error, such as an unknown command or option, prints a short message on STDERR, followed by the running command's synopsis when a command's input is invalid, and exits with status 1. Add `-v` to also print the exception class and trace.

Commands work on the SuperKernel application in the current directory, or in the directory given with the global `-d`/`--working-dir` option.

## Configuration file

A project's settings for SKERNEL's commands are read from `skernel.json` in the project's root directory, or from `skernel.json.dist` when `skernel.json` does not exist; the two files are never merged. They have no command-line options: the command line says only where to work, with the global `-d`/`--working-dir` option, and passes the application's arguments after `run --`. Commit `skernel.json.dist` with the project's shared settings and keep machine-specific settings in an ignored `skernel.json`.

```json
{
    "$schema": "https://raw.githubusercontent.com/super-kernel-tools/skernel/main/skernel.schema.json",
    "php": "8.4",
    "prebuilt": true,
    "composer": "composer",
    "run": {
        "watch": true,
        "watch-files": ["*.php", ".env", ".env.*", "composer.json", "composer.lock"]
    },
    "package": {
        "runtime": "build/micro.sfx",
        "output": "target/my-app",
        "dev": false,
        "phar": false
    }
}
```

| Key | Used by | Default |
| --- | --- | --- |
| `php` | `build`, `run`, `package` | the latest PHP release that the root package's `require.php` allows, see [Managed PHP](#managed-php) |
| `prebuilt` | `build`, `run`, `package` | `true`: a managed PHP that is not in the SKERNEL home yet is downloaded prebuilt when one is published, see [Managed PHP](#managed-php) |
| `composer` | `build`, `run`, `package` | the first working Composer 2 SKERNEL finds, or else its own, as for [`new`](#new) |
| `run.watch` | `run` | `false` |
| `run.watch-files` | `run` | `["*.php", ".env", ".env.*", "composer.json", "composer.lock"]` |
| `package.runtime` | `package` | the managed micro runtime |
| `package.output` | `package` | `target/<name>`, or `target/<name>.phar` for a PHAR |
| `package.dev` | `package` | `false` |
| `package.phar` | `package` | `false` |

A setting that the file leaves out, and every setting of a project without the file, takes its default. Relative paths resolve against the project's root directory, and a `composer` value without a `/` is a name looked up in `PATH`. An unknown key or a value of the wrong type stops the command with a message that names the file and the key. [`skernel.schema.json`](skernel.schema.json) describes the file for editors that support JSON Schema.

## Managed PHP

SKERNEL builds, runs, and packages a project with a static PHP build of its own rather than with the PHP on your system. The `php` setting gives the version as `major.minor`, such as `8.4`, which selects that branch's latest release on php.net, or as `major.minor.patch`, such as `8.4.27`. Without a version, SKERNEL selects the latest supported PHP release that satisfies the root package's `require.php`.

The build contains the PHP extensions that the project's installation requires through `ext-*` requirements in the root manifest, in its development requirements when they are installed, and in every installed package, together with `phar`, which packaging needs, and `tokenizer`, which `build` needs. SKERNEL builds it once per platform, version, and extension set with a pinned release of [static-php-cli](https://github.com/crazywhalecc/static-php-cli), producing a static PHP CLI and a micro runtime for standalone binaries, and reuses it afterwards. The micro runtime reports `cli` as `PHP_SAPI`, so a standalone binary starts what requires the CLI, such as a Swoole server. On Linux, both are linked statically against musl and compiled for the architecture's baseline CPU, so they do not depend on the Linux distribution; SKERNEL builds them with a pinned release of [Zig](https://ziglang.org), which it verifies and installs into its home on first use. As the Zig Software Foundation asks of tools, Zig is downloaded from the [community mirrors](https://ziglang.org/download/community-mirrors/) in random order, moving on from a mirror that is too slow, and from ziglang.org only when no mirror serves it.

When the installation limits the version of `ext-swoole` or `ext-redis`, through a requirement or a conflict, SKERNEL builds the latest stable PECL release that it accepts. Any other extension is built at the version static-php-cli selects, and a build that ends up with an extension version the installation does not accept fails instead of being used.

Before SKERNEL builds a runtime, it looks for the same build in the releases of [super-kernel-tools/runtimes](https://github.com/super-kernel-tools/runtimes), which publishes the runtimes of a project that `new` creates, with and without its development requirements and with and without `super-kernel/redis`, and the PHP of SKERNEL's own Composer. A prebuilt runtime is used only when it has exactly the platform, PHP version, extensions, extension versions, toolchain, and build options of the build SKERNEL would do:

```text
Downloading the prebuilt PHP 8.4.27 runtime for linux-x86_64.
```

SKERNEL installs the download only when the archive matches its published SHA-256 digest, its manifest names what the project needs, both binaries match the digests in the manifest, and its PHP runs on the machine and reports extension versions that the installation accepts; otherwise the command stops. When no such runtime is published, or the releases cannot be reached, SKERNEL builds the runtime:

```text
No prebuilt PHP 8.4.27 runtime for linux-x86_64 with the extensions imagick, phar, tokenizer was found; building it with static-php-cli.
```

With `prebuilt` set to `false`, SKERNEL builds every runtime on the machine and never contacts that repository.

Everything SKERNEL downloads and builds lives in its home directory, `~/.skernel`, or the directory given by the `SKERNEL_HOME` environment variable:

```text
~/.skernel/
├── cache/                       php.net and PECL release answers and Zig mirrors, refreshed daily
├── downloads/                   source archives, shared by every build
├── generator/<digest>/          the generator a standalone SKERNEL extracts for builds
├── pkgroot/<platform>/          Zig and the other packages static-php-cli installs
├── runtimes/<platform>/php-<version>/<extension-hash>/
│   ├── php                      the static PHP CLI
│   ├── micro.sfx                the micro runtime
│   └── manifest.json
├── tools/spc/<version>/         static-php-cli
└── work/<job>/                  sources and build output of one build
```

Downloading a prebuilt runtime needs only network access. Building a runtime needs network access and the build tools that static-php-cli requires, such as a C compiler, `perl`, `re2c`, `autoconf`, `automake`, `cmake`, `libtool`, and `gettext`. SKERNEL never installs system packages; when a build fails, static-php-cli's output on STDERR names what is missing, and the job's directory is kept for inspection. The static-php-cli binary and Zig are verified against their pinned SHA-256 digests before they run, so a mirror can neither alter nor replace Zig. php.net and PECL answers and the Zig mirror list are cached for a day and reused when those sites cannot be reached.

Downloads go through the proxy that the environment names, as with curl: `https_proxy` or `HTTPS_PROXY` for HTTPS, `http_proxy` or `HTTP_PROXY` for HTTP, and `no_proxy` or `NO_PROXY` for the comma-separated hosts, with their subdomains, that bypass it. static-php-cli and Composer, which SKERNEL runs, read the same variables.

## Commands

### new

```text
new <directory> [--composer=<binary>]
```

`new` creates a project in `<directory>`, a path relative to the global `-d`/`--working-dir` option or the current directory, from the [`super-kernel/skeleton`](https://github.com/super-kernel/skeleton) package with Composer's `create-project` command. Platform requirements are not checked against the PHP that runs Composer, because the project runs on its managed PHP.

A released SKERNEL creates the skeleton at exactly its own version, so SKERNEL 1.0.0-alpha.3 creates `super-kernel/skeleton` 1.0.0-alpha.3; SKERNEL run from source, which has no version, creates it from the development line. The version only selects the template: the template has no `composer.lock`, so Composer resolves its requirements when it creates the project.

With `--composer`, that binary is used. Otherwise SKERNEL uses the first working Composer 2 it finds: `composer` or `composer.phar` in `PATH`, then `~/.local/bin/composer`, `~/bin/composer`, `~/.config/composer/vendor/bin/composer`, and `~/.composer/vendor/bin/composer`. When there is none, it downloads a pinned Composer release, verified against its SHA-256 digest, into its home directory and runs it with a managed PHP, which it downloads prebuilt or builds the first time, as described under [Managed PHP](#managed-php). No project and so no configuration file exists yet, so a `prebuilt` setting cannot apply here.

```text
Project created in /path/to/my-app.
```

A directory that exists and is not empty, a Composer that is not found or fails, or a managed PHP that cannot be provided prints a short message on STDERR and exits with status 1. When Composer fails for a released SKERNEL, the message also names the skeleton version it asked for and says to run `skernel self-update` and create the project again if Composer could not find that version.

### run

```text
run [-d|--working-dir=<dir>] [--] [<arguments>...]
```

`run` builds the project as `build` does and then runs it from source with its managed PHP, in a separate process in the project directory. `<arguments>` go to the application, so `run swoole:start` starts its Swoole server; put options for the application after `--`. Unless the project watches, SKERNEL exits with the application's exit status.

When the configuration file sets `run.watch` to `true`, SKERNEL restarts the application whenever a watched file changes. It checks once a second for changes to the files whose name matches a pattern of `run.watch-files`, with `*`, `?`, and `[...]`, by default PHP files, `.env` and `.env.*` files, `composer.json`, and `composer.lock`. It always leaves out the vendor directory, `target/`, and directories whose name starts with a dot, so the logs and caches a running application writes do not restart it. It sends the application SIGTERM and then SIGKILL after 10 seconds, and builds the project again, including its Composer autoloader, before it restarts it. An application that exits on its own, or whose build fails, is started again by the next change.

SIGINT and SIGTERM stop the application before SKERNEL exits. The failures of `build`, and an application that cannot start, print a short message on STDERR and exit with status 1.

### clean

```text
clean [-d|--working-dir=<dir>]
```

`clean` removes the project's `target/` directory and its `<vendor>/.cache` build artifacts, and says what it removed. A package written elsewhere through the `package.output` setting stays. The project's dependencies do not have to be installed.

### self-update

```text
self-update
```

`self-update` replaces a standalone SKERNEL with the binary of the [latest GitHub Release](https://github.com/super-kernel-tools/skernel/releases/latest), named `skernel-<platform>`, such as `skernel-linux-x86_64`. The download is verified against the SHA-256 digest that GitHub reports for it, and the binary keeps its permissions. When SKERNEL already has that version, nothing is downloaded; any other version is replaced.

Running from source, a latest release that cannot be read or has no verified binary for the platform, or a binary that cannot be replaced prints a short message on STDERR and exits with status 1.

### build

```text
build [-d|--working-dir=<dir>]
```

`build` generates the build artifacts that the application loads at start and writes them to the project's `<vendor>/.cache` directory, honouring its `config.vendor-dir`: the package records with their class maps, the attributes collected from those classes, a build stamp, and whatever the project's components write when the build publishes `SuperKernel\Event\ApplicationBuild`, such as the prepared proxies of `super-kernel/aop`. A SuperKernel application started without them, or with artifacts from an older `composer.lock`, stops with a message that names `skernel build`. The project must have its dependencies installed, including `super-kernel/metadata`, `super-kernel/context`, and `super-kernel/di`, and the build covers the packages of that installation.

Before it generates anything, `build` regenerates the project's Composer autoloader with Composer's `dump-autoload` command, so the build and the application find a class added since `composer install` without a `composer dump-autoload` of your own. It uses the Composer that the `composer` setting names, or else the first working Composer 2 it finds, or else its own, as [`new`](#new) does. Composer applies the project's own autoloader settings, such as `config.optimize-autoloader` and `config.classmap-authoritative` in `composer.json`, and the development autoload rules follow the installation: a project installed with `--no-dev` stays without them. Composer runs in its own process, and so do the project's `pre-autoload-dump` and `post-autoload-dump` scripts that it runs. When Composer cannot be provided or fails, the build stops before it generates anything and keeps the previous artifacts.

The build runs in two separate processes of the managed PHP CLI, so SKERNEL never loads the project's code itself. The first loads the project's own autoloader, checks that the installed `super-kernel/metadata` uses the build protocol this SKERNEL generates, maps the classes of every installed package, collects the attributes of the classes that declare any, and publishes the records through the project's own `super-kernel/metadata` and `super-kernel/annotator`. A class that cannot be loaded or whose attribute cannot be created is skipped, even when loading it ends the process with a fatal error. The second process bootstraps the container from the records and then publishes `ApplicationBuild` through the project's PSR-14 event dispatcher, when the project has both the event and a dispatcher, without resolving or running the application.

Everything Composer and the processes write goes to STDERR, followed by the skipped classes, one per line, sorted by package and class:

```text
SKERNEL skipped 2 classes whose attributes could not be collected:
  acme/app App\Broken\Listener: Error: Class "App\Missing" not found
  acme/app App\Broken\Proxy: fatal error: Declaration of App\Broken\Proxy::run() must be compatible with ...
```

On success STDOUT carries one line:

```text
Build artifacts written to /path/to/my-app/vendor/.cache.
```

When the project's `super-kernel/metadata` uses another build protocol, the build stops before it writes anything and says which side to upgrade: SKERNEL, for example with `skernel self-update`, or the project's SuperKernel packages. A project without `super-kernel/metadata` predates SKERNEL-generated artifacts and needs SuperKernel 1.0.0-alpha.2 or later. That, a missing project or dependencies, an invalid configuration file, a managed PHP or Composer that cannot be provided, a Composer that fails to regenerate the autoloader, or a failed build prints a short message on STDERR and exits with status 1.

### package

```text
package [-d|--working-dir=<dir>]
```

`package` turns the project into a standalone executable binary, or into a PHAR when `package.phar` is `true`. It works on a temporary copy of the project, so the project itself is not changed:

1. It copies the project without its vendor directory, VCS metadata such as `.git`, its `target/` directory, its `skernel.json` and `skernel.json.dist`, and the `.env` and `.env.*` files in its root directory, which usually hold machine-local secrets; a packaged application reads its environment from the process environment.
2. It installs the copy's dependencies from the project's `composer.lock` with the Composer that `build` uses: the one the `composer` setting names, or else the first working Composer 2 it finds, or else its own. Development dependencies are left out unless `package.dev` is `true`. The project must have a `composer.lock`, but its own dependencies need not be installed. Composer does not check platform requirements against the PHP that runs it, because the managed PHP is the package's platform.
3. It runs `build` on the copy with the managed PHP of that installation and the same Composer.
4. It archives the copy into a PHAR whose stub loads the copy's autoloader, obtains the application container, and runs the application, so no entry script is needed.
5. It writes the managed micro runtime, or the file that `package.runtime` names, followed by the PHAR to the output path, or only the PHAR when `package.phar` is `true`, and makes the result executable. A micro runtime named by `package.runtime` must report `cli` as `PHP_SAPI` for the result to start a Swoole server; with static-php-cli, build it with `--with-micro-fake-cli`.

The output path is `package.output`, by default `target/<name>`, or `target/<name>.phar` for a PHAR, where `<name>` is the last segment of the root package's name, or the project directory's name when the package has none. Everything the copy's Composer, build, and archive processes write goes to STDERR, and on success STDOUT carries one line:

```text
Package written to /path/to/my-app/target/my-app.
```

A missing `composer.lock`, a runtime file that is not found, a failed installation, build, or archive, or any of the failures of `build` prints a short message on STDERR and exits with status 1.

## Releases

Pushing a tag that is a [SemVer 2.0](https://semver.org) version, such as `1.2.3` or `1.3.0-rc.1`, without a `v` prefix, publishes a [GitHub Release](https://github.com/super-kernel-tools/skernel/releases) with a binary for each of `linux-x86_64`, `linux-aarch64`, `macos-aarch64`, and `macos-x86_64`. Each binary is checked by creating, building, packaging, and serving a project before anything is published. Any other tag fails.

The newest tag is always the [latest release](https://github.com/super-kernel-tools/skernel/releases/latest), which `self-update` installs, whatever its version says. SKERNEL keeps no older version line and does not support moving back to an older version.

## Requirements

- PHP `>=8.4.27 <8.5` with the `openssl`, `pcntl`, `phar`, `tokenizer`, and `zlib` extensions, only to run SKERNEL from source; Composer checks the required PHP extensions during installation.
- Network access the first time a managed PHP is needed, and static-php-cli's build tools when it is built on the machine instead of downloaded prebuilt, as described under [Managed PHP](#managed-php).

## Running from source

To work on SKERNEL itself, run it from a clone of this repository. Every start from source first generates SKERNEL's own build artifacts in its `vendor/.cache`, the way `build` does for a project but without Composer, so a change to SKERNEL's sources takes effect at the next start; two starts from the same clone wait for each other while one of them generates. SKERNEL's own autoloader is authoritative, so run `composer dump-autoload` after adding a class to SKERNEL. SKERNEL can be started from any directory and works on the project in the current directory, or in the one given with `-d`:

```bash
git clone https://github.com/super-kernel-tools/skernel.git
cd skernel
composer install --no-dev
php bin/bootstrap.php list
php bin/bootstrap.php build -d ../my-app
php bin/bootstrap.php package -d ../my-app
```

## License

SKERNEL is released under the [MIT License](LICENSE).
