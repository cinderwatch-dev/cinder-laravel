<?php

/*
 * A minimal stand-in for Horizon's supervisor repository, so the probe can be
 * tested (and analysed) without installing Horizon.
 */

namespace Laravel\Horizon\Contracts {
    if (! interface_exists(MasterSupervisorRepository::class)) {
        interface MasterSupervisorRepository
        {
            /**
             * @return array<int, mixed>|null
             */
            public function all();
        }
    }
}
