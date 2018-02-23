<?php

namespace Tests\Environment;

use Illuminate\Support\Collection;
use Sztyup\Nexus\Contracts\SiteModelContract;
use Sztyup\Nexus\Contracts\SiteRepositoryContract;

class ModelRepo implements SiteRepositoryContract
{
    /**
     * Returns all site models, implementing SiteModelContract
     *
     * @return Collection|SiteModelContract[]
     */
    public function getAll(): Collection
    {
        return new Collection([
            new Foo()
        ]);
    }

    /**
     * Returns Models for a specified site
     *
     * @param string $slug
     * @return SiteModelContract[]|Collection
     */
    public function getBySlug(string $slug): Collection
    {
        if ($slug == 'foo') {
            return new Collection([
                new Foo()
            ]);
        }

        if ($slug == 'bar') {
            return new Collection([
                new Bar()
            ]);
        }

        dd($slug);
    }
}