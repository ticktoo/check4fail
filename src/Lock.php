<?php
/**
 * File-based lock mechanism to prevent race conditions
 */
class Lock {
    private $lockFile;
    private $lockHandle;
    private $maxLockAge;
    
    /**
     * @param string $lockFile Path to lock file
     * @param int $maxLockAge Maximum age of lock in seconds before considering it stale
     */
    public function __construct(string $lockFile, int $maxLockAge = 3600) {
        $this->lockFile = $lockFile;
        $this->maxLockAge = $maxLockAge;
        $this->lockHandle = null;
    }
    
    /**
     * Acquire lock, removing stale locks if necessary
     * @return bool True if lock acquired, false otherwise
     */
    public function acquire(): bool {
        // Check for stale lock
        if (file_exists($this->lockFile)) {
            $lockAge = time() - filemtime($this->lockFile);
            if ($lockAge > $this->maxLockAge) {
                // Lock is stale, remove it
                @unlink($this->lockFile);
                $this->log("Removed stale lock (age: {$lockAge}s)");
            }
        }
        
        // Try to acquire lock
        $this->lockHandle = @fopen($this->lockFile, 'x');
        if ($this->lockHandle === false) {
            return false;
        }
        
        // Write PID and timestamp
        fwrite($this->lockHandle, json_encode([
            'pid' => getmypid(),
            'timestamp' => time(),
            'started_at' => date('Y-m-d H:i:s')
        ]));
        fflush($this->lockHandle);
        
        return true;
    }
    
    /**
     * Release the lock
     */
    public function release(): void {
        if ($this->lockHandle) {
            fclose($this->lockHandle);
            @unlink($this->lockFile);
            $this->lockHandle = null;
        }
    }
    
    /**
     * Check if lock is currently held
     */
    public function isLocked(): bool {
        return file_exists($this->lockFile) && 
               (time() - filemtime($this->lockFile)) <= $this->maxLockAge;
    }
    
    /**
     * Get lock info if locked
     */
    public function getLockInfo(): ?array {
        if (!file_exists($this->lockFile)) {
            return null;
        }
        
        $content = @file_get_contents($this->lockFile);
        if ($content === false) {
            return null;
        }
        
        $info = json_decode($content, true);
        if ($info) {
            $info['age_seconds'] = time() - $info['timestamp'];
        }
        
        return $info;
    }
    
    private function log(string $message): void {
        error_log("[Lock] {$message}");
    }
    
    /**
     * Destructor ensures lock is released
     */
    public function __destruct() {
        $this->release();
    }
}
