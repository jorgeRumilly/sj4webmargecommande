<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Non-blocking file lock, to keep concurrent recompute runs from overlapping.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MarginLock
{
    /** @var resource|null */
    private $handle = null;

    /** @var string */
    private $file;

    /**
     * @param string $key
     */
    public function __construct($key = 'sj4webmargecommande')
    {
        $this->file = rtrim(_PS_CACHE_DIR_, '/\\') . DIRECTORY_SEPARATOR . $key . '.lock';
    }

    /**
     * @return bool
     */
    public function acquire()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->handle = @fopen($this->file, 'c');
        if (!$this->handle) {
            return false;
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;

            return false;
        }

        return true;
    }

    /**
     * @return void
     */
    public function release()
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
            if (is_file($this->file)) {
                @unlink($this->file);
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
