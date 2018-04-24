<?php

namespace Sztyup\Nexus;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Str;
use Sztyup\Nexus\Contracts\CommonRouteGroup;
use Sztyup\Nexus\Contracts\SiteRepositoryContract;
use Sztyup\Nexus\Events\SiteFound;
use Sztyup\Nexus\Exceptions\NexusException;

class SiteManager
{
    /** @var Request */
    protected $request;

    /** @var  Factory */
    protected $viewFactory;

    /** @var UrlGenerator */
    protected $urlGenerator;

    /** @var Encrypter */
    protected $encrypter;

    /** @var  Repository */
    protected $config;

    /** @var  Router */
    protected $router;

    /** @var Dispatcher */
    protected $dispatcher;


    /** @var Collection */
    protected $sites;

    /** @var Site */
    private $current;

    /**
     * SiteManager constructor.
     *
     * @param Factory $viewFactory
     * @param UrlGenerator $urlGenerator
     * @param Router $router
     * @param Encrypter $encrypter
     * @param Repository $config
     * @param Container $container
     * @param Dispatcher $dispatcher
     *
     * @throws NexusException
     * @throws \ReflectionException
     */
    public function __construct(
        Factory $viewFactory,
        UrlGenerator $urlGenerator,
        Router $router,
        Encrypter $encrypter,
        Repository $config,
        Container $container,
        Dispatcher $dispatcher
    ) {
        $this->sites = new Collection();
        $this->viewFactory = $viewFactory;
        $this->urlGenerator = $urlGenerator;
        $this->encrypter = $encrypter;
        $this->router = $router;
        $this->config = $config;
        $this->dispatcher = $dispatcher;

        $this->loadSitesFromRepo($container);
    }

    /**
     * Gets a config out of nexus
     *
     * @param $config
     * @param null $default
     * @return mixed
     */
    public function getConfig($config, $default = null)
    {
        return $this->config->get('nexus.' . $config, $default);
    }

    /**
     * Handles request
     *
     * @param Request $request
     */
    public function handleRequest(Request $request)
    {
        $this->request = $request;

        if ($this->current === null) {
            // Determine current site
            $currentSite = $this->getByDomain($request->getHost());
            if ($currentSite) {
                $this->dispatcher->dispatch(SiteFound::class, [
                    $currentSite
                ]);

                $this->registerCurrentSite($currentSite);
            }
        }


        // Sets routing domain defaults
        foreach ($this->getEnabledSites() as $site) {
            $this->urlGenerator->defaults([
                '__nexus_' . $site->getName() => $site->getDomains()[0]
            ]);
        }
    }

    /**
     * Handles response
     *
     * @param Response $response
     *
     * @throws NexusException
     */
    public function handleResponse(Response $response)
    {
        // If the main domain also hosts a full site
        $main = $this->getByDomain(
            $this->getConfig('main_domain')
        );

        $sites = $this->getEnabledSites();

        if ($main) {
            // Remove the site which is on the main domain from this collection
            $sites->forget($sites->search($main));
        }

        // remove the current site from the collection
        $sites->forget($sites->search($this->current()));

        // Render cross-domain login images
        $content = $this->viewFactory->make('nexus::cdimages', [
            'sites' => $sites,
            'code' => $this->encrypter->encrypt($this->request->session()->getId())
        ])->render();

        // Inject images into the response
        $response->setContent(
            Str::replaceFirst("</body>", $content . "\n</body>", $response->getContent())
        );
    }

    /**
     * Sets some global stuff for easier usage of the current Site object
     *
     * @param Site $site
     */
    protected function registerCurrentSite(Site $site)
    {
        $this->current = $site;

        $this->viewFactory->share('__nexus_site', $site);

        $this->config->set('filesystems.default', $site->getSlug());
    }

