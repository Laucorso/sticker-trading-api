<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * En los tests la aplicación se reutiliza entre peticiones y el guard guarda el
     * primer usuario resuelto. Se olvida en cada petición para que la cabecera
     * X-User-Id de cada llamada mande, igual que en una petición real.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
