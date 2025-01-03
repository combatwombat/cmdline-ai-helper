

# Installation

1. Have PHP installed
2. Put ai.php somewhere
3. Optionally add an alias to your `.bashrc` or `.bash_profile`

```
alias ai="php path/to/your/ai.php"
```

When it's first called, it creates a config file in `~/.php-cmdline-ai.config.json`. Edit this with your API keys and set your default provider and model. It supports Anthropic, OpenAI, Google and local ollama.

Tested on macOS with bash.

# Usage

```
$ ai description of your command
```

Press enter, wait. Then edit the resulting command, press Enter to execute, or ESC to cancel.
