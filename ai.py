#!/usr/bin/env python3
import os
import sys
import json
import platform
import subprocess
import tty
import termios
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path

class AI:
    def __init__(self):
        self.config_path = Path.home() / '.cmdline-ai-helper'
        self.detect_os()
        self.load_config()

    def detect_os(self):
        system = platform.system()
        if system == 'Darwin':
            self.os_type = 'macos'
        elif system == 'Windows':
            self.os_type = 'windows'
        else:
            self.os_type = 'linux'

    def load_config(self):
        if not self.config_path.exists():
            raise FileNotFoundError(
                f"No config file found at {self.config_path}\n"
                "Please copy .cmdline-ai-helper.sample to ~/.cmdline-ai-helper and edit it"
            )

        self.config = {}
        required_keys = {'DEFAULT_PROVIDER', 'DEFAULT_MODEL'}

        with open(self.config_path) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#'):
                    try:
                        key, value = line.split('=', 1)
                        self.config[key.strip()] = value.strip()
                    except ValueError:
                        print(f"Warning: Ignoring invalid config line: {line}")

        missing_keys = required_keys - self.config.keys()
        if missing_keys:
            raise ValueError(f"Missing required config keys: {missing_keys}")

    def make_request(self, url, headers, data):
        try:
            data = json.dumps(data).encode('utf-8')
            req = urllib.request.Request(
                url,
                data=data,
                headers=headers,
                method='POST'
            )
            try:
                with urllib.request.urlopen(req) as response:
                    return json.loads(response.read().decode('utf-8'))
            except urllib.error.HTTPError as e:
                error_message = e.read().decode('utf-8')
                raise Exception(f"API request failed: {error_message}")
            except json.JSONDecodeError:
                raise Exception("Failed to parse API response")
        except Exception as e:
            raise Exception(f"Request failed: {str(e)}")

    def get_prompt(self, user_input):
        os_prompts = {
            'macos': "Convert the following request into a macOS terminal command. Use macOS-compatible syntax (avoid bash-specific features like ${var,,} for lowercase conversion). ",
            'windows': "Convert the following request into a Windows CMD or PowerShell command.",
            'linux': "Convert the following request into a Linux shell command."
        }
        general_prompt = "Return only the command to be executed, nothing else, no markdown, formatting or prose. Only add the lolcat command if something colorful, fun or lolcat directly is requested. If so, add the -f option to lolcat."
        return f"{os_prompts[self.os_type]} {general_prompt} Request: {user_input}"

    def call_google(self, prompt):
        url = f"{self.config['GOOGLE_ENDPOINT']}?key={self.config['GOOGLE_API_KEY']}"
        data = {
            'contents': [{'parts': [{'text': prompt}]}],
            'generationConfig': {
                'temperature': 0.7,
                'topK': 1,
                'topP': 0.8,
                'maxOutputTokens': 1024,
            }
        }
        headers = {'Content-Type': 'application/json'}
        result = self.make_request(url, headers, data)
        return result['candidates'][0]['content']['parts'][0]['text']

    def call_openai(self, prompt):
        headers = {
            'Content-Type': 'application/json',
            'Authorization': f"Bearer {self.config['OPENAI_API_KEY']}"
        }
        data = {
            'model': self.config['DEFAULT_MODEL'],
            'messages': [{'role': 'user', 'content': prompt}],
            'temperature': 0.7
        }
        result = self.make_request(self.config['OPENAI_ENDPOINT'], headers, data)
        return result['choices'][0]['message']['content']

    def call_anthropic(self, prompt):
        headers = {
            'Content-Type': 'application/json',
            'x-api-key': self.config['ANTHROPIC_API_KEY'],
            'anthropic-version': '2023-06-01'
        }
        data = {
            'model': self.config['DEFAULT_MODEL'],
            'max_tokens': 1000,
            'messages': [{'role': 'user', 'content': prompt}]
        }
        result = self.make_request(self.config['ANTHROPIC_ENDPOINT'], headers, data)
        return result['content'][0]['text']

    def call_ollama(self, prompt):
        headers = {'Content-Type': 'application/json'}
        data = {
            'model': self.config['DEFAULT_MODEL'],
            'prompt': prompt
        }
        response = self.make_request(self.config['OLLAMA_ENDPOINT'], headers, data)
        if isinstance(response, str):
            return response.strip().strip('`')

        full_response = ''
        try:
            if isinstance(response, dict) and 'response' in response:
                return response['response'].strip().strip('`')
            for line in response.get('text', '').strip().split('\n'):
                if line:
                    json_obj = json.loads(line)
                    if 'response' in json_obj:
                        full_response += json_obj['response']
            return full_response.strip().strip('`')
        except (json.JSONDecodeError, AttributeError) as e:
            raise Exception(f"Failed to parse Ollama response: {e}")

    def call_llm(self, prompt):
        provider = self.config['DEFAULT_PROVIDER']
        if provider == 'openai':
            return self.call_openai(prompt)
        elif provider == 'anthropic':
            return self.call_anthropic(prompt)
        elif provider == 'google':
            return self.call_google(prompt)
        elif provider == 'ollama':
            return self.call_ollama(prompt)
        else:
            raise Exception(f"Unknown provider: {provider}")

    def get_char(self):
        fd = sys.stdin.fileno()
        old_settings = termios.tcgetattr(fd)
        try:
            tty.setraw(fd)
            ch = sys.stdin.read(1)
        finally:
            termios.tcsetattr(fd, termios.TCSADRAIN, old_settings)
        return ch

    def run(self, args):
        # Join args with spaces and escape special characters
        prompt = ' '.join(args)
        command = self.call_llm(self.get_prompt(prompt))

        print("\nEdit command and press Enter to execute, or ESC to cancel:\n")
        input_text = command
        position = len(input_text)

        # Enable raw mode
        fd = sys.stdin.fileno()
        old_settings = termios.tcgetattr(fd)
        try:
            while True:
                # Clear line and show current input
                sys.stdout.write('\r')
                sys.stdout.write('\033[K')  # Clear to end of line
                sys.stdout.write(input_text)
                # Move cursor to position
                sys.stdout.write('\r')
                if position > 0:
                    sys.stdout.write(f'\033[{position}C')
                sys.stdout.flush()

                char = self.get_char()

                if ord(char) == 27:  # ESC
                    char2 = self.get_char()
                    if char2 == '[':
                        char3 = self.get_char()
                        if char3 == 'D':  # Left arrow
                            position = max(0, position - 1)
                        elif char3 == 'C':  # Right arrow
                            position = min(len(input_text), position + 1)
                        elif char3 == 'H':  # Home
                            position = 0
                        elif char3 == 'F':  # End
                            position = len(input_text)
                        elif char3 == '3':  # Delete
                            self.get_char()  # Read the trailing ~
                            if position < len(input_text):
                                input_text = input_text[:position] + input_text[position+1:]
                    else:
                        print("\nCancelled.")
                        return 0

                elif ord(char) == 13:  # Enter
                    break

                elif ord(char) in (127, 8):  # Backspace
                    if position > 0:
                        input_text = input_text[:position-1] + input_text[position:]
                        position -= 1

                elif ord(char) >= 32:  # Printable characters
                    input_text = input_text[:position] + char + input_text[position:]
                    position += 1

        finally:
            termios.tcsetattr(fd, termios.TCSADRAIN, old_settings)

        print()
        if not input_text:
            input_text = command

        # Execute the command
        env = os.environ.copy()
        env['TERM'] = 'xterm-256color'

        # Escape the entire command for shell execution
        try:
            return subprocess.call(['bash', '-c', input_text], env=env)
        except subprocess.CalledProcessError as e:
            print(f"Command execution failed: {e}")
            return 1

def main():
    try:
        ai = AI()
        args = sys.argv[1:]
        if not args:
            print("Usage: ai <your command description>")
            sys.exit(1)
        sys.exit(ai.run(args))
    except Exception as e:
        print(f"Error: {str(e)}")
        sys.exit(1)

if __name__ == '__main__':
    main()