    /**
     * Loads all available Site object from the configured repository
     *
     * @param Container $container
     *
     * @throws NexusException
     * @throws \ReflectionException
     */
    protected function loadSitesFromRepo(Container $container)
    {
        $repositoryClass = $this->getConfig('model_repository');

        // Check if it implements required Contract
        $reflection = new \ReflectionClass($repositoryClass);
        if (!$reflection->implementsInterface(SiteRepositoryContract::class)) {
            throw new NexusException('Configured repository does not implement SiteRepositoryContract');
        }

        // Instantiate repo
        /** @var SiteRepositoryContract $repository */
        $repository = $container->make($repositoryClass);

        foreach ($this->getConfig('sites') ?? [] as $site => $siteOptions) {
            $domains = [];
            $params = [];

            foreach ($repository->getBySlug($site) ?? [] as $siteModel) {
                if ($siteModel->isEnabled()) {
                    $domains[] = $siteModel->getDomain();
                }

                foreach ($siteOptions['extra_params'] ?? [] as $param => $paramOptions) {
                    if ($siteModel->getExtraData($param)) {
                        $params[$siteModel->getDomain()] = $siteModel->getExtraData($param);
                    } elseif ($paramOptions['required']) {
                        throw new NexusException('Require parameter[' . $param . '] is not given for Site: ' . $site);
                    }
                }
            };

            $commonRegistrars = [];
            foreach ($siteOptions['routes'] ?? [] as $registrar) {
                $group = $container->make($registrar);
                if (!$group instanceof CommonRouteGroup) {
                    throw new NexusException('Given class does not implement CommonRouteGroup interface');
                }

                $commonRegistrars[] = $container->make($registrar);
            }

            $this->sites->push(
                $site = $container->make(Site::class, [
                    'commonRegistrars' => $commonRegistrars,
                    'domains' => $domains,
                    'name' => $site,
                    'title' => $siteOptions['title'] ?? 'Névtelen',
                    'domainParams' => $params
                ])
            );
        }
    }

    /**
     * Register all routes defined by the Sites
     */
    public function registerRoutes()
    {
        /*
         * Main domain, where the central authentication takes place, can be moved by enviroment,
         * and independent of the sites storage, the asset generator pipeline and much else
         */
        if (file_exists($main = $this->getConfig('directories.routes') . DIRECTORY_SEPARATOR . 'main.php')) {
            $this->router->group([
                'middleware' => ['nexus', 'web'],
                'domain' => $this->getConfig('main_domain'),
                'as' => 'main.',
                'namespace' => $this->getConfig('route_namespace') . '\\Main'
            ], $main);
        }

        /*
         * Resource routes, to handle resources for each site
         * Its needed to avoid eg. golya.sch.bme.hu/js/golya/app.js,
         * instead we can use golya.sch.bme.hu/js/app.js
         */
        foreach ($this->all() as $site) {
            /** @noinspection PhpUndefinedMethodInspection */
            $this->router->nexus([
                'middleware' => ['nexus', 'web'],
                'site' => $site,
            ], __DIR__ . '/../routes/resources.php');
        }

        // Global route group
        $global = $this->getConfig('directories.routes') . DIRECTORY_SEPARATOR . 'global.php';

        if (file_exists($global)) {
            $this->registerGlobalRoute(function ($router) use ($global) {
                include $global;
            });
        }

        foreach ($this->all() as $site) {
            $this->registerGlobalRoute(function ($router) use ($site) {
                $site->registerRoutes($router);
            });
        }
    }

    /**
     * Passes the given closure to a route group with everything setup for nexus
     *
     * @param \Closure $closure
     */
    public function registerGlobalRoute(\Closure $closure)
    {
        $this->router->group([
            'middleware' => ['nexus', 'web'],
            'namespace' => $this->getConfig('route_namespace')
        ], function () use ($closure) {
            $closure($this->router);
        });

        /*
         * Needed because of Route::...->name() declarations
         */
        $this->router->getRoutes()->refreshActionLookups();
        $this->router->getRoutes()->refreshNameLookups();
    }

    /**
     * @param $field
     * @param $value
     * @return Collection
     */
    protected function findBy($field, $value): Collection
    {
        return $this->sites->filter(function (Site $site) use ($field, $value) {
            if (method_exists($site, 'get' . ucfirst($field))) {
                $got = $site->{'get' . ucfirst($field)}();
            } elseif (method_exists($site, 'is' . ucfirst($field))) {
                $got = $site->{'is' . ucfirst($field)}();
            } else {
                $got = null;
            }

            if (is_array($got)) {
                return in_array($value, $got);
            } else {
                return $got == $value;
            }
        });
    }

    /**
     * @return Site
     * @throws NexusException()
     */
    public function current()
    {
        if (is_null($this->request)) {
            throw new NexusException('SiteManager has not been booted');
        }

        return $this->current;
    }

    /**
     * @param string $domain
     * @return Site
     */
    public function getByDomain(string $domain)
    {
        return $this->findBy('domains', $domain)->first();
    }

    /**
     * @param string $slug
     * @return Site
     */
    public function getBySlug(string $slug)
    {
        return $this->findBy('slug', $slug)->first();
    }

    /**
     * @param int $id
     * @return Site
     */
    public function getById(int $id)
    {
        return $this->sites->get($id);
    }

    /**
     * @return Collection|Site[]
     */
    public function all(): Collection
    {
        return $this->sites;
    }

    public function getEnabledSites(): Collection
    {
        return $this->findBy('enabled', true);
    }
}
