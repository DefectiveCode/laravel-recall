<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tracking;

use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class ClientTracker
{
    /** @var array<string, PendingRequest> */
    protected array $pendingRequests = [];

    public function __construct(
        protected InvalidationSubscriber $subscriber,
        protected LocalCacheInterface $localCache,
    ) {}

    public function registerPendingRequest(string $key): PendingRequest
    {
        $request = new PendingRequest($key);
        $this->pendingRequests[$key] = $request;

        return $request;
    }

    public function completePendingRequest(PendingRequest $request): bool
    {
        $key = $request->getKey();

        if (! isset($this->pendingRequests[$key])) {
            return false;
        }

        if ($this->pendingRequests[$key]->getTimestamp() !== $request->getTimestamp()) {
            return false;
        }

        unset($this->pendingRequests[$key]);

        return true;
    }

    public function processInvalidations(): void
    {
        if (! $this->subscriber->isConnected()) {
            return;
        }

        $invalidatedKeys = $this->subscriber->readInvalidations();

        foreach ($invalidatedKeys as $key) {
            $this->handleInvalidation($key);
        }
    }

    protected function handleInvalidation(string $key): void
    {
        if ($key === '__flush_all__') {
            $this->localCache->flush();
            $this->pendingRequests = [];

            return;
        }

        $this->localCache->forget($key);
        unset($this->pendingRequests[$key]);
    }

    public function clearPendingRequests(): void
    {
        $this->pendingRequests = [];
    }
}
