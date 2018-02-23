<?php

namespace Tests;

class RoutingTest extends TestCase
{
    public function testGetRoutes()
    {
        $this->get($this->url('foo', '/foo/lol'))
            ->assertSuccessful()
            ->assertSee('lol')
            ->assertDontSee('asd')
        ;

        $this->get($this->url('foo', '/foo/asd'))
            ->assertSuccessful()
            ->assertSee('asd')
            ->assertDontSee('lol')
        ;
    }

    public function testPostRoutesDenied()
    {
        $this->post($this->url('foo', '/foo/lol'))->assertStatus(405);
    }

    public function testCommonRouteGroup()
    {
        $this->get($this->url('bar', '/custom'))
            ->assertSuccessful()
            ->assertSee('custom.bar')
        ;
    }
}