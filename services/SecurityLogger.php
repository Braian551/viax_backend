<?php

class SecurityLogger {
    private string $logFile;

    public function __construct(?string $logFile = null) {
        $baseDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0700, true);
        }

        $this->logFile = $logFile ?: $baseDir . '/email.log';
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            @chmod($this->logFile, 0600);
        }
    }

    public function info(string $message, array $context = []): void {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void {
        $line = sprintf(
            "[%s] %s %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            empty($context) ? '' : json_encode($context, JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );

        error_log($line, 3, $this->logFile);
    }
}
