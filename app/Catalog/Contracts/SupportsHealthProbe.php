<?php

namespace App\Catalog\Contracts;

/**
 * A provider that can tell the admin how it is answering: asked, cheaply,
 * only whether it answers and takes the instance's key, so its health is
 * known on an instance nobody is using, and named with the setting that
 * holds its key, so a refused key says what to change.
 */
interface SupportsHealthProbe
{
    /** The service's own name, as its users know it ("TMDB"). */
    public function serviceName(): string;

    /**
     * The settings that hold its key, as the operator writes them in `.env`.
     *
     * @return non-empty-list<string>
     */
    public function credentialVariables(): array;

    /**
     * One cheap request past every cache, asking only for what the instance
     * asks for every day, so it needs no scope the key would not have
     * anyway. It throws as any request to the provider would; the
     * provider's health has heard how it went either way.
     */
    public function probe(): void;
}
