<?php

namespace App\Providers;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\UrlWindow;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\ResponseFactory;
use League\Glide\Server;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerInertia();
        $this->registerGlide();
        $this->registerLengthAwarePaginator();

        ResponseFactory::macro('decorate', function ($response, $data, $defaultRedirect) {
//            if (false && request()->hasHeader('X-Inertia')) {
//                request()->headers->set('X-Inertia-Partial-Data', implode(',', array_merge(['referer'], array_keys($data))));
//                request()->headers->set('X-Inertia-Partial-Component', $response->toResponse(request())->getData()->component);
//            }

            session()->put('X-Inertia-Referer', request()->headers->get('referer') ?? $defaultRedirect);

            return $response->with(array_merge([
                'referer' => session('X-Inertia-Referer')
            ], $data));
        });

        ResponseFactory::macro('back', function () {
            return session('X-Inertia-Referer') ? redirect(session('X-Inertia-Referer')) : redirect()->back();
        });

        ResponseFactory::macro('partial', function ($data, $uri) {
            $response = app(Router::class)->getRoutes()->match(\Illuminate\Http\Request::create($uri))->run();

            if (request()->hasHeader('X-Inertia')) {
                request()->headers->set('X-Inertia-Partial-Data', implode(',', array_merge(['referer'], array_keys($data))));
                request()->headers->set('X-Inertia-Partial-Component', $response->toResponse(request())->getData()->component);
            }

            $uri = request()->headers->get('referer') ?? $uri;
            session()->put('X-Inertia-Referer', $uri);

            $path = parse_url($uri);

            if ($path && isset($path['query'])) {
                parse_str($path['query'], $params);
                request()->merge($params);
            }

            return $response
                ->with(array_merge([
                    'referer' => $uri
                ], $data));
        });
    }

    public function registerInertia()
    {
        Inertia::version(function () {
            return md5_file(public_path('mix-manifest.json'));
        });

        Inertia::share([
            'auth' => function () {
                return [
                    'user' => Auth::user() ? [
                        'id' => Auth::user()->id,
                        'first_name' => Auth::user()->first_name,
                        'last_name' => Auth::user()->last_name,
                        'email' => Auth::user()->email,
                        'role' => Auth::user()->role,
                        'account' => [
                            'id' => Auth::user()->account->id,
                            'name' => Auth::user()->account->name,
                        ],
                    ] : null,
                ];
            },
            'flash' => function () {
                return [
                    'success' => Session::get('success'),
                    'error' => Session::get('error'),
                ];
            },
            'errors' => function () {
                return Session::get('errors')
                    ? Session::get('errors')->getBag('default')->getMessages()
                    : (object)[];
            },
        ]);
    }

    protected function registerGlide()
    {
        $this->app->bind(Server::class, function ($app) {
            return Server::create([
                'source' => Storage::getDriver(),
                'cache' => Storage::getDriver(),
                'cache_folder' => '.glide-cache',
                'base_url' => 'img',
            ]);
        });
    }

    protected function registerLengthAwarePaginator()
    {
        $this->app->bind(LengthAwarePaginator::class, function ($app, $values) {
            return new class(...array_values($values)) extends LengthAwarePaginator {
                public function only(...$attributes)
                {
                    return $this->transform(function ($item) use ($attributes) {
                        return $item->only($attributes);
                    });
                }

                public function transform($callback)
                {
                    $this->items->transform($callback);

                    return $this;
                }

                public function toArray()
                {
                    return [
                        'data' => $this->items->toArray(),
                        'links' => $this->links(),
                    ];
                }

                public function links($view = null, $data = [])
                {
                    $this->appends(Request::all());

                    $window = UrlWindow::make($this);

                    $elements = array_filter([
                        $window['first'],
                        is_array($window['slider']) ? '...' : null,
                        $window['slider'],
                        is_array($window['last']) ? '...' : null,
                        $window['last'],
                    ]);

                    return Collection::make($elements)->flatMap(function ($item) {
                        if (is_array($item)) {
                            return Collection::make($item)->map(function ($url, $page) {
                                return [
                                    'url' => $url,
                                    'label' => $page,
                                    'active' => $this->currentPage() === $page,
                                ];
                            });
                        } else {
                            return [
                                [
                                    'url' => null,
                                    'label' => '...',
                                    'active' => false,
                                ],
                            ];
                        }
                    })->prepend([
                        'url' => $this->previousPageUrl(),
                        'label' => 'Previous',
                        'active' => false,
                    ])->push([
                        'url' => $this->nextPageUrl(),
                        'label' => 'Next',
                        'active' => false,
                    ]);
                }
            };
        });
    }
}
