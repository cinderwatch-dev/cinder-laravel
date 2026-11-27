<?php

namespace Cinderwatch\Laravel\Tests\Fixtures;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use RuntimeException;

class StubProbe implements Probe
{
    public function __construct(
        private string $id = 'stub',
        private string $title = 'Stub',
        private ?Observation $observation = null,
    ) {}

    public static function throwing(string $id = 'exploding'): self
    {
        return new self($id, ucfirst($id));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function examine(): Observation
    {
        return $this->observation ?? throw new RuntimeException('Boom');
    }
}
