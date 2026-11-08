<?php

namespace App\Exceptions;

use App\Enums\RepairStatus;
use DomainException;

class InvalidRepairTransition extends DomainException
{
    public static function between(RepairStatus $from, RepairStatus $to): self
    {
        return new self("No se puede pasar de \"{$from->getLabel()}\" a \"{$to->getLabel()}\".");
    }
}
