<?php

declare(strict_types=1);

namespace SKERNEL\Generator\Constants;

/**
 * How a generator child process ended, as recorded in its report.
 */
enum ReportStatus: string
{
    /** The artifacts were published. */
    case Built = 'built';

    /** The project's build protocol differs from the generator's; nothing was written. */
    case ProtocolMismatch = 'protocol-mismatch';

    /** Loading one class ended the process; nothing was written. */
    case ClassFatal = 'class-fatal';

    /** The build failed outside any class; nothing was written unless publication itself failed. */
    case Failed = 'failed';
}
