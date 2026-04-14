<?php

declare(strict_types=1);

namespace TurboExcel\Enums;

enum InsertStrategy: string
{
    case INSERT = 'insert';
    case UPDATE = 'update';
    case UPSERT = 'upsert';
}
