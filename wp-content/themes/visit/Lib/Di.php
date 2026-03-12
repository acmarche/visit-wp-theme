<?php

namespace VisitMarche\ThemeWp\Lib;

use AcMarche\PivotAi\Kernel;

class Di
{
    private static ?self $instance = null;
    private ?Kernel $kernel = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    public function get(string $service): object
    {
        return $this->boot()->getContainer()->get($service);
    }

    private function boot(): Kernel
    {
        if (!$this->kernel) {
            $this->kernel = new Kernel($_ENV['APP_ENV'], WP_DEBUG);
            $this->kernel->boot();
        }

        return $this->kernel;
    }
}
