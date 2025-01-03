<?php

class AI {
    private $config;
    private $configPath;
    private $osType;

    public function __construct() {
        $this->configPath = $_SERVER['HOME'] . '/.php-cmdline-ai.config.json';
        $this->detectOS();
        $this->loadConfig();
    }

    private function detectOS() {
        if (PHP_OS === 'Darwin') {
            $this->osType = 'macos';
        } elseif (PHP_OS === 'WINNT') {
            $this->osType = 'windows';
        } else {
            $this->osType = 'linux';
        }
    }

    private function loadConfig() {
        $defaultConfig = [
            'default_provider' => 'openai',
            'default_model' => 'gpt-4',
            'providers' => [
                'openai' => [
                    'api_key' => '',
                    'endpoint' => 'https://api.openai.com/v1/chat/completions',
                    'models' => ['gpt-4o', 'gpt-4o-mini']
                ],
                'anthropic' => [
                    'api_key' => '',
                    'endpoint' => 'https://api.anthropic.com/v1/messages',
                    'models' => ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest']
                ],
                'google' => [
                    'api_key' => '',
                    'endpoint' => 'https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent',
                    'models' => ['gemini-pro']
                ],
                'ollama' => [
                    'endpoint' => 'http://localhost:11434/api/generate',
                    'models' => ['llama3.1:8b']
                ]
            ]
        ];

        if (!file_exists($this->configPath)) {
            echo "No config file found. Creating default config at {$this->configPath}\n";
            file_put_contents($this->configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT));
            echo "Please edit {$this->configPath} to add your API keys.\n";
            exit(1);
        }

