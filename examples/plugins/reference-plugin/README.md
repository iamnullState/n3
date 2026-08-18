# Reference plugin

This is a non-production example of the supported n3 plugin contract. It lives outside the production `plugins/` directory so it cannot be discovered or executed accidentally. Plugins are trusted local code with the same privileges as n3; review every file before installing one.

The example is disabled in its manifest by default and demonstrates:

- manifest metadata, a dashboard widget, a structured primary-navigation item, and declared CSS/JavaScript;
- manifest-declared structured profile tools/cards and a Page information row using only viewer-authorized context;
- an authenticated parameterized `GET` route using route, query, and header accessors;
- a parameterized `POST` route using the JSON-body accessor and array response normalization;
- an explicit error `Response` for invalid JSON;
- the core authentication and CSRF boundary around plugin routes.
- a separately declared, prefix-scoped public `GET`/`HEAD` hook using `PublicPluginRegistry` and `PluginContext`.

For local development, copy the complete `reference-plugin` directory into the configured plugin directory, reload n3, and enable **Reference plugin** from **Plugin management**. With the default Compose configuration, modify the host `plugins/` directory rather than the read-only path inside the container.

After signing in, the read route is available at:

```text
GET /api/plugins/reference-plugin/items/example?view=detail
```

The mutation route requires the CSRF token returned by `/api/bootstrap`:

```js
const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
const response = await fetch('/api/plugins/reference-plugin/items/example/events', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': bootstrap.csrfToken,
  },
  body: JSON.stringify({event: 'reviewed'}),
});
console.log(await response.json());
```

Plugin handlers should depend only on the documented `Request`, `Response`, authenticated user, and `PluginRegistry` contract. Do not depend on PHP globals or procedural helpers from `src/routes.php`.

The three contribution handlers demonstrate the authenticated-only slots. Their text is escaped by core n3 and their links stay under `/api/plugins/reference-plugin`. Anonymous public profiles and articles do not execute or render these handlers.

When explicitly enabled in the isolated reference test, the public hook is available at `GET /reference-plugin-public/status`. Only `public.php` executes for that anonymous prefix; the authenticated bootstrap, contributions, and private assets remain outside the public lifecycle.
