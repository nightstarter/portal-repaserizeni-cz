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
        $this->setMany(array($key => $value));
    }

    public function setMany(array $values)
    {
        $state = array_merge($this->read(), $values);
        Util::atomicWrite($this->path, Util::json($state));
    }

    public function remove(array $keys)
    {
        $state = $this->read();
        foreach ($keys as $key) {
            unset($state[$key]);
        }
        Util::atomicWrite($this->path, Util::json($state));
    }
}
