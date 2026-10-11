<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StaleTicket extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Ce ticket a changé ou est temporairement occupé. Rechargez-le avant de réessayer.');
    }
}
