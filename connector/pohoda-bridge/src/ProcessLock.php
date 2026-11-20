<?php

namespace PortalRepase\Bridge;

final class ProcessLock
{
    private $handle;

    public function acquire($path)
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BridgeException('LOCK_DIRECTORY_CREATE_FAILED');
        }
        $this->handle = fopen($path, 'c+');
        if ($this->handle === false || ! flock($this->handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($this->handle)) {
                fclose($this->handle);
            }
            $this->handle = null;
            throw new BridgeException('CONNECTOR_ALREADY_RUNNING');
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, (string) getmypid());
        fflush($this->handle);
    }

    public function release()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
