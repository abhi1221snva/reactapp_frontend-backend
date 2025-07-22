<?php


namespace App\Services;


class ExecutionProfiler
{
    private $intervals = [];
    private $profile = [];

    public function __construct()
    {
        $this->start();
    }

    public function start(): void
    {
        $this->intervals["start"] = microtime(true);
    }

    public function end(): void
    {
        $this->intervals["end"] = microtime(true);
    }

    public function addInterval(string $key): void
    {
        $this->intervals[$key] = microtime(true);
    }

    public function calculate(): array
    {
        $previous = null;
        foreach ($this->intervals as $key=>$value) {
            if ($previous) {
                $this->profile[$key] = round($value - $previous, 3);
            }
            $previous = $value;
        }
        if (!isset($this->intervals["end"])) $this->end();
        $this->profile["total"] = round($this->intervals["end"] - $this->intervals["start"], 3);
        return $this->profile;
    }
}
