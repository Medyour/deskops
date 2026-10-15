<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

class RedactConnectionUrls implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->redact($record->message), context: $this->redact($record->context), extra: $this->redact($record->extra));
    }

    private function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('~(?:postgres(?:ql)?|mysql|redis|rediss)://[^\s\x22\x27<>]+~i', '[connection URL redacted]', $value);
        }
        if ($value instanceof Throwable) {
            return ['class' => $value::class, 'message' => $this->redact($value->getMessage()), 'file' => $value->getFile(), 'line' => $value->getLine()];
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redact($item), $value);
        }
        if (is_object($value)) {
            return $this->redact(get_object_vars($value));
        }

        return $value;
    }
}
