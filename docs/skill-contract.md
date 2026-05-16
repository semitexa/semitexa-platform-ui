# `#[AsAiSkill]` contract

platform-ui integrates with `semitexa/llm` via the **tool-use model**: commands annotated with `#[AsAiSkill]` are automatically discoverable by the Planner (`semitexa/llm/src/Planner/`) and executable by the `SkillExecutor`. Users interact in prose ("create a skin about sunset at sea"); the LLM routes to `skins:generate`.

## Skills shipped in v1

Inspect the live registry:
```bash
bin/semitexa ai:skills --json
```

### `skins:generate`

- **riskLevel**: `Low`
- **confirmation**: `WhenMutating` (asks before `--write`)
- **supportsDryRun**: `true`
- **argumentPolicy**: `Allowlisted` — `algorithm`, `hex`, `prompt`, `name`, `mode`, `write`
- **executionKind**: `DirectCommand`

### `skins:explain-prompt`

- **riskLevel**: `Low`
- **confirmation**: `Never` (read-only)
- **supportsDryRun**: `false`
- **argumentPolicy**: `Allowlisted` — `prompt` (required)

### `skins:refine` — not a skill

Refinement is an operator tool, not a Planner route. `skin:refine` has no `#[AsAiSkill]` attribute, so prose like "make it darker" reaches `skin:generate` (fresh regeneration from prompt) rather than `skin:refine`. Add the attribute in a consumer project if you want LLM-driven refinement routing.

## Adding a skill to a consumer project

Any Console command can become a skill. Annotate with `#[AsAiSkill]` and the registry picks it up.

```php
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Policy\{AiRiskLevel, AiConfirmationMode, AiArgumentPolicy, AiExecutionKind};

#[AsCommand(name: 'my-app:report:generate', description: '...')]
#[AsAiSkill(
    summary: 'Generate a PDF report for a given period.',
    useWhen: 'user asks for a report, PDF export, period summary',
    avoidWhen: 'user just wants to preview data — use my-app:report:show',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::WhenMutating,
    supportsDryRun: true,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['period', 'format', 'output'],
    executionKind: AiExecutionKind::DirectCommand,
)]
final class GenerateReportCommand extends Command { /* ... */ }
```

## Internal LLM consumption (not skill-based)

When a platform-ui command needs to call the LLM **inside** its own logic (not via Planner), it depends on `LlmProviderInterface` directly:

```php
public function __construct(private readonly LlmProviderInterface $provider) {
    parent::__construct();
}
```

`PromptResolver` in platform-ui uses exactly this pattern — the `--prompt` mode of `skin:generate` is a normal CLI invocation that reaches into the LLM internally, not a Planner decision.

## Reproducibility guarantee

Every LLM-generated skin includes its full provenance in `skin.json`:

```json
{
  "source": "prompt",
  "prompt": "...",
  "llm": {
    "skill": "platform-ui.skin.resolve-prompt",
    "skill_version": "1.0",
    "model": "gemma4:e2b",
    "attempts": 1,
    "rationale": "..."
  },
  "algorithm": "brutalist",
  "seed": "#d93025",
  "knobs": { "shadow_offset": "pronounced", "contrast_boost": "high", "shadow_color_mode": "brand" },
  "mode": "light",
  "tokens": { /* ... */ }
}
```

Any LLM-generated skin can be re-generated offline via `skin:generate <algorithm> "<resolved.seed>" --knob=…` without touching an LLM. The LLM is a UX layer, never a load-bearing dependency. `history[]` on the manifest records every generate + refine event on the skin — see [skin-refinement.md](skin-refinement.md).
