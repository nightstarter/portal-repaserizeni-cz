<?php

namespace PortalRepase\Bridge;

final class StateStore
{
    private $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function read()
    {
        if (! is_file($this->path)) {
            return array();
        }
        $state = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($state)) {
            throw new BridgeException('STATE_FILE_INVALID');
        }
        return $state;
    }

    public function set($key, $value)
    {
        $state = $this->read();
        $state[$key] = $value;
        Util::atomicWrite($this->path, Util::json($state));
    }
}
