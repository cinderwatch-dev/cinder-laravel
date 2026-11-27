<?php

namespace Cinderwatch\Laravel\Health\Http;

use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Wire\SnapshotSerializer;
use Cinderwatch\Laravel\Health\ClusterAssembler;
use Cinderwatch\Laravel\Health\HealthSettings;
use Cinderwatch\Laravel\Health\SnapshotRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers 404 without the shared secret, so the route cannot be discovered.
 */
class HealthEndpointController
{
    public function __construct(
        private readonly HealthSettings $settings,
        private readonly Inspector $inspector,
        private readonly SnapshotRepository $repository,
        private readonly ClusterAssembler $assembler,
        private readonly SnapshotSerializer $serializer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expected = $this->settings->endpointSecret();
        $provided = $request->bearerToken();

        if ($expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        $payload = $this->serializer->clusterToPayload(
            $this->assembler->assemble($this->localSnapshot()),
        );

        return new JsonResponse($payload);
    }

    private function localSnapshot(): HealthSnapshot
    {
        if (! $this->settings->inspectsOnRequest()) {
            $stored = $this->repository->find();

            if ($stored !== null) {
                return $stored;
            }
        }

        $snapshot = $this->inspector->inspect();
        $this->repository->put($snapshot);

        return $snapshot;
    }
}
