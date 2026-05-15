# LSCache Documentation Examples

These examples are runnable snippets for integrators adopting `skom/lscache` in a
TYPO3 14 project. Run shell and Composer commands from the repository or project
root unless an example states otherwise.

| Scenario | File | Runtime context |
|---|---|---|
| Install the extension and include the Site Set | [quickstart-install.md](quickstart-install.md) | TYPO3 project root with Composer |
| Tune site settings for a normal production site | [site-settings-tuning.md](site-settings-tuning.md) | `config/sites/<site-id>/settings.yaml` |
| Observe purge events from custom project code | [custom-purge-listener.php](custom-purge-listener.php) | Project or site-package `Classes/EventListener/` |
| Trigger dry-run, tag, full-site, and status CLI checks | [cli-triggered-purge.sh](cli-triggered-purge.sh) | POSIX shell from TYPO3 project root |
| Keep preview/private responses out of LSCache | [bypass-rule-recipe.yaml](bypass-rule-recipe.yaml) | `config/sites/<site-id>/settings.yaml` |

Validation is enforced by the root-level `composer ci:docs:validate` script.
