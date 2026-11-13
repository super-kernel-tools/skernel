<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SKERNEL

`super-kernel/skernel` 即 SKERNEL，是 SuperKernel 应用的命令行项目工具。它本身也是用 SuperKernel 构建的控制台应用，以独立的二进制文件发布。

> [!NOTE]
> SKERNEL 仍在积极开发中。目前提供 `new`、`run`、`build`、`package`、`clean` 和 `self-update` 命令。控制台另有 SKERNEL 自己的 `help` 和 `list` 命令、Symfony Console 的 `completion` 命令，以及 `--version` 选项。二进制文件通过本仓库的 [GitHub Releases](https://github.com/super-kernel-tools/skernel/releases) 发布。

## 安装

从[最新发布](https://github.com/super-kernel-tools/skernel/releases/latest)下载适合你平台的二进制，平台有 `linux-x86_64`、`linux-aarch64`、`macos-aarch64` 和 `macos-x86_64`。它不需要 PHP。

```bash
curl -fsSL -o skernel https://github.com/super-kernel-tools/skernel/releases/latest/download/skernel-linux-x86_64
chmod +x skernel
./skernel --version
```

## 控制台

不带命令运行 SKERNEL，或运行 `list` 时，纯文本的命令总览上方会打印 SKERNEL 标志。raw、JSON、XML 和 Markdown 格式的列表、按命名空间列出的列表以及命令帮助都不打印标志。`--version` 输出 `SKERNEL`，包声明了版本时后面跟上版本号。

SKERNEL 把 WARNING 及以上级别的日志（包括框架的异常报告）写到 STDERR，STDOUT 只承载命令本身的输出；例如 `list --format=json` 在 STDOUT 上输出的就是纯 JSON。

命令不存在、选项不存在这类用法错误，会在 STDERR 上打印一条简短的提示；如果是某个命令的输入有误，后面还会打印该命令的用法。退出码为 1。加上 `-v` 时还会显示异常类名和堆栈。

各命令处理的是当前目录中的 SuperKernel 应用，或全局选项 `-d`/`--working-dir` 指定的目录中的应用。

## 配置文件

项目供 SKERNEL 各命令使用的设置，从项目根目录的 `skernel.json` 读取；`skernel.json` 不存在时改读 `skernel.json.dist`。两个文件从不合并。这些设置没有命令行选项：命令行只用全局选项 `-d`/`--working-dir` 说明在哪里工作，并在 `run --` 之后传递应用的参数。把项目共享的设置写进 `skernel.json.dist` 并提交，本机特有的设置写进被忽略的 `skernel.json`。

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

| 键 | 使用者 | 默认值 |
| --- | --- | --- |
| `php` | `build`、`run`、`package` | 根包 `require.php` 允许的最新 PHP 版本，见[受管 PHP](#受管-php) |
| `prebuilt` | `build`、`run`、`package` | `true`：SKERNEL 主目录中还没有的受管 PHP，在已有发布时直接下载预构建版本，见[受管 PHP](#受管-php) |
| `composer` | `build`、`run`、`package` | SKERNEL 找到的第一个可用的 Composer 2，否则是它自己的，与 [`new`](#new) 相同 |
| `run.watch` | `run` | `false` |
| `run.watch-files` | `run` | `["*.php", ".env", ".env.*", "composer.json", "composer.lock"]` |
| `package.runtime` | `package` | 受管 micro 运行时 |
| `package.output` | `package` | `target/<name>`，PHAR 为 `target/<name>.phar` |
| `package.dev` | `package` | `false` |
| `package.phar` | `package` | `false` |

配置文件没有写出的设置，以及没有配置文件的项目的所有设置，都取默认值。相对路径相对于项目根目录解析；不含 `/` 的 `composer` 值是在 `PATH` 中查找的名称。出现未知的键或类型不对的值时，命令会停止，并给出包含文件名和键名的提示。[`skernel.schema.json`](skernel.schema.json) 为支持 JSON Schema 的编辑器描述了这个文件。

## 受管 PHP

SKERNEL 用自己构建的静态 PHP 来构建、运行和打包项目，而不是系统上的 PHP。`php` 设置把版本写成 `major.minor`（例如 `8.4`）时，选用 php.net 上该分支的最新发布；写成 `major.minor.patch`（例如 `8.4.27`）时精确使用该版本。不指定版本时，SKERNEL 选用满足根包 `require.php` 的最新受支持 PHP 版本。

构建出的 PHP 包含项目安装所需的扩展，也就是根清单中、已安装时的开发依赖中，以及每个已安装包中的 `ext-*` 要求，另外再加上打包所需的 `phar` 和 `build` 所需的 `tokenizer`。SKERNEL 使用固定版本的 [static-php-cli](https://github.com/crazywhalecc/static-php-cli)，按平台、版本和扩展组合各构建一次，产出静态 PHP CLI 和用于独立二进制的 micro 运行时，之后重复使用。micro 运行时把 `PHP_SAPI` 报告为 `cli`，所以独立二进制可以启动要求 CLI 的功能，例如 Swoole 服务。在 Linux 上，两者都静态链接 musl，并按该架构的基线 CPU 编译，不依赖具体的 Linux 发行版；SKERNEL 用固定版本的 [Zig](https://ziglang.org) 构建它们，首次使用时校验并安装到自己的主目录中。按照 Zig 软件基金会对下载工具的要求，Zig 以随机顺序从[社区镜像](https://ziglang.org/download/community-mirrors/)下载，某个镜像太慢就换下一个，没有镜像能提供时才从 ziglang.org 下载。

当项目安装通过要求或冲突限制了 `ext-swoole` 或 `ext-redis` 的版本时，SKERNEL 会构建它接受的最新 PECL 稳定版。其他扩展使用 static-php-cli 选择的版本；如果构建出的扩展版本不被项目安装接受，构建会失败，而不是继续使用。

构建运行时之前，SKERNEL 先到 [super-kernel-tools/runtimes](https://github.com/super-kernel-tools/runtimes) 的 Releases 中查找同一个构建。那里发布 `new` 所创建项目的运行时，包括含与不含开发依赖、加与不加 `super-kernel/redis` 的组合，以及 SKERNEL 自带 Composer 所用的 PHP。只有平台、PHP 版本、扩展、扩展版本、工具链和构建选项都与 SKERNEL 本来要做的构建完全一致时，才会使用预构建的运行时：

```text
Downloading the prebuilt PHP 8.4.27 runtime for linux-x86_64.
```

下载的归档必须与发布的 SHA-256 摘要一致，清单必须写明项目需要的内容，两个二进制文件必须与清单中的摘要一致，其中的 PHP 还必须能在本机运行，并报告项目安装接受的扩展版本，SKERNEL 才会安装它；否则命令停止。没有这样的运行时发布，或者无法访问这些 Releases 时，SKERNEL 就自己构建运行时：

```text
No prebuilt PHP 8.4.27 runtime for linux-x86_64 with the extensions imagick, phar, tokenizer was found; building it with static-php-cli.
```

把 `prebuilt` 设为 `false` 后，SKERNEL 在本机构建所有运行时，并且不再访问该仓库。

SKERNEL 下载和构建的所有内容都放在它的主目录 `~/.skernel` 中，或者环境变量 `SKERNEL_HOME` 指定的目录中：

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

下载预构建的运行时只需要联网。构建运行时除了联网，还需要 static-php-cli 要求的构建工具，例如 C 编译器、`perl`、`re2c`、`autoconf`、`automake`、`cmake`、`libtool` 和 `gettext`。SKERNEL 从不安装系统软件包；构建失败时，static-php-cli 在 STDERR 上的输出会指出缺少什么，该次构建的目录会保留下来供检查。static-php-cli 的二进制和 Zig 在运行前都会按固定的 SHA-256 摘要校验，所以镜像无法篡改或替换 Zig。php.net 和 PECL 的查询结果以及 Zig 镜像列表都缓存一天，这些站点无法访问时沿用缓存的结果。

下载时使用环境变量指定的代理，规则与 curl 相同：HTTPS 用 `https_proxy` 或 `HTTPS_PROXY`，HTTP 用 `http_proxy` 或 `HTTP_PROXY`；`no_proxy` 或 `NO_PROXY` 列出不走代理的主机（以逗号分隔，包括其子域名）。SKERNEL 调用的 static-php-cli 和 Composer 也读取这些变量。

## 命令

### new

```text
new <directory> [--composer=<binary>]
```

`new` 用 Composer 的 `create-project` 命令，从 [`super-kernel/skeleton`](https://github.com/super-kernel/skeleton) 包在 `<directory>` 创建项目；相对路径以全局选项 `-d`/`--working-dir` 或当前目录为基准。不会按运行 Composer 的 PHP 检查平台要求，因为项目运行在它的受管 PHP 上。

已发布的 SKERNEL 会创建与自身版本完全相同的骨架，例如 SKERNEL 1.0.0-alpha.3 创建 `super-kernel/skeleton` 1.0.0-alpha.3；从源码运行的 SKERNEL 没有版本，会从开发线创建。这个版本只用于选择模板：模板不带 `composer.lock`，所以 Composer 在创建项目时解析它的依赖要求。

指定 `--composer` 时使用该程序。否则 SKERNEL 使用找到的第一个可用的 Composer 2：先找 `PATH` 中的 `composer` 或 `composer.phar`，再找 `~/.local/bin/composer`、`~/bin/composer`、`~/.config/composer/vendor/bin/composer` 和 `~/.composer/vendor/bin/composer`。都没有时，它把固定版本的 Composer 下载到自己的主目录，按 SHA-256 摘要校验，再用受管 PHP 运行；这个 PHP 在首次使用时下载预构建版本或在本机构建，详见[受管 PHP](#受管-php)。此时还没有项目，也就没有配置文件，所以 `prebuilt` 设置在这里不起作用。

```text
Project created in /path/to/my-app.
```

目录已存在且不为空、找不到 Composer 或 Composer 执行失败、无法提供受管 PHP 时，会在 STDERR 上打印一条简短的提示，退出码为 1。已发布的 SKERNEL 在 Composer 执行失败时，提示中还会写明它请求的骨架版本，并说明：如果 Composer 找不到该版本，请运行 `skernel self-update` 后重新创建项目。

### run

```text
run [-d|--working-dir=<dir>] [--] [<arguments>...]
```

`run` 先像 `build` 一样构建项目，再用受管 PHP 从源码运行项目，在项目目录中以单独的进程执行。`<arguments>` 传给应用，所以 `run swoole:start` 会启动它的 Swoole 服务；要传给应用的选项放在 `--` 之后。项目不监视文件时，SKERNEL 以应用的退出码退出。

配置文件把 `run.watch` 设为 `true` 时，被监视的文件变化后 SKERNEL 会重启应用。它每秒检查一次名称匹配 `run.watch-files` 中某个模式（可用 `*`、`?` 和 `[...]`）的文件，默认是 PHP 文件、`.env` 和 `.env.*` 文件、`composer.json` 以及 `composer.lock`。它总是跳过 vendor 目录、`target/` 和名称以点开头的目录，所以运行中的应用写入日志和缓存不会触发重启。重启时先向应用发送 SIGTERM，10 秒后发送 SIGKILL，并在重启前重新构建项目，包括它的 Composer autoloader。应用自行退出或构建失败后，下一次变化会再次启动它。

SIGINT 和 SIGTERM 会先停止应用，SKERNEL 再退出。出现 `build` 的任一种失败或应用无法启动时，会在 STDERR 上打印一条简短的提示，退出码为 1。

### clean

```text
clean [-d|--working-dir=<dir>]
```

`clean` 删除项目的 `target/` 目录和 `<vendor>/.cache` 构建产物，并说明删除了什么。通过 `package.output` 设置写到其他位置的包不会被删除。项目的依赖不必已安装。

### self-update

```text
self-update
```

`self-update` 把独立的 SKERNEL 替换为[最新 GitHub Release](https://github.com/super-kernel-tools/skernel/releases/latest) 中名为 `skernel-<platform>` 的二进制，例如 `skernel-linux-x86_64`。下载内容按 GitHub 报告的 SHA-256 摘要校验，二进制保留原有权限。SKERNEL 已经是该版本时不会下载；其他任何版本都会被替换。

从源码运行、无法读取最新 Release 或其中没有当前平台可校验的二进制、无法替换二进制时，会在 STDERR 上打印一条简短的提示，退出码为 1。

### build

```text
build [-d|--working-dir=<dir>]
```

`build` 生成应用启动时加载的构建产物，写到项目的 `<vendor>/.cache` 目录，vendor 目录遵循项目的 `config.vendor-dir`：包括带类映射的包记录、从这些类收集到的注解、构建戳，以及构建发布 `SuperKernel\Event\ApplicationBuild` 时项目各组件写出的产物，例如 `super-kernel/aop` 预先准备好的代理。SuperKernel 应用在缺少这些产物，或产物来自较旧的 `composer.lock` 时启动，会停止并给出提到 `skernel build` 的提示。项目必须已经安装好依赖，其中包括 `super-kernel/metadata`、`super-kernel/context` 和 `super-kernel/di`；构建覆盖的包以这次安装为准。

`build` 在生成任何内容之前，先用 Composer 的 `dump-autoload` 命令重新生成项目的 Composer autoloader，所以 `composer install` 之后新增的类能被构建和应用找到，不需要自己运行 `composer dump-autoload`。它使用 `composer` 设置指定的 Composer，否则是找到的第一个可用的 Composer 2，再否则是 SKERNEL 自己的，与 [`new`](#new) 相同。Composer 采用项目自己的 autoloader 设置，例如 `composer.json` 中的 `config.optimize-autoloader` 和 `config.classmap-authoritative`；开发环境的自动加载规则随安装方式而定：用 `--no-dev` 安装的项目不会重新获得这些规则。Composer 在自己的进程中运行，它执行的项目 `pre-autoload-dump` 和 `post-autoload-dump` 脚本也是如此。无法提供 Composer 或 Composer 执行失败时，构建会在生成任何内容之前停止，并保留之前的产物。

构建在受管 PHP CLI 的两个单独进程中运行，所以 SKERNEL 自己从不加载项目的代码。第一个进程加载项目自己的 autoloader，确认已安装的 `super-kernel/metadata` 使用本 SKERNEL 生成的构建协议，为每个已安装的包建立类映射，收集声明了注解的类的注解，再通过项目自己的 `super-kernel/metadata` 和 `super-kernel/annotator` 发布这些记录。无法加载的类，或注解无法创建的类，会被跳过，即使加载它时进程因致命错误而结束也是如此。第二个进程从这些记录引导出容器；项目同时具备该事件和事件分发器时，再通过项目的 PSR-14 事件分发器发布 `ApplicationBuild`。整个过程不解析也不运行应用。

Composer 和这些进程写出的所有内容都转到 STDERR，之后列出被跳过的类，每行一个，按包和类排序：

```text
SKERNEL skipped 2 classes whose attributes could not be collected:
  acme/app App\Broken\Listener: Error: Class "App\Missing" not found
  acme/app App\Broken\Proxy: fatal error: Declaration of App\Broken\Proxy::run() must be compatible with ...
```

成功时 STDOUT 只有一行：

```text
Build artifacts written to /path/to/my-app/vendor/.cache.
```

项目的 `super-kernel/metadata` 使用其他构建协议时，构建会在写入任何内容之前停止，并说明该升级哪一方：SKERNEL（例如用 `skernel self-update`），或项目的 SuperKernel 包。没有 `super-kernel/metadata` 的项目早于由 SKERNEL 生成产物的做法，需要 SuperKernel 1.0.0-alpha.2 或更高版本。出现这种情况，或项目或依赖不存在、配置文件无效、无法提供受管 PHP 或 Composer、Composer 重新生成 autoloader 失败、构建失败时，会在 STDERR 上打印一条简短的提示，退出码为 1。

### package

```text
package [-d|--working-dir=<dir>]
```

`package` 把项目做成可直接执行的独立二进制，`package.phar` 为 `true` 时则做成 PHAR。它在项目的临时副本上工作，不会改动项目本身：

1. 复制项目，但不包括 vendor 目录、`.git` 等 VCS 元数据、`target/` 目录、`skernel.json` 和 `skernel.json.dist`，以及根目录下的 `.env` 和 `.env.*` 文件（这些文件通常保存本机密钥）；打包后的应用从进程环境变量读取环境配置。
2. 按项目的 `composer.lock` 为副本安装依赖，使用的 Composer 与 `build` 相同：`composer` 设置指定的那个，否则是找到的第一个可用的 Composer 2，再否则是 SKERNEL 自己的。除非 `package.dev` 为 `true`，否则不安装开发依赖。项目必须有 `composer.lock`，但项目自身不必安装依赖。Composer 不会按运行它的那个 PHP 检查平台要求，因为包的运行平台是受管 PHP。
3. 用该次安装对应的受管 PHP 和同一个 Composer 对副本运行 `build`。
4. 把副本归档为 PHAR，它的 stub 加载副本的 autoloader、获取应用容器并运行应用，因此不需要入口脚本。
5. 把受管 micro 运行时（或 `package.runtime` 指定的文件）和 PHAR 依次写入输出路径；`package.phar` 为 `true` 时只写入 PHAR。结果文件会被设为可执行。由 `package.runtime` 指定的 micro 运行时必须把 `PHP_SAPI` 报告为 `cli`，结果文件才能启动 Swoole 服务；使用 static-php-cli 时，构建时加上 `--with-micro-fake-cli`。

输出路径是 `package.output`，默认是 `target/<name>`，PHAR 为 `target/<name>.phar`；其中 `<name>` 是根包名称的最后一段，根包没有名称时取项目目录名。副本的 Composer、构建和归档进程写出的所有内容都转到 STDERR；成功时 STDOUT 只有一行：

```text
Package written to /path/to/my-app/target/my-app.
```

缺少 `composer.lock`、找不到运行时文件、安装、构建或归档失败，或出现 `build` 的任一种失败时，会在 STDERR 上打印一条简短的提示，退出码为 1。

## 发布

推送一个符合 [SemVer 2.0](https://semver.org) 的标签（例如 `1.2.3` 或 `1.3.0-rc.1`，不带 `v` 前缀）就会发布一个 [GitHub Release](https://github.com/super-kernel-tools/skernel/releases)，其中包含 `linux-x86_64`、`linux-aarch64`、`macos-aarch64` 和 `macos-x86_64` 各一个二进制。发布之前，每个二进制都要通过创建、打包并运行一个项目的检查。其他标签会失败。

最新的标签始终是[最新发布](https://github.com/super-kernel-tools/skernel/releases/latest)，`self-update` 安装的就是它，无论版本号如何。SKERNEL 不维护旧版本线，也不支持退回旧版本。

## 环境要求

- PHP `>=8.4.27 <8.5`，并带有 `openssl`、`pcntl`、`phar`、`tokenizer` 和 `zlib` 扩展，仅在从源码运行 SKERNEL 时需要；安装时由 Composer 检查所需的 PHP 扩展。
- 首次需要某个受管 PHP 时需要联网；不下载预构建版本而在本机构建时，还需要 static-php-cli 的构建工具，详见[受管 PHP](#受管-php)。

## 从源码运行

要开发 SKERNEL 本身，请从本仓库的克隆中运行它。每次从源码启动时，SKERNEL 都会像 `build` 为项目所做的那样（但不使用 Composer），先在自己的 `vendor/.cache` 中生成自己的构建产物，所以对 SKERNEL 源码的修改在下次启动时生效；同一个克隆的两次启动在其中一次生成期间会互相等待。SKERNEL 自己的 autoloader 是权威类映射，所以给 SKERNEL 新增类之后，请运行 `composer dump-autoload`。SKERNEL 可以在任意目录中启动，处理当前目录中的项目，或 `-d` 指定的目录中的项目：

```bash
git clone https://github.com/super-kernel-tools/skernel.git
cd skernel
composer install --no-dev
php bin/bootstrap.php list
php bin/bootstrap.php build -d ../my-app
php bin/bootstrap.php package -d ../my-app
```

## 许可证

SKERNEL 以 [MIT 许可证](LICENSE) 发布。
