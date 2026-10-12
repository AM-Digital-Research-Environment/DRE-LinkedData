<?php
declare(strict_types=1);

namespace DRELinkedData\Job;

use DRELinkedData\Dump\DumpGenerator;
use Omeka\Job\AbstractJob;

/**
 * Regenerates the N-Triples dump and the figures in the VoID description.
 * Dispatched from the module configuration form; Admin → Jobs shows progress
 * and the summary line, and "Attempt stop" abandons the run between batches,
 * leaving the previous dump in place.
 */
class GenerateDump extends AbstractJob
{
    public function perform(): void
    {
        $this->getServiceLocator()
            ->get(DumpGenerator::class)
            ->generate(fn (): bool => $this->shouldStop());
    }
}
