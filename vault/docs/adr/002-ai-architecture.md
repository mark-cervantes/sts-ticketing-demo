# ADR-002: AI / Summary Generation Architecture

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment requires async summary generation with a clean interface and genuine fallback.

## Decision

Use **Laravel Manager pattern + Facade + Strategy** with two drivers behind a single interface.

```
Summary (Facade — app code calls this)
  └── SummaryManager (extends Illuminate\Support\Manager)
        └── resolves driver via config
              ├── LlmDriver implements SummaryGeneratorInterface
              └── RulesDriver implements SummaryGeneratorInterface
```

The Manager pattern is the same pattern Laravel uses for Cache, Queue, Mail,
and Filesystem. It provides idiomatic driver resolution, explicit driver
selection, and testability via facade mocking.

### Driver Resolution
1. Config `SUMMARY_DRIVER=llm|rules` selects the primary driver
2. If `llm` selected but no `LLM_API_KEY` → auto-fallback to `rules`
3. If LLM call fails after retries → fallback to `rules` for that job

### LLM Driver
- Uses OpenAI-compatible `POST /v1/chat/completions` endpoint
- Base URL, API key, and model are env-configurable
- Works with Ollama Cloud, OpenRouter, OpenAI, or any compatible API
- Prompt template committed to `config/prompts/summary.php`

### Rules-Based Driver
- Deterministic: category keywords + priority level + description analysis
- Always succeeds — no external dependency
- Produces genuinely useful output (not "This is a billing issue.")

## Rationale

**Facade** — application code never touches drivers directly. Changing AI provider =
config change, not code change. This is the "clean seam" the assessment looks for.

**Strategy pattern** — both drivers implement the same interface. Adding a third
driver (e.g., local Ollama, different LLM) = one new class, one config value.

**Auto-fallback** — the assessment explicitly says "The app must still run locally
without an API key by falling back to the rules-based path." Our fallback is
automatic and genuine, not a stub.

**OpenAI-compatible API** — Ollama Cloud, OpenRouter, and OpenAI all speak the same
protocol. One HTTP client implementation covers all three. Env vars select the
provider.

## Consequences

- Prompt template must be maintained as a committed file
- Rules engine must produce quality output (not throwaway placeholders)
- Retry logic lives in the job, not the driver (drivers are single-attempt)
- Need integration test that actually runs the rules engine end-to-end

## Retry Policy

| Attempt | Delay | Action on Failure                        |
| ------- | ----- | ---------------------------------------- |
| 1       | 0s    | Try LLM                                 |
| 2       | 10s   | Retry LLM                               |
| 3       | 30s   | Retry LLM                               |
| 4       | —     | Fallback to rules engine, mark as ready  |

If rules engine also fails (shouldn't — it's deterministic): mark as `failed`.
