# Local MCP Boundary

Phase 6C proves the Model Context Protocol boundary without exposing CMS data or actions. The `n3/mcp-server` module is disabled by default, has no migration, opens no socket, and registers no HTTP route.

## Protocol and transport

The server implements the stateless MCP revision `2026-07-28` over newline-delimited standard input/output. It supports only:

- `server/discover`;
- `tools/list`;
- `tools/call` for the single approved tool.

Every request must include `io.modelcontextprotocol/protocolVersion` and an object-valued `io.modelcontextprotocol/clientCapabilities` inside `params._meta`. Unsupported versions return the MCP `-32022` error with the one supported version. Responses include the server identity in result metadata. Notifications receive no response.

Each input message is bounded to 1 MiB. Arrays/batches, malformed JSON-RPC messages, missing metadata, cursors, unsupported fields, unknown methods/tools, and invalid tool arguments return controlled errors. Standard output contains protocol messages only. The process may write controlled diagnostics to standard error and exits when input closes.

## Trust and authorization

Only local `stdio` is approved. The client launches N3 as a subprocess, so the operating-system account and reviewed process configuration are the trust boundary. There is no MCP HTTP authorization flow for this transport and no remote listener.

Enabling the module does not authorize future capabilities. Each new tool requires a separate review covering caller authorization, user consent, input/output schemas, data minimization, audit events, rate limits, timeouts, failure behavior, and tests. Credentials must never be passed as command arguments or emitted through MCP.

The current fixed-window limiter permits 60 valid calls to the known tool per 60 seconds within one server process. This is a bounded abuse control for the data-free local probe, not an identity-aware production rate limit.

## Approved tool

`n3_system_status` takes a strict empty object and returns exactly:

```json
{"status":"available"}
```

The value means only that the local MCP process handled the call. The tool does not inspect or reveal Core version, environment, modules, database health, hostnames, files, content, accounts, sessions, configuration, network state, logs, or secrets. It is read-only, idempotent, and closed-world.

## Enable and run

Set the real deployment environment variable and follow the reviewed module workflow:

```bash
export MCP_ENABLED=true
php bin/n3 module:migrate:status
php bin/n3 module:status
php bin/n3 module:sync --apply
php bin/n3 mcp:serve --stdio
```

`mcp:serve` refuses to start without both `MCP_ENABLED=true` and the explicit `--stdio` option. The command must be configured directly in the trusted MCP client; do not place a shell, web server, or network relay in front of it.

## Deferred

Streamable HTTP, OAuth, API credentials, remote transport, prompts, resources, subscriptions, tasks, sampling, elicitation, CMS/database/file access, identity-aware tools, mutations, and external services are not implemented. Adding any of them is a new milestone, not configuration of Phase 6C.
