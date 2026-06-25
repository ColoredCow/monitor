<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use ReflectionClass;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Override the assertInertia macro to use JSON_PRESERVE_ZERO_FRACTION so
        // that whole-number floats (e.g. 50.0) survive the json_encode/json_decode
        // roundtrip in the Inertia test harness and stay float rather than int.
        TestResponse::macro('assertInertia', function (?callable $callback = null) {
            /** @var \Illuminate\Testing\TestResponse $this */
            $pageData = $this->viewData('page');
            $page = json_decode(
                json_encode($pageData, JSON_PRESERVE_ZERO_FRACTION),
                true
            );

            $assert = AssertableInertia::fromArray($page['props'] ?? []);

            $ref = new ReflectionClass($assert);

            $componentProp = $ref->getProperty('component');
            $componentProp->setAccessible(true);
            $componentProp->setValue($assert, $page['component'] ?? null);

            $urlProp = $ref->getProperty('url');
            $urlProp->setAccessible(true);
            $urlProp->setValue($assert, $page['url'] ?? null);

            $versionProp = $ref->getProperty('version');
            $versionProp->setAccessible(true);
            $versionProp->setValue($assert, $page['version'] ?? null);

            if ($callback !== null) {
                $callback($assert);
            }

            return $this;
        });
    }
}