        $this->config = json_decode(file_get_contents($this->configPath), true);
    }

    private function getPrompt($userInput) {
        $osSpecificPrompt = match($this->osType) {
            'macos' => "Convert the following request into a macOS terminal command.",
            'windows' => "Convert the following request into a Windows CMD or PowerShell command.",
            'linux' => "Convert the following request into a Linux shell command."
        };

        return "{$osSpecificPrompt} Return only the command to be executed, nothing else, no markdown or prose. Request: {$userInput}";
    }

    private function callGoogle($prompt, $model) {
        $url = $this->config['providers']['google']['endpoint'] .
            '?key=' . $this->config['providers']['google']['api_key'];

        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 1,
                'topP' => 0.8,
                'maxOutputTokens' => 1024,
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }


    private function callOpenAI($prompt, $model) {
        $ch = curl_init($this->config['providers']['openai']['endpoint']);
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['providers']['openai']['api_key']
            ],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'];
    }

    private function callAnthropic($prompt, $model) {
        $ch = curl_init($this->config['providers']['anthropic']['endpoint']);
        $data = [
            'model' => $model,
            'max_tokens' => 1000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->config['providers']['anthropic']['api_key'],
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        return $result['content'][0]['text'];
    }

    private function callOllama($prompt, $model) {
        $ch = curl_init($this->config['providers']['ollama']['endpoint']);
        $data = [
            'model' => $model,
            'prompt' => $prompt
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        // Split response into lines and get the accumulated response
        $lines = explode("\n", trim($response));
        $fullResponse = '';

        foreach ($lines as $line) {
            if (empty($line)) continue;
            $jsonObj = json_decode($line, true);
            if (isset($jsonObj['response'])) {
                $fullResponse .= $jsonObj['response'];
            }
        }

        // Clean up the response (remove surrounding backticks if present)
        $fullResponse = trim($fullResponse);
        $fullResponse = trim($fullResponse, '`');

        return $fullResponse;
    }

    private function callLLM($prompt) {
        $provider = $this->config['default_provider'];
        $model = $this->config['default_model'];

        switch ($provider) {
            case 'openai':
                return $this->callOpenAI($prompt, $model);
            case 'anthropic':
                return $this->callAnthropic($prompt, $model);
            case 'google':
                return $this->callGoogle($prompt, $model);
            case 'ollama':
                return $this->callOllama($prompt, $model);
            default:
                throw new Exception("Unknown provider: {$provider}");
        }
    }

    private function expandPath($path) {
        // Expand ~ to home directory
        if (strpos($path, '~') === 0) {
            $path = $_SERVER['HOME'] . substr($path, 1);
        }
        return $path;
    }

    public function run($args) {
        // Process arguments and expand file paths
        $processedArgs = [];
        foreach ($args as $arg) {
            if (strpos($arg, '/') !== false || strpos($arg, '\\') !== false) {
                $processedArgs[] = $this->expandPath($arg);
            } else {
                $processedArgs[] = $arg;
            }
        }

        $prompt = implode(' ', $processedArgs);
        $command = $this->callLLM($this->getPrompt($prompt));

        $promptText = "\nEdit command and press Enter to execute, or ESC to cancel:\n";
        echo $promptText;

        // Enable raw input mode
        system('stty -icanon -echo');

        $input = $command;
        $position = strlen($input);

        while (true) {
            // Clear line and show current input
            echo "\r\033[K" . $input; // Clear line and show input

            // Calculate actual terminal position
            $actualPosition = max(0, $position); // Ensure we don't go negative
            echo "\r"; // Return to start of line
            if ($actualPosition > 0) {
                echo "\033[" . $actualPosition . "C"; // Move cursor right by actual position
            }
            flush();

            // Read a character
            $char = fgetc(STDIN);

            if ($char === "\033") {
                // Read additional bytes for special keys
                $char2 = fgetc(STDIN);
                if ($char2 === '[') {
                    $char3 = fgetc(STDIN);
                    switch ($char3) {
                        case 'D': // Left arrow
                            if ($position > 0) {
                                $position--;
                            }
                            continue 2;
                        case 'C': // Right arrow
                            if ($position < strlen($input)) {
                                $position++;
                            }
                            continue 2;
                        case 'H': // Home
                            $position = 0;
                            continue 2;
                        case 'F': // End
                            $position = strlen($input);
                            continue 2;
                        case '3': // Delete key
                            fgetc(STDIN); // Read the trailing ~
                            if ($position < strlen($input)) {
                                $input = substr($input, 0, $position) . substr($input, $position + 1);
                            }
                            continue 2;
                    }
                } else {
                    // Plain ESC pressed
                    system('stty icanon echo'); // Restore terminal
                    echo "\nCancelled.\n";
                    return 0;
                }
            }

            if ($char === "\n") { // Enter key
                break;
            }

            if (ord($char) === 127 || ord($char) === 8) { // Backspace
                if ($position > 0) {
                    $input = substr($input, 0, $position - 1) . substr($input, $position);
                    $position--;
                }
                continue;
            }

            // Regular character input
            if (ord($char) >= 32) { // Printable characters
                $input = substr($input, 0, $position) . $char . substr($input, $position);
                $position++;
            }
        }

        // Restore terminal
        system('stty icanon echo');
        echo "\n";

        if (empty($input)) {
            $input = $command;
        }

        system($input, $returnVal);
        return $returnVal;
    }
}

// Create shell completion script
if (isset($argv[1]) && $argv[1] === '--install-completion') {
    $completionScript = '
# AI command completion
_ai_completion() {
    local cur prev
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"

    # File completion for arguments containing / or starting with .
    if [[ $cur == */* || $cur == .* ]]; then
        COMPREPLY=( $(compgen -f -- "$cur") )
        return 0
    fi
}
complete -F _ai_completion ai
';

    $completionPath = $_SERVER['HOME'] . '/.ai_completion';
    file_put_contents($completionPath, $completionScript);
    echo "Added completion script to {$completionPath}\n";
    echo "Add this line to your ~/.bashrc or ~/.zshrc:\n";
    echo "source ~/.ai_completion\n";
    exit(0);
}

// Main execution
try {
    $ai = new AI();
    $args = array_slice($argv, 1);
    if (empty($args)) {
        echo "Usage: ai <your command description>\n";
        echo "       ai --install-completion (to install shell completion)\n";
        exit(1);
    }
    exit($ai->run($args));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}