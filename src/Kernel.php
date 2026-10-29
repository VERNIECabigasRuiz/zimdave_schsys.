<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Every date is the school's: dates read from URLs and forms are compared with "today" on the
     * school clock, which only works when PHP's default timezone is the school's too.
     */
    public function boot(): void
    {
        parent::boot();
        date_default_timezone_set($this->getContainer()->getParameter('app.timezone'));
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
