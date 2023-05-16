<?php

declare(strict_types=1);

namespace MilesChou\Monoex\Teams;

class LoggerColour
{
    private const EMERGENCY = '721C24';
    private const CRITICAL = 'FF8000';
    private const ERROR = 'FF0000';
    private const WARNING = 'FFEEBA';
    private const INFO = 'BEE5EB';
    private const DEBUG = 'C3E6CB';

    public function __construct(private readonly string $const = 'DEBUG')
    {
    }

    public function __toString()
    {
        return constant('self::' . $this->const);
    }
}
