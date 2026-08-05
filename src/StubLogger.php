<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * A logger that keeps what it was told and writes nothing anywhere
 */
class StubLogger implements LoggerInterface {

    /** @var array[] Every line, as ['level' => ..., 'message' => ...] */
    public array $lines = [];

    public function level(): string {
        return LogLevel::ERROR;
    }

    public function log($level, $message, array $context = []): void {
        $this->lines[] = ['level' => $level, 'message' => (string)$message];
    }

    public function emergency($message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert($message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical($message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error($message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning($message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice($message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info($message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    public function debug($message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }
}
