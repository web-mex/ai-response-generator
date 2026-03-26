# AI Response Generator Plugin for osTicket

Fork maintained by Stefan Schneider / Web-Mex.

This plugin adds an AI-powered "Generate Response" button to the agent ticket view in osTicket. It allows agents to generate suggested replies using an OpenAI-compatible API and optionally enriches responses with custom context (RAG content).

This fork includes deployment fixes for osTicket plugin asset loading and UX improvements such as visible loading feedback during response generation.

## Features

- Adds a "Generate AI Response" button to each ticket for agents
- **Supports multiple plugin instances:** You can add and configure multiple instances of the plugin, each with its own API URL, key, model, and settings. This allows you to use different AI providers or configurations for different teams or workflows.
- Configurable API URL, API key, and model
- Optional system prompt and response template
- Supports pasting additional context (RAG content) to enrich AI responses
- Secure API key storage (with PasswordField)

## Requirements

- osTicket (latest stable version recommended)
- Access to an OpenAI-compatible API (e.g., OpenAI, OpenRouter, or local Ollama server)

## Installation

1. Copy the plugin folder to your osTicket `include/plugins/` directory.
2. In the osTicket admin panel, go to **Manage → Plugins**.
3. Click **Add New Plugin** and select **AI Response Generator**.
4. Configure the plugin:
    - Set the API URL (e.g., `https://api.openai.com/v1`)
    - Enter your API key
    - Specify the model (e.g., `gpt-4.1-mini`)
    - (Optional) Add a system prompt or response template
    - (Optional) Paste RAG content to provide extra context for AI replies
5. Save changes.

## Usage

- In the agent panel, open any ticket.
- Click the **Generate AI Response** button in the ticket actions menu.
- The plugin will call the configured API and insert the suggested reply into the response box.

## Configuration Options

- **API URL**: The endpoint for your OpenAI-compatible API.
- **API Key**: The key used for authentication (stored securely).
- **Model Name**: The model to use (e.g., `gpt-4.1-mini`).
- **AI System Prompt**: (Optional) Custom instructions for the AI.
- **Response Template**: (Optional) Template for formatting the AI response.
- **RAG Content**: (Optional) Paste additional context to enrich AI responses.

## Security

**Data Protection & Redaction:**
- Only staff with reply permission can use the AI response feature.
- **Sensitive data is automatically redacted** before being sent to the API:
  - Passwords (e.g., `[password: xyz]` or `password=xyz`)
  - API keys and tokens
  - Credit card numbers
  - Social security / ID numbers (basic patterns)
  - Custom password patterns in square brackets: `[password: ...]`, `[PIN: ...]`, etc.

**Context Handling:**
- The plugin sends **up to the last 20 thread entries** (customer + agent messages) as context to the AI
- This provides conversation history for the AI to understand the ticket
- Large ticket histories are automatically capped to prevent API overhead

**API Key Storage:**
- API keys are stored in the osTicket database using the PasswordField encryption
- They are not visible in normal admin views and are only exposed when explicitly editing the configuration

## Example screenshots

### Plugin configuration

![Plugin configuration](https://github.com/user-attachments/assets/26e97839-4720-4eea-9af4-67d39fa3722e)

### Ticket view

![Ticket view](https://github.com/user-attachments/assets/89968c70-f0ff-4e04-a028-b1842d598cac)

## License

MIT License

Note: The original license and upstream copyright notice are intentionally retained.
