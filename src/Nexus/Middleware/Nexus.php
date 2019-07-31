<?php

namespace Sztyup\Nexus\Middleware;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Sztyup\Nexus\Exceptions\NexusException;
use Sztyup\Nexus\SiteManager;
use Illuminate\Http\Request;
use Closure;

class Nexus
{
    /** @var SiteManager */
    protected $siteManager;

    /**
     * Nexus constructor.
     * @param SiteManager $manager
     */
    public function __construct(SiteManager $manager)
    {
        $this->siteManager = $manager;
    }

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     * @throws NexusException
     */
    public function handle(Request $request, Closure $next)
    {
        $this->siteManager->handleRequest($request);

        $response = $next($request);

        if ($response instanceof Response && Str::startsWith($response->headers->get('Content-type'), 'text/html')) {
            $this->siteManager->handleResponse($response);
        }

        return $response;
    }
}
