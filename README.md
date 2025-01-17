Generates command line commands from your description. Lets you edit and execute it.

# Installation

1. Have Python 3 installed
2. Put `ai.py` somewhere
3. Copy `cmdline-ai-helper.sample` to `~/.cmdline-ai-helper` and fill in your provider, model and API keys. It supports Anthropic, OpenAI, Google and local ollama. 
4. Optionally add an alias to your `.bashrc` or `.bash_profile`

```
alias ai="python path/to/your/ai.py"
```

Tested on macOS with bash.

# Usage

```
$ ai description of your command
```

Press enter, wait. Then edit the resulting command, press Enter to execute, or ESC to cancel.

Examples: https://hachyderm.io/@combatwombat/113765663832357137

![example](https://sc.robsite.net/files/1737156248-Bildschirmfoto_2025-01-17_um_22.22.17.png)
