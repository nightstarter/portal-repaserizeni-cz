<?php

namespace PortalRepase\Bridge;

final class SafeLogger
{
    private $file;
    private $maxBytes;
    private $retentionDays;
    private $sensitive;

    public function __construct($file, $maxBytes, $retentionDays, array $sensitive)
    {
        $this->file = $file;
        $this->maxBytes = max(1024, (int) $maxBytes);
        $this->retentionDays = max(1, (int) $retentionDays);
        $this->sensitive = array_filter($sensitive, function ($value) {
            return is_string($value) && $value !== '';
        });
    }

    public function write($level, $code, array $context = array())
    {
        $this->rotate();
        foreach ($context as $key => $value) {
            if (preg_match('/secret|password|dsn|signature|authorization/i', (string) $key)) {
                $context[$key] = '[REDACTED]';
            }
        }
        $line = Util::utcNow().' '.strtoupper((string) $level).' '.preg_replace('/[^A-Z0-9_.-]/', '_', strtoupper((string) $code));
        if ($context !== array()) {
            $line .= ' '.Util::json($context);
        }
        foreach ($this->sensitive as $secret) {
            $line = str_replace($secret, '[REDACTED]', $line);
        }
        $directory = dirname($this->file);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BridgeException('LOG_DIRECTORY_CREATE_FAILED');
        }
        if (file_put_contents($this->file, $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new BridgeException('LOG_WRITE_FAILED');
        }
    }

    private function rotate()
    {
        if (is_file($this->file) && filesize($this->file) >= $this->maxBytes) {
            @rename($this->file, $this->file.'.'.gmdate('YmdHis'));
        }
        foreach (glob($this->file.'.*') ?: array() as $old) {
            if (is_file($old) && filemtime($old) < time() - ($this->retentionDays * 86400)) {
                @unlink($old);
            }
        }
    }
}
