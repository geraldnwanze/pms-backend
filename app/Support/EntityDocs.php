<?php

namespace App\Support;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Gives each generic REST resource route its own title and description in the API docs: what it lists, who may read
 * or change it, and which module it belongs to. They all share one controller, so without this every one reads alike.
 */
final class EntityDocs
{
    private const ACTIONS = [
        'index' => ['List %s', 'Returns a page of %s the signed-in user can see, newest first. Filter and page with the query string.'],
        'store' => ['Create a %s', 'Creates one record. Fields are validated against the resource; unknown fields are ignored.'],
        'show' => ['Show a %s', 'Returns one record, or 404 when it does not exist or the signed-in user cannot see it.'],
        'update' => ['Update a %s', 'Changes the given fields of one record.'],
        'destroy' => ['Delete a %s', 'Removes one record.'],
    ];

    public static function apply(Operation $operation, RouteInfo $route): void
    {
        $resource = $route->route->defaults['resource'] ?? null;
        $action = $route->route->getActionMethod();

        if (! is_string($resource) || ! isset(self::ACTIONS[$action])) {
            return;
        }

        $plural = str_replace('-', ' ', $resource);
        $singular = Str::singular($plural);
        [$title, $text] = self::ACTIONS[$action];

        $operation->summary(sprintf($title, $action === 'index' ? $plural : $singular));
        $operation->description(self::describe($resource, $action, sprintf($text, $plural)));
    }

    public static function describe(string $resource, string $action, string $text): string
    {
        $access = config("rbac.resources.{$resource}");
        $kind = $action === 'index' || $action === 'show' ? 'view' : 'manage';
        $roles = $access[$kind] ?? [];

        $lines = [$text];
        if ($roles !== []) {
            $lines[] = '**Allowed roles:** '.implode(', ', array_map(fn ($r) => "`{$r}`", $roles)).'.';
        }
        if ($module = Modules::forResource($resource)) {
            $lines[] = '**Module:** '.Modules::DEFINITIONS[$module]['label']." (`{$module}`). Answers 403 with `module` set while a superadmin has it turned off.";
        }

        return implode("\n\n", $lines);
    }
}
