#!/usr/bin/env php
<?php

class AI {
    private $configPath;
    private $config;
    private $osType;

    public function __construct() {
        $this->configPath = $_SERVER['HOME'] . '/.cmdline-ai-helper';
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
        if (!file_exists($this->configPath)) {
            throw new Exception(
                "No config file found at {$this->configPath}\n" .
                "Please copy .cmdline-ai-helper.sample to ~/.cmdline-ai-helper and edit it"
            );
        }

        $this->config = [];
        $requiredKeys = ['DEFAULT_PROVIDER', 'DEFAULT_MODEL'];

        $lines = file($this->configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && !str_starts_with($line, '#')) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $this->config[trim($parts[0])] = trim($parts[1]);
                } else {
                    echo "Warning: Ignoring invalid config line: {$line}\n";
                }
            }
        }

        $missingKeys = array_diff($requiredKeys, array_keys($this->config));
        if (!empty($missingKeys)) {
            throw new Exception("Missing required config keys: " . implode(', ', $missingKeys));
        }
    }

    private function makeRequest($url, $headers, $data) {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($data)
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("API request failed: {$response}");
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Failed to parse API response");
            }

            return $result;
        } catch (Exception $e) {
            throw new Exception("Request failed: " . $e->getMessage());
        }
    }

    private function getPrompt($userInput) {
        $osPrompts = [
            'macos' => "Convert the following request into a macOS terminal command. Use macOS-compatible syntax (avoid bash-specific features like \${var,,} for lowercase conversion). ",
            'windows' => "Convert the following request into a Windows CMD or PowerShell command.",
            'linux' => "Convert the following request into a Linux shell command."
        ];
        $generalPrompt = "Return only the command to be executed, nothing else, no markdown, formatting or prose. Only add the lolcat command if something colorful, fun or lolcat directly is requested. If so, add the -f option to lolcat.";
        return $osPrompts[$this->osType] . " " . $generalPrompt . " Request: " . $userInput;
    }

    private function callGoogle($prompt) {
        $url = "{$this->config['GOOGLE_ENDPOINT']}?key={$this->config['GOOGLE_API_KEY']}";
        $data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 1,
                'topP' => 0.8,
                'maxOutputTokens' => 1024,
            ]
        ];
        $headers = ['Content-Type: application/json'];
        $result = $this->makeRequest($url, $headers, $data);
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }

    private function callOpenAI($prompt) {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config['OPENAI_API_KEY']
        ];
        $data = [
            'model' => $this->config['DEFAULT_MODEL'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7
        ];
        $result = $this->makeRequest($this->config['OPENAI_ENDPOINT'], $headers, $data);
        return $result['choices'][0]['message']['content'];
    }

    private function callAnthropic($prompt) {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->config['ANTHROPIC_API_KEY'],
            'anthropic-version: 2023-06-01'
        ];
        $data = [
            'model' => $this->config['DEFAULT_MODEL'],
            'max_tokens' => 1000,
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ];
        $result = $this->makeRequest($this->config['ANTHROPIC_ENDPOINT'], $headers, $data);
        return $result['content'][0]['text'];
    }

    private function callOllama($prompt) {
        $headers = ['Content-Type: application/json'];
        $data = [
            'model' => $this->config['DEFAULT_MODEL'],
            'prompt' => $prompt
        ];
        $response = $this->makeRequest($this->config['OLLAMA_ENDPOINT'], $headers, $data);

        if (is_string($response)) {
            return trim(trim($response, '`'));
        }

        $fullResponse = '';
        try {
            if (isset($response['response'])) {
                return trim(trim($response['response'], '`'));
            }
            foreach (explode("\n", $response['text'] ?? '') as $line) {
                if ($line) {
                    $jsonObj = json_decode($line, true);
                    if (isset($jsonObj['response'])) {
                        $fullResponse .= $jsonObj['response'];
                    }
                }
            }
            return trim(trim($fullResponse, '`'));
        } catch (Exception $e) {
            throw new Exception("Failed to parse Ollama response: " . $e->getMessage());
        }
    }

    private function callLLM($prompt) {
        switch ($this->config['DEFAULT_PROVIDER']) {
            case 'openai':
                return $this->callOpenAI($prompt);
            case 'anthropic':
                return $this->callAnthropic($prompt);
            case 'google':
                return $this->callGoogle($prompt);
            case 'ollama':
                return $this->callOllama($prompt);
            default:
                throw new Exception("Unknown provider: " . $this->config['DEFAULT_PROVIDER']);
        }
    }

    private function getChar() {
        system('stty -icanon -echo');
        $char = fgetc(STDIN);
        system('stty icanon echo');
        return $char;
    }

    public function run($args) {
        $prompt = implode(' ', $args);
        $command = $this->callLLM($this->getPrompt($prompt));

        echo "\nEdit command and press Enter to execute, or ESC to cancel:\n\n";
        $inputText = $command;
        $position = strlen($inputText);

        // Enable raw mode
        system('stty -icanon -echo');

        try {
            while (true) {
                // Clear line and show current input
                echo "\r\033[K" . $inputText;
                echo "\r";
                if ($position > 0) {
                    echo "\033[{$position}C";
                }
                flush();

                $char = fgetc(STDIN);

                if (ord($char) === 27) { // ESC
                    $char2 = fgetc(STDIN);
                    if ($char2 === '[') {
                        $char3 = fgetc(STDIN);
                        switch ($char3) {
                            case 'D': // Left arrow
                                $position = max(0, $position - 1);
                                break;
                            case 'C': // Right arrow
                                $position = min(strlen($inputText), $position + 1);
                                break;
                            case 'H': // Home
                                $position = 0;
                                break;
                            case 'F': // End
                                $position = strlen($inputText);
                                break;
                            case '3': // Delete
                                fgetc(STDIN); // Read trailing ~
                                if ($position < strlen($inputText)) {
                                    $inputText = substr($inputText, 0, $position) . substr($inputText, $position + 1);
                                }
                                break;
                        }
                    } else {
                        echo "\nCancelled.\n";
                        return 0;
                    }
                } elseif (ord($char) === 10 || ord($char) === 13) { // Enter key (LF or CR)
                    break;
                } elseif (ord($char) === 127 || ord($char) === 8) { // Backspace
                    if ($position > 0) {
                        $inputText = substr($inputText, 0, $position - 1) . substr($inputText, $position);
                        $position--;
                    }
                } elseif (ord($char) >= 32) { // Printable characters
                    $inputText = substr($inputText, 0, $position) . $char . substr($inputText, $position);
                    $position++;
                }
            }
        } finally {
            // Restore terminal settings
            system('stty icanon echo');
        }

        echo "\n";
        if (empty($inputText)) {
            $inputText = $command;
        }

        // Execute the command
        putenv("TERM=xterm-256color");
        $returnVal = 0;
        passthru("bash -c " . escapeshellarg($inputText), $returnVal);
        return $returnVal;
    }
}

function main() {
    try {
        global $argv;  // Add this line to access $argv
        $ai = new AI();
        $args = array_slice($argv, 1);
        if (empty($args)) {
            echo "Usage: ai <your command description>\n";
            exit(1);
        }
        exit($ai->run($args));
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (PHP_SAPI === 'cli') {
    main();
}