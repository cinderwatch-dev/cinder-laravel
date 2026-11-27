<?php

namespace Cinderwatch\Laravel\Tests\Fixtures;

class LivewireComponent
{
    public function getName(): string
    {
        return 'orders.table';
    }

    public function getId(): string
    {
        return 'abc123';
    }
}
