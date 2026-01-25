# Creating Custom Skills 🛠

Clawdbot is designed to be easily extensible. "Skills" are the primary way to add new capabilities to your assistant.

## What is a Skill?
A skill is a directory containing a `SKILL.md` file (which provides instructions and tool definitions to the LLM) and optionally some scripts or resources.

## Step-by-Step: Your First Skill

### 1. Create the Directory
Skills live in your workspace at `<workspace>/skills` (default: `~/clawd/skills`). Create a new folder for your skill:
```bash
mkdir -p ~/clawd/skills/hello-world
```

### 2. Define the `SKILL.md`
Create a `SKILL.md` file in that directory. This file uses YAML frontmatter for metadata and Markdown for instructions.

```markdown
---
name: hello_world
description: A simple skill that says hello.
---

# Hello World Skill
When the user asks for a greeting, use the `exec` tool to run `echo "Hello from your custom skill!"`.
```

### 3. Add Tools (Optional)
You can define custom tools in the frontmatter or instruct the agent to use existing system tools (like `exec` or `web`).

### 4. Refresh Clawdbot
If the skills watcher is enabled (default), changes are picked up automatically. Otherwise restart the gateway or start a new session to refresh the skills snapshot.

## Best Practices
- **Be Concise**: Instruct the model on *what* to do, not how to be an AI.
- **Safety First**: If your skill uses `exec`, ensure the prompts don't allow arbitrary command injection from untrusted user input.
- **Test Locally**: Use `clawdbot agent --message "use my new skill"` to test.

## Shared Skills
You can also browse and contribute skills to [ClawdHub](https://clawdhub.com).
