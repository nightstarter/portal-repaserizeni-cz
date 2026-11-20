<?php

namespace App\Backup;

use App\Backup\Contracts\DumpProcessRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class SymfonyDumpProcessRunner implements DumpProcessRunner
{
    public function run(array $command, ?string $stdoutPath, int $timeoutSeconds): DumpProcessResult
    {
        $binary = basename((string) ($command[0] ?? 'mariadb-dump'));
        $output = null;
        try {
            if ($stdoutPath !== null) {
                $output = fopen($stdoutPath, 'xb');
                if ($output === false || ! chmod($stdoutPath, 0600)) {
                    throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'dump_output', null, $binary, 'Use a new writable backup target on a filesystem with sufficient free space.');
                }
            }

            $process = new Process($command);
            $process->setTimeout(max(1, $timeoutSeconds));
            $process->start();
            $stdout = '';
            $stderr = '';
            foreach ($process as $type => $chunk) {
                if ($type === Process::OUT) {
                    if ($output !== null) {
                        $this->writeAll($output, $chunk, $binary);
                    } else {
                        $stdout = $this->appendLimited($stdout, $chunk);
                    }
                } else {
                    $stderr = $this->appendLimited($stderr, $chunk);
                }
            }

            return new DumpProcessResult($process->getExitCode() ?? 1, $stdout, $stderr);
        } catch (ProcessTimedOutException) {
            throw new DumpException('DUMP_TIMEOUT', 'dump_process', null, $binary, 'Increase the configured timeout only after checking database load and backup duration.');
        } catch (DumpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DumpException('DUMP_PROCESS_START_FAILED', 'dump_process', null, $binary, 'Verify that the configured dump binary exists and is executable by the portal user.');
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function appendLimited(string $current, string $chunk): string
    {
        return substr($current.$chunk, -65536);
    }

    /** @param resource $output */
    private function writeAll($output, string $chunk, string $binary): void
    {
        $written = 0;
        $length = strlen($chunk);
        while ($written < $length) {
            $bytes = fwrite($output, substr($chunk, $written));
            if ($bytes === false || $bytes === 0) {
                throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'dump_output', null, $binary, 'Use a new writable backup target on a filesystem with sufficient free space.');
            }
            $written += $bytes;
        }
    }
}